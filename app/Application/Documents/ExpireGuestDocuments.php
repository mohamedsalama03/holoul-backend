<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\ProjectIntake\Actions\IntakeDocuments;
use App\Modules\ProjectIntake\Models\GuestAccess;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Retire only abandoned guest draft attachments; submitted history is never eligible. */
final readonly class ExpireGuestDocuments
{
    public function __construct(private IntakeDocuments $attachments, private DocumentService $documents) {}

    public function handle(int $limit): int
    {
        $ids = ProjectRequest::query()->where('guest_origin', true)->whereNull('customer_id')->where('state', 'draft')
            ->where('created_at', '<=', DB::raw("clock_timestamp() - interval '24 hours'"))
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('intake_draft_documents')->whereColumn('request_id', 'project_requests.id'))
            ->orderBy('id')->limit(min(max($limit, 1), 100))->pluck('id');
        $expired = 0;
        foreach ($ids as $id) {
            $expired += DB::transaction(function () use ($id): int {
                $record = ProjectRequest::query()->whereKey($id)->where('guest_origin', true)->whereNull('customer_id')->where('state', 'draft')->lockForUpdate()->first();
                if ($record === null || ! GuestAccess::query()->whereKey($id)->where('expires_at', '<', DB::raw('clock_timestamp()'))->exists()) {
                    return 0;
                }
                $documentId = DB::table('intake_draft_documents')->where('request_id', $id)->value('document_id');
                if (! is_string($documentId)) {
                    return 0;
                }
                $correlation = (string) Str::uuid7();
                $owner = new DocumentOwner($record->id, null, null, null, $correlation);
                $this->documents->metadata($owner, $documentId, true);
                $this->attachments->detachExpiredUpload($record, $documentId, $correlation);
                $this->documents->requestDeletion($owner, $documentId);

                return 1;
            }, 2);
        }

        return $expired;
    }
}
