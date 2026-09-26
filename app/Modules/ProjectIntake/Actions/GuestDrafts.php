<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\GuestAccess;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\ProjectIntake\Models\RequestDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class GuestDrafts
{
    public function __construct(private RecordAuditEvent $audit) {}

    /** @return array{draft_id:string,capability:string,expires_at:string,etag:string} */
    public function create(string $sessionBinding, string $requestId): array
    {
        return DB::transaction(function () use ($sessionBinding, $requestId): array {
            $record = ProjectRequest::query()->forceCreate(['guest_origin' => true, 'state' => RequestState::Draft,
                'customer_id' => null, 'customer_user_id' => null, 'lock_version' => 1, 'latest_revision_number' => 0]);
            RequestDraft::query()->forceCreate(['request_id' => $record->id, 'customer_id' => null, 'is_open' => true, 'base_revision_number' => 0]);
            $capability = bin2hex(random_bytes(32));
            $access = GuestAccess::query()->forceCreate(['request_id' => $record->id, 'capability_hash' => hash('sha256', $capability),
                'session_hash' => hash('sha256', $sessionBinding), 'expires_at' => now()->addMinutes(30)]);
            $this->audit->handle('intake.guest_draft_created', 'project_request', $record->id, $requestId);

            return ['draft_id' => $record->id, 'capability' => $capability, 'expires_at' => $access->expires_at->toISOString() ?? throw new \LogicException,
                'etag' => VersionPrecondition::etag($record->id, 1)];
        });
    }

    /** @return array{ProjectRequest,GuestAccess} */
    public function lock(string $id, string $capability, string $sessionBinding, bool $editable = true): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Guest access requires a transaction.');
        }
        if (! Str::isUuid($id, 7) || preg_match('/\A[a-f0-9]{64}\z/D', $capability) !== 1 || $sessionBinding === '') {
            throw new HttpException(404);
        }
        $record = ProjectRequest::query()->whereKey($id)->where('guest_origin', true)->whereNull('customer_id')->lockForUpdate()->first();
        $access = GuestAccess::query()->whereKey($id)->where('expires_at', '>', DB::raw('clock_timestamp()'))->lockForUpdate()->first();
        if ($record === null || $access === null || ! hash_equals($access->capability_hash, hash('sha256', $capability))
            || ! hash_equals($access->session_hash, hash('sha256', $sessionBinding))) {
            throw new HttpException(404);
        }
        if ($editable && $record->state !== RequestState::Draft) {
            throw new HttpException(404);
        }

        return [$record, $access];
    }
}
