<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Customers\Contracts\CustomerContact;
use App\Modules\ProjectIntake\Data\ClaimRejected;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\GuestAccess;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\ProjectIntake\Models\RequestRevision;
use Illuminate\Support\Facades\DB;

final readonly class ClaimGuestRequest
{
    public function __construct(private IntakeStore $store, private RecordAuditEvent $audit) {}

    /** @return array{request_id:string,reference:?string,version:int} */
    public function handle(CustomerContact $contact, string $token, ?string $key, string $correlation): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Claim requires the outer identity/document transaction.');
        }
        SubmitGuestRequest::requireKey($key);
        if (! $contact->verifiedEmail || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new ClaimRejected;
        }
        $hash = hash('sha256', $token);
        $candidate = GuestAccess::query()->where('claim_token_hash', $hash)->first();
        if ($candidate === null) {
            throw new ClaimRejected;
        }
        $record = ProjectRequest::query()->whereKey($candidate->request_id)->where('guest_origin', true)->lockForUpdate()->first();
        $access = GuestAccess::query()->whereKey($candidate->request_id)->lockForUpdate()->firstOrFail();
        $revision = RequestRevision::query()->where('request_id', $candidate->request_id)->where('revision_number', 1)->first();
        if ($record === null || $revision === null || ! hash_equals($revision->email, $contact->email)) {
            throw new ClaimRejected;
        }
        $keyHash = hash('sha256', $key ?? '');
        $receipt = DB::table('intake_guest_claims')->where('request_id', $record->id)->first();
        if ($receipt !== null) {
            if ($receipt->customer_id !== $contact->customerId || $receipt->customer_user_id !== $contact->userId
                || $record->customer_id !== $contact->customerId || ! is_string($receipt->key_hash) || ! hash_equals($receipt->key_hash, $keyHash)) {
                throw new ClaimRejected;
            }

            if (! is_int($receipt->result_version)) {
                throw new \LogicException('Invalid claim receipt.');
            }

            return ['request_id' => $record->id, 'reference' => $record->reference, 'version' => $receipt->result_version];
        }
        if ($access->claim_expires_at === null || DB::scalar('SELECT ?::timestamptz <= clock_timestamp()', [$access->claim_expires_at->toISOString()]) !== false) {
            throw new ClaimRejected(true);
        }
        if ($record->customer_id !== null || ! in_array($record->state, [RequestState::Submitted, RequestState::UnderReview], true)
            || DB::table('intake_guest_claims')->where('customer_user_id', $contact->userId)->where('key_hash', $keyHash)->exists()) {
            throw new ClaimRejected;
        }
        DB::table('intake_guest_claims')->insert(['request_id' => $record->id, 'customer_id' => $contact->customerId,
            'customer_user_id' => $contact->userId, 'token_hash' => $hash, 'key_hash' => $keyHash, 'result_version' => $record->lock_version + 1]);
        $record->customer_id = $contact->customerId;
        $record->customer_user_id = $contact->userId;
        $this->store->changed($record);
        $this->store->draft($record)->forceFill(['customer_id' => $contact->customerId])->save();
        $this->audit->handle('intake.claim_completed', 'project_request', $record->id, $correlation, $contact->userId);

        return ['request_id' => $record->id, 'reference' => $record->reference, 'version' => $record->lock_version];
    }
}
