<?php

declare(strict_types=1);

namespace App\Modules\Contact\Actions;

use App\Infrastructure\Async\AsyncOperation;
use App\Infrastructure\Async\LostOperationLease;
use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Infrastructure\Async\PermanentOperationFailure;
use App\Infrastructure\Async\RetryableOperationFailure;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Contact\ContactMailNotAccepted;
use App\Modules\Contact\Contracts\ContactMailSender;
use App\Modules\Contact\Data\ContactMail;
use App\Modules\Contact\Models\ContactMessage;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class SendContactMail implements OperationHandler
{
    public function __construct(private ContactMailSender $sender, private RecordAuditEvent $audit) {}

    /** @return Closure():void */
    public function execute(OperationClaim $operation): Closure
    {
        $id = $operation->references['delivery_id'] ?? throw new PermanentOperationFailure('contact_delivery_missing');
        $mail = DB::transaction(function () use ($operation, $id): ?ContactMail {
            $this->fence($operation);
            $delivery = DB::table('contact_deliveries')->where('id', $id)->where('operation_id', $operation->id)->lockForUpdate()->first()
                ?? throw new PermanentOperationFailure('contact_delivery_missing');
            if ($delivery->state === 'sending') {
                $this->outcome($id, $operation, 'uncertain', 'uncertain');

                return null;
            }
            if ($delivery->state !== 'pending') {
                return null;
            }
            $message = ContactMessage::query()->whereKey($delivery->message_id)->firstOrFail();
            if ($message->status === 'redacted') {
                $this->outcome($id, $operation, 'suppressed', 'suppressed');

                return null;
            }
            $sandbox = Config::boolean('identity.mail_sandbox');
            $dashboard = Config::boolean('public-content.contact_dashboard_accepted');
            if (! $sandbox && (! $dashboard || ! Config::boolean('public-content.contact_production_mail_enabled'))) {
                $this->outcome($id, $operation, 'blocked', 'blocked');

                return null;
            }
            $recipient = Config::string('public-content.contact_recipient');
            $origin = rtrim(Config::string('app.url'), '/');
            $host = parse_url($origin, PHP_URL_HOST);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || strlen($recipient) > 254
                || ! is_string($host) || preg_match('/\A[a-z0-9.-]+\z/Di', $host) !== 1
                || parse_url($origin, PHP_URL_SCHEME) !== 'https' || parse_url($origin, PHP_URL_USER) !== null
                || parse_url($origin, PHP_URL_PASS) !== null || parse_url($origin, PHP_URL_PATH) !== null
                || parse_url($origin, PHP_URL_QUERY) !== null || parse_url($origin, PHP_URL_FRAGMENT) !== null) {
                $this->outcome($id, $operation, 'blocked', 'blocked');

                return null;
            }
            DB::table('contact_deliveries')->where('id', $id)->update(['state' => 'sending', 'send_fence' => $operation->fence]);
            $this->attempt($id, $operation, 'started');

            return new ContactMail($recipient, $message->reference, $dashboard ? $origin.'/admin/contact-messages/'.$message->id : null, $id.'@'.$host);
        });
        if ($mail === null) {
            $state = DB::table('contact_deliveries')->where('id', $id)->value('state');
            if (in_array($state, ['uncertain', 'blocked'], true)) {
                throw new PermanentOperationFailure($state === 'uncertain' ? 'contact_mail_uncertain' : 'contact_mail_blocked');
            }

            return static function (): void {};
        }
        try {
            $this->sender->send($mail);
        } catch (ContactMailNotAccepted $error) {
            DB::transaction(function () use ($id, $operation, $error): void {
                $this->fence($operation);
                $this->outcome($id, $operation, $error->retryable ? 'pending' : 'blocked', 'not_accepted');
            });
            if ($error->retryable) {
                throw new RetryableOperationFailure('contact_mail_not_accepted');
            }
            throw new PermanentOperationFailure('contact_mail_blocked');
        } catch (Throwable) {
            DB::transaction(function () use ($id, $operation): void {
                $this->fence($operation);
                $this->outcome($id, $operation, 'uncertain', 'uncertain');
            });
            throw new PermanentOperationFailure('contact_mail_uncertain');
        }

        return function () use ($id, $operation): void {
            $updated = DB::table('contact_deliveries')->where('id', $id)->where('state', 'sending')->where('send_fence', $operation->fence)
                ->update(['state' => 'sent', 'sent_at' => DB::raw('clock_timestamp()')]);
            if ($updated !== 1) {
                throw new PermanentOperationFailure('contact_mail_state_conflict');
            }
            $this->attempt($id, $operation, 'sent');
            $this->audit->handle('contact.mail_sent', 'contact.delivery', $id, $operation->requestId ?? $operation->id);
        };
    }

    private function fence(OperationClaim $operation): void
    {
        if (AsyncOperation::query()->whereKey($operation->id)->where('state', 'running')->where('fence', $operation->fence)
            ->where('lease_expires_at', '>', DB::raw('clock_timestamp()'))->lockForUpdate()->first() === null) {
            throw new LostOperationLease;
        }
    }

    private function outcome(string $id, OperationClaim $operation, string $state, string $outcome): void
    {
        DB::table('contact_deliveries')->where('id', $id)->update(['state' => $state]);
        $this->attempt($id, $operation, $outcome);
        $this->audit->handle('contact.mail_'.$outcome, 'contact.delivery', $id, $operation->requestId ?? $operation->id);
    }

    private function attempt(string $id, OperationClaim $operation, string $outcome): void
    {
        DB::table('contact_delivery_attempts')->insert(['id' => (string) Str::uuid7(), 'delivery_id' => $id, 'fence' => $operation->fence, 'outcome' => $outcome]);
    }
}
