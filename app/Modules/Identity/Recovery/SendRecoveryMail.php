<?php

declare(strict_types=1);

namespace App\Modules\Identity\Recovery;

use App\Infrastructure\Async\AsyncOperation;
use App\Infrastructure\Async\LostOperationLease;
use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Infrastructure\Async\PermanentOperationFailure;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Data\SafeAuditMetadata;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Recovery\Contracts\MailTransport;
use App\Modules\Identity\Recovery\Data\RecoveryMailPayload;
use App\Modules\Identity\Recovery\Models\RecoveryMail;
use App\Modules\Identity\Recovery\Models\RecoveryToken;
use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SendRecoveryMail implements OperationHandler
{
    public function __construct(
        private MailTransport $transport,
        private Encrypter $encrypter,
        private RecoveryMailTemplates $templates,
        private RecordAuditEvent $audit,
    ) {}

    /** @return Closure(): void */
    public function execute(OperationClaim $operation): Closure
    {
        $mailId = $operation->references['mail_id'] ?? throw new PermanentOperationFailure('mail_record_missing');
        $prepared = $this->prepare($operation, $mailId);
        $mail = $prepared['mail'];
        $payload = $prepared['payload'];

        if ($mail->state === 'uncertain') {
            throw new PermanentOperationFailure('mail_delivery_uncertain');
        }

        if ($mail->state === 'discarded' && $mail->failure_code === 'mail_payload_invalid') {
            throw new PermanentOperationFailure('mail_payload_invalid');
        }

        if ($payload === null) {
            return static function (): void {};
        }

        try {
            $this->transport->send($this->templates->render($mail->id, $payload));
        } catch (Throwable) {
            DB::transaction(function () use ($operation, $mail): void {
                $this->lockFence($operation);
                $this->markUncertain($operation, $mail);
            });

            throw new PermanentOperationFailure('mail_delivery_uncertain');
        }

        return function () use ($operation, $mail): void {
            $updated = RecoveryMail::query()->whereKey($mail->id)->where('operation_id', $operation->id)
                ->where('state', 'sending')->where('send_fence', $operation->fence)
                ->update([
                    'state' => 'sent',
                    'encrypted_payload' => null,
                    'sent_at' => DB::raw('clock_timestamp()'),
                    'failure_code' => null,
                ]);

            if ($updated !== 1) {
                throw new PermanentOperationFailure('mail_state_conflict');
            }

            // This writer and the async operation's success share the fenced
            // transaction. No plaintext payload or recipient enters the audit.
            $this->audit->handle('identity.recovery.mail_sent', 'identity.recovery_mail', $mail->id,
                $operation->requestId ?? $operation->id, metadata: new SafeAuditMetadata([
                    'operation_id' => $operation->id,
                    'outcome' => 'succeeded',
                    'attempt_number' => $operation->attempt,
                ]));
        };
    }

    /** @return array{mail: RecoveryMail, payload: RecoveryMailPayload|null} */
    private function prepare(OperationClaim $operation, string $mailId): array
    {
        $candidate = RecoveryMail::query()->whereKey($mailId)->where('operation_id', $operation->id)->first()
            ?? throw new PermanentOperationFailure('mail_record_missing');

        return DB::transaction(function () use ($operation, $candidate): array {
            $this->lockFence($operation);
            $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $token = RecoveryToken::query()->whereKey($candidate->recovery_token_id)->lockForUpdate()->firstOrFail();
            $mail = RecoveryMail::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if ($mail->state === 'sending') {
                // A previous worker may have died after SMTP acceptance. A
                // second attempt must not blindly repeat an external effect.
                $this->markUncertain($operation, $mail);

                return ['mail' => $mail->refresh(), 'payload' => null];
            }

            if ($mail->state !== 'pending') {
                return ['mail' => $mail, 'payload' => null];
            }

            $usable = RecoveryToken::query()->whereKey($token->id)
                ->whereNull('consumed_at')->whereNull('revoked_at')
                ->where('expires_at', '>', DB::raw('clock_timestamp()'))->exists();

            if (! $usable || ! $user->enabled || $token->auth_version !== $user->auth_version
                || ! hash_equals($token->email_hash, hash('sha256', $user->email))) {
                return ['mail' => $this->discard($mail, 'token_unavailable'), 'payload' => null];
            }

            try {
                $payload = RecoveryMailPayload::decrypt($mail->encrypted_payload ?? '', $this->encrypter);

                if ($payload->purpose !== $token->purpose || $payload->recipient !== $user->email
                    || ! hash_equals($token->token_hash, hash('sha256', $payload->token))) {
                    throw new PermanentOperationFailure('mail_payload_invalid');
                }
            } catch (Throwable) {
                return ['mail' => $this->discard($mail, 'mail_payload_invalid'), 'payload' => null];
            }

            RecoveryMail::query()->whereKey($mail->id)->update([
                'state' => 'sending',
                'send_fence' => $operation->fence,
                'send_started_at' => DB::raw('clock_timestamp()'),
            ]);

            return ['mail' => $mail->refresh(), 'payload' => $payload];
        });
    }

    private function lockFence(OperationClaim $operation): void
    {
        $valid = AsyncOperation::query()->whereKey($operation->id)->where('state', 'running')
            ->where('fence', $operation->fence)->where('lease_expires_at', '>', DB::raw('clock_timestamp()'))
            ->lockForUpdate()->first();

        if ($valid === null) {
            throw new LostOperationLease;
        }
    }

    private function markUncertain(OperationClaim $operation, RecoveryMail $mail): void
    {
        $updated = RecoveryMail::query()->whereKey($mail->id)->where('state', 'sending')
            ->update(['state' => 'uncertain', 'encrypted_payload' => null, 'failure_code' => 'mail_delivery_uncertain']);

        if ($updated === 1) {
            $this->audit->handle('identity.recovery.mail_uncertain', 'identity.recovery_mail', $mail->id,
                $operation->requestId ?? $operation->id, metadata: new SafeAuditMetadata([
                    'operation_id' => $operation->id,
                    'outcome' => 'failed',
                ]));
        }
    }

    private function discard(RecoveryMail $mail, string $code): RecoveryMail
    {
        RecoveryMail::query()->whereKey($mail->id)->where('state', 'pending')
            ->update(['state' => 'discarded', 'encrypted_payload' => null, 'failure_code' => $code]);

        return $mail->refresh();
    }
}
