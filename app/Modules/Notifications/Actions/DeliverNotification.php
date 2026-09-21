<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Infrastructure\Async\AsyncOperation;
use App\Infrastructure\Async\LostOperationLease;
use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Infrastructure\Async\PermanentOperationFailure;
use App\Infrastructure\Async\RetryableOperationFailure;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Contracts\NotificationRecipientReader;
use App\Modules\Notifications\Contracts\EmailProvider;
use App\Modules\Notifications\Data\EmailMessage;
use App\Modules\Notifications\EmailNotAccepted;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class DeliverNotification implements OperationHandler
{
    public function __construct(private EmailProvider $provider, private NotificationRecipientReader $recipients, private RecordAuditEvent $audit) {}

    public function execute(OperationClaim $operation): Closure
    {
        $prepared = $this->prepare($operation);
        $delivery = $prepared['delivery'];
        if (in_array($delivery->state, ['uncertain', 'failed'], true)) {
            throw new PermanentOperationFailure('notification_delivery_terminal');
        }
        $message = $prepared['message'];
        $attempt = $prepared['attempt'];
        if ($message === null || $attempt === null) {
            return static function (): void {};
        }
        try {
            $reference = $this->provider->send($message);
            if ($reference !== null && preg_match('/\A[A-Za-z0-9_.:@\/-]{1,160}\z/D', $reference) !== 1) {
                $reference = null;
            }
        } catch (EmailNotAccepted $error) {
            $retry = $error->retryable && $operation->attempt < 5;
            $code = $error->retryable ? 'provider_unavailable' : 'provider_rejected';
            DB::transaction(function () use ($operation, $delivery, $attempt, $retry, $code): void {
                $this->fence($operation);
                $this->outcome($delivery, $attempt, $retry ? 'pending' : 'failed', 'failed', $code);
            });
            if ($retry) {
                throw new RetryableOperationFailure('provider_unavailable', 30);
            }
            throw new PermanentOperationFailure($error->retryable ? 'attempts_exhausted' : 'provider_rejected');
        } catch (Throwable) {
            DB::transaction(function () use ($operation, $delivery, $attempt): void {
                $this->fence($operation);
                $this->outcome($delivery, $attempt, 'uncertain', 'uncertain', 'acknowledgement_uncertain');
            });
            throw new PermanentOperationFailure('acknowledgement_uncertain');
        }

        return function () use ($operation, $delivery, $attempt, $reference): void {
            $current = NotificationDelivery::query()->whereKey($delivery->id)->where('state', 'processing')->where('send_fence', $operation->fence)->lockForUpdate()->first();
            if ($current === null) {
                throw new LostOperationLease;
            }
            $this->outcome($current, $attempt, 'accepted', 'accepted', null, $reference);
            $this->audit->handle('notifications.email_accepted', 'notifications.delivery', $current->id, $operation->requestId ?? $operation->id);
        };
    }

    /** @return array{delivery:NotificationDelivery,message:?EmailMessage,attempt:?string} */
    private function prepare(OperationClaim $operation): array
    {
        $id = $operation->references['delivery_id'] ?? throw new PermanentOperationFailure('notification_missing');
        $candidate = NotificationDelivery::query()->whereKey($id)->where('operation_id', $operation->id)->first() ?? throw new PermanentOperationFailure('notification_missing');
        $notice = Notification::query()->findOrFail($candidate->notification_id);

        return DB::transaction(function () use ($operation, $candidate, $notice): array {
            $this->fence($operation);
            $recipient = $this->recipients->current($notice->recipient_id, true);
            $delivery = NotificationDelivery::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($delivery->operation_id !== $operation->id) {
                throw new LostOperationLease;
            }
            if ($delivery->state === 'processing') {
                $this->uncertain($delivery);
            }
            if ($delivery->state !== 'pending') {
                return ['delivery' => $delivery, 'message' => null, 'attempt' => null];
            }
            $preference = DB::table('notification_preferences')->where('user_id', $notice->recipient_id)->value('workflow_email');
            $failure = $recipient === null || ! $recipient->enabled || ! $recipient->verified ? 'recipient_unavailable'
                : (! Config::boolean('notifications.email_enabled') ? 'email_disabled' : ($preference === false ? 'preference_disabled' : null));
            if ($failure !== null) {
                $delivery->forceFill(['state' => 'suppressed', 'failure_code' => $failure, 'completed_at' => DB::raw('clock_timestamp()'), 'lock_version' => $delivery->lock_version + 1])->save();

                return ['delivery' => $delivery, 'message' => null, 'attempt' => null];
            }
            $attempt = (string) Str::uuid7();
            DB::table('notification_delivery_attempts')->insert(['id' => $attempt, 'delivery_id' => $delivery->id, 'operation_id' => $operation->id,
                'generation' => $delivery->generation, 'attempt_number' => $operation->attempt, 'fence' => $operation->fence, 'state' => 'processing']);
            $delivery->forceFill(['state' => 'processing', 'send_fence' => $operation->fence, 'processing_at' => DB::raw('clock_timestamp()'),
                'failure_code' => null, 'completed_at' => null, 'lock_version' => $delivery->lock_version + 1])->save();

            return ['delivery' => $delivery, 'message' => new EmailMessage($recipient->email, $notice->title, $notice->message,
                $notice->id.'@notifications.holoul'), 'attempt' => $attempt];
        });
    }

    public function uncertain(NotificationDelivery $delivery): void
    {
        DB::table('notification_delivery_attempts')->where('delivery_id', $delivery->id)->where('state', 'processing')
            ->update(['state' => 'uncertain', 'failure_code' => 'acknowledgement_uncertain', 'completed_at' => DB::raw('clock_timestamp()')]);
        $delivery->forceFill(['state' => 'uncertain', 'failure_code' => 'acknowledgement_uncertain',
            'completed_at' => DB::raw('clock_timestamp()'), 'lock_version' => $delivery->lock_version + 1])->save();
    }

    private function outcome(NotificationDelivery $delivery, string $attempt, string $state, string $attemptState, ?string $code, ?string $reference = null): void
    {
        DB::table('notification_delivery_attempts')->where('id', $attempt)->where('state', 'processing')->update([
            'state' => $attemptState, 'failure_code' => $code, 'provider_reference' => $reference, 'completed_at' => DB::raw('clock_timestamp()')]);
        $delivery->forceFill(['state' => $state, 'failure_code' => $code, 'completed_at' => $state === 'pending' ? null : DB::raw('clock_timestamp()'),
            'lock_version' => $delivery->lock_version + 1])->save();
    }

    private function fence(OperationClaim $operation): void
    {
        if (! AsyncOperation::query()->whereKey($operation->id)->where('state', 'running')->where('fence', $operation->fence)
            ->where('lease_expires_at', '>', DB::raw('clock_timestamp()'))->lockForUpdate()->exists()) {
            throw new LostOperationLease;
        }
    }
}
