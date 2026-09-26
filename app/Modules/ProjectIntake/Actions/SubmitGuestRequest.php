<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\ProjectIntake\Data\DraftValues;
use App\Modules\ProjectIntake\Data\SubmissionContact;
use App\Modules\ProjectIntake\Models\GuestAccess;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class SubmitGuestRequest
{
    public function __construct(private GuestDrafts $guests, private IntakeStore $store, private CommitSubmission $commit, private RecordAuditEvent $audit) {}

    /** @param array<string,mixed> $input
     * @return array{reference:?string,confirmation:string,next_step:string,claim_token:string,claim_expires_at:?string}
     */
    public function handle(string $id, string $capability, string $session, ?string $etag, ?string $key, array $input, string $requestId): array
    {
        self::requireKey($key);

        return DB::transaction(function () use ($id, $capability, $session, $etag, $key, $input, $requestId): array {
            [$record, $access] = $this->guests->lock($id, $capability, $session, false);
            $values = DraftValues::patch(null, $input);
            $values->requireComplete();
            $contact = SubmissionContact::guest($this->text($input, 'full_name'), $this->text($input, 'email'), $this->text($input, 'phone'));
            $hash = hash('sha256', json_encode([$id, $etag, $values->columns(), $contact], JSON_THROW_ON_ERROR));
            $keyHash = hash('sha256', $key ?? '');
            // One browser cannot replay its final-submission key against another draft.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['intake.guest-submit:'.$access->session_hash.':'.$keyHash]);
            if (GuestAccess::query()->where('session_hash', $access->session_hash)
                ->where('submission_key_hash', $keyHash)->where('request_id', '<>', $id)->exists()) {
                throw new HttpException(409);
            }
            $token = hash_hmac('sha256', 'g1.claim:'.$id.':'.$capability, Config::string('app.key'));
            if ($access->submission_key_hash !== null) {
                if (! hash_equals($access->submission_key_hash, $keyHash) || ! hash_equals($access->submission_input_hash ?? '', $hash)) {
                    throw new HttpException(409);
                }
            } else {
                VersionPrecondition::require($etag, $id, $record->lock_version);
                $draft = $this->store->draft($record);
                if (! $draft->is_open || $record->latest_revision_number !== 0) {
                    throw new HttpException(409);
                }
                $this->commit->handle($record, $draft, $values, $contact, null, $requestId);
                $access->forceFill(['submission_key_hash' => $keyHash, 'submission_input_hash' => $hash,
                    'result_version' => $record->lock_version, 'claim_token_hash' => hash('sha256', $token),
                    'claim_expires_at' => now()->addHours(72)])->save();
                $this->audit->handle('intake.guest_submitted', 'project_request', $id, $requestId);
                $this->audit->handle('intake.claim_issued', 'project_request', $id, $requestId);
            }

            return ['reference' => $record->reference, 'confirmation' => 'submitted', 'next_step' => 'sign_in_verify_email_and_claim',
                'claim_token' => $token, 'claim_expires_at' => $access->claim_expires_at?->toISOString()];
        });
    }

    public static function requireKey(?string $key): void
    {
        if ($key === null || preg_match('/\A[A-Za-z0-9_.:-]{16,128}\z/D', $key) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'A bounded unique key is required.']);
        }
    }

    /** @param array<string,mixed> $input */
    private function text(array $input, string $key): string
    {
        return is_string($input[$key] ?? null) ? $input[$key] : throw ValidationException::withMessages([$key => 'Required.']);
    }
}
