<?php

declare(strict_types=1);

namespace App\Modules\Contact\Actions;

use App\Infrastructure\Async\OperationRecorder;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Contact\Data\ContactSubmission;
use App\Modules\Contact\Models\ContactMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ReceiveContact
{
    public function __construct(private OperationRecorder $operations, private RecordAuditEvent $audit) {}

    /** @return array{id:string,reference:string,status:string,received_at:string} */
    public function handle(ContactSubmission $submission, string $key, string $requestId): array
    {
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A UUID key is required.']);
        }
        $secret = Config::string('app.key');
        $keyHash = hash_hmac('sha256', 'contact.submit:'.strtolower($key), $secret);
        $inputHash = hash_hmac('sha256', json_encode($submission->fields(), JSON_THROW_ON_ERROR), $secret);

        return DB::transaction(function () use ($submission, $keyHash, $inputHash, $requestId): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['contact.submit:'.$keyHash]);
            $clock = DB::selectOne('SELECT clock_timestamp() AS instant');
            if (! $clock instanceof \stdClass || ! is_string($clock->instant)) {
                throw new LogicException('Database clock unavailable.');
            }
            $now = CarbonImmutable::parse($clock->instant);
            $stored = DB::table('contact_submission_keys')->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($stored !== null && is_string($stored->expires_at) && CarbonImmutable::parse($stored->expires_at)->greaterThan($now)) {
                if (! is_string($stored->input_hash) || ! hash_equals($stored->input_hash, $inputHash)) {
                    throw new HttpException(409);
                }

                return $this->receipt(ContactMessage::query()->whereKey($stored->message_id)->firstOrFail());
            }
            $sequence = DB::selectOne("SELECT nextval('contact_reference_sequence') AS number");
            if (! $sequence instanceof \stdClass || ! is_int($sequence->number)) {
                throw new LogicException('Contact sequence unavailable.');
            }
            $message = new ContactMessage;
            $message->forceFill([...$submission->fields(), 'id' => (string) Str::uuid7(),
                'reference' => 'CNT-'.$now->format('Y').'-'.str_pad((string) $sequence->number, 5, '0', STR_PAD_LEFT),
                'status' => 'received', 'lock_version' => 1, 'received_at' => $now, 'updated_at' => $now]);
            $message->save();
            DB::table('contact_submission_keys')->updateOrInsert(['key_hash' => $keyHash], [
                'input_hash' => $inputHash, 'message_id' => $message->id, 'expires_at' => $now->addHours(72)]);
            $deliveryId = (string) Str::uuid7();
            DB::table('contact_deliveries')->insert(['id' => $deliveryId, 'message_id' => $message->id]);
            $operation = $this->operations->record('contact.email', $deliveryId, ['delivery_id' => $deliveryId], $requestId);
            DB::table('contact_deliveries')->where('id', $deliveryId)->update(['operation_id' => $operation->id]);
            $this->audit->handle('contact.received', 'contact.message', $message->id, $requestId);

            return $this->receipt($message);
        }, 2);
    }

    /** @return array{id:string,reference:string,status:string,received_at:string} */
    private function receipt(ContactMessage $message): array
    {
        return ['id' => $message->id, 'reference' => $message->reference, 'status' => 'received', 'received_at' => $message->received_at->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }
}
