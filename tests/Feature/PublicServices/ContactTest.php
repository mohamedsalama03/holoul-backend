<?php

declare(strict_types=1);

namespace Tests\Feature\PublicServices;

use App\Infrastructure\Async\OperationRunner;
use App\Modules\Contact\Actions\ReceiveContact;
use App\Modules\Contact\Actions\ReconcileContactDelivery;
use App\Modules\Contact\ContactMailNotAccepted;
use App\Modules\Contact\Contracts\ContactMailSender;
use App\Modules\Contact\Data\ContactMail;
use App\Modules\Contact\Data\ContactSubmission;
use App\Modules\Contact\Models\ContactMessage;
use App\Modules\Identity\Authorization\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommercialDatabase;
use Tests\Support\PublicContentHttp;
use Tests\TestCase;

final class ContactTest extends TestCase
{
    use CommercialDatabase, PublicContentHttp;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Config::set('database.redis.options.prefix', 'contact_test_'.Str::uuid7().'_');
        app('redis')->purge('cache');
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        $this->initializeBrowser();
    }

    public function test_receipt_is_stable_encrypted_and_independent_of_session_and_admin_status(): void
    {
        $input = $this->input();
        $key = (string) Str::uuid7();
        $one = $this->browser('POST', '/api/v1/public/contact-messages', $input, ['Idempotency-Key' => $key])->assertCreated();
        self::assertSame([], $one->headers->getCookies());
        $this->assertDatabaseCount('contact_messages', 1);
        $this->assertDatabaseCount('contact_deliveries', 1);
        $this->assertDatabaseCount('async_operations', 1);
        $this->assertDatabaseCount('users', 0);
        $stored = json_encode([DB::table('contact_messages')->get(), DB::table('async_operations')->get(), DB::table('audit_events')->get()], JSON_THROW_ON_ERROR);
        foreach ([$input['email'], $input['message'], $input['full_name'], $input['phone']] as $personal) {
            self::assertStringNotContainsString($personal, $stored);
        }
        $this->publicContentOperator(Role::Administrator);
        $this->browser('GET', '/api/v1/admin/contact-messages?limit=1&status=received')->assertOk()->assertJsonCount(1, 'data');
        $id = $one->json('data.id');
        $read = $this->browser('GET', '/api/v1/admin/contact-messages/'.$id)->assertOk();
        self::assertSame([], $read->headers->getCookies());
        $this->browser('PATCH', '/api/v1/admin/contact-messages/'.$id, ['status' => 'resolved'], ['If-Match' => $read->headers->get('ETag')])->assertOk();
        $two = $this->browser('POST', '/api/v1/public/contact-messages', $input, ['Idempotency-Key' => $key])->assertCreated();
        self::assertSame($one->json(), $two->json());
        self::assertSame([], $two->headers->getCookies());
        $this->browser('POST', '/api/v1/public/contact-messages', [...$input, 'message' => 'A different message body'], ['Idempotency-Key' => $key])->assertConflict();
        $this->assertDatabaseCount('contact_messages', 1);
    }

    public function test_expired_key_starts_a_new_atomic_operation_without_waiting_for_cleanup(): void
    {
        $input = new ContactSubmission('Synthetic Contact', 'expiry@example.test', '+12025550123', null, 'A synthetic expiry check');
        $key = (string) Str::uuid7();
        $one = app(ReceiveContact::class)->handle($input, $key, (string) Str::uuid7());
        DB::table('contact_submission_keys')->update(['expires_at' => DB::raw('clock_timestamp()')]);
        $two = app(ReceiveContact::class)->handle($input, $key, (string) Str::uuid7());
        $three = app(ReceiveContact::class)->handle($input, $key, (string) Str::uuid7());
        self::assertNotSame($one['id'], $two['id']);
        self::assertSame($two, $three);
        $this->assertDatabaseCount('contact_messages', 2);
        $this->assertDatabaseCount('contact_deliveries', 2);
        $this->assertDatabaseCount('contact_submission_keys', 1);
    }

    public function test_csrf_origin_fields_limits_and_invalid_phone_cannot_create_a_receipt(): void
    {
        $url = '/api/v1/public/contact-messages';
        $input = $this->input();
        $key = ['Idempotency-Key' => (string) Str::uuid7()];
        $this->browser('POST', $url, $input, $key, false)->assertForbidden();
        $this->browser('POST', $url, $input, [...$key, 'Origin' => 'https://evil.example.test'])->assertForbidden();
        $this->browser('POST', $url, [...$input, 'recipient' => 'evil@example.test'], $key)->assertUnprocessable();
        $this->browser('POST', $url, [...$input, 'phone' => '1234'], $key)->assertUnprocessable()->assertJsonValidationErrors('phone', 'error.fields');
        $this->browser('POST', $url, $input)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key', 'error.fields');
        $this->browser('POST', $url, [...$input, 'message' => str_repeat('x', 33000)], $key)->assertStatus(413);
        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_unicode_and_phone_normalization_and_no_actor_or_content_in_receipt(): void
    {
        $reply = $this->browser('POST', '/api/v1/public/contact-messages', [...$this->input(),
            'full_name' => '  محمد  علي  ', 'email' => ' CONTACT@example.test ', 'phone' => '+1 202 555 0123',
            'company' => ' ', 'message' => "رسالة تجريبية\r\nلاختبار النموذج فقط."], ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        self::assertSame(['id', 'reference', 'status', 'received_at'], array_keys($reply->json('data')));
        $record = ContactMessage::query()->sole();
        self::assertSame('محمد علي', $record->full_name);
        self::assertSame('contact@example.test', $record->email);
        self::assertSame('+12025550123', $record->phone);
        self::assertNull($record->company);
        self::assertStringNotContainsString("\r", $record->message);
    }

    public function test_receipt_and_work_roll_back_together_when_audit_fails(): void
    {
        DB::unprepared("CREATE FUNCTION test_reject_contact_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.event_type='contact.received' THEN RAISE EXCEPTION 'test audit failure'; END IF; RETURN NEW; END; $$; CREATE TRIGGER test_contact_audit BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION test_reject_contact_audit();");
        try {
            app(ReceiveContact::class)->handle(new ContactSubmission('Synthetic', 'rollback@example.test', '+12025550123', null, 'Rollback test message'), (string) Str::uuid7(), (string) Str::uuid7());
            self::fail('Expected rollback');
        } catch (QueryException) {
            $this->assertDatabaseCount('contact_messages', 0);
            $this->assertDatabaseCount('contact_deliveries', 0);
            $this->assertDatabaseCount('async_operations', 0);
            $this->assertDatabaseCount('contact_submission_keys', 0);
        } finally {
            DB::unprepared('DROP TRIGGER test_contact_audit ON audit_events; DROP FUNCTION test_reject_contact_audit();');
        }
    }

    public function test_redaction_requires_recent_password_and_preserves_receipt_with_no_automatic_policy(): void
    {
        $input = $this->input();
        $key = (string) Str::uuid7();
        $receipt = $this->browser('POST', '/api/v1/public/contact-messages', $input, ['Idempotency-Key' => $key])->assertCreated()->json();
        $this->publicContentOperator();
        $url = '/api/v1/admin/contact-messages/'.$receipt['data']['id'];
        $etag = $this->browser('GET', $url)->assertOk()->headers->get('ETag');
        $this->browser('DELETE', $url)->assertStatus(428);
        $this->browser('DELETE', $url, [], ['If-Match' => '"wrong"'])->assertStatus(412);
        $this->travel(6)->minutes();
        $this->browser('DELETE', $url, [], ['If-Match' => $etag])->assertForbidden();
        $this->browser('POST', '/api/v1/auth/password/confirm', ['password' => 'Correct-Horse-72-River'])->assertOk();
        $redacted = $this->browser('DELETE', $url, [], ['If-Match' => $etag])->assertOk()->assertJsonPath('data.status', 'redacted');
        foreach (['full_name', 'email', 'phone', 'company', 'message'] as $field) {
            $redacted->assertJsonPath('data.'.$field, null);
        }
        $this->travelBack();
        self::assertSame($receipt, $this->browser('POST', '/api/v1/public/contact-messages', $input, ['Idempotency-Key' => $key])->assertCreated()->json());
        $this->assertDatabaseHas('audit_events', ['event_type' => 'contact.redacted', 'subject_id' => $receipt['data']['id']]);
        self::assertFalse(config('public-content.contact_automatic_redaction'));
        $operation = DB::table('contact_deliveries')->sole()->operation_id;
        app(OperationRunner::class)->run($operation);
        $this->assertDatabaseHas('contact_deliveries', ['state' => 'suppressed']);
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_other_roles_cannot_read_manage_or_redact_contact(Role $role): void
    {
        $this->publicContentOperator($role);
        $caps = $this->browser('GET', '/api/v1/admin/public-content/capabilities')->assertOk();
        foreach (['portfolio_read', 'portfolio_manage', 'portfolio_publish', 'contact_read', 'contact_manage', 'contact_redact'] as $permission) {
            $caps->assertJsonPath('data.'.$permission, false);
        }
        $this->browser('GET', '/api/v1/admin/contact-messages')->assertForbidden();
        $this->browser('GET', '/api/v1/admin/contact-messages/'.Str::uuid7())->assertForbidden();
        $this->browser('DELETE', '/api/v1/admin/contact-messages/'.Str::uuid7())->assertForbidden();
    }

    public static function unauthorizedRoles(): array
    {
        return array_map(static fn (Role $role): array => [$role], array_values(array_filter(Role::cases(), static fn (Role $role): bool => ! in_array($role, [Role::SuperAdmin, Role::Administrator, Role::Customer], true))));
    }

    #[DataProvider('mailOutcomes')]
    public function test_mail_is_identifier_only_and_distinguishes_rejection_from_ambiguous_delivery(string $outcome, string $state): void
    {
        $transport = new class($outcome) implements ContactMailSender
        {
            public int $calls = 0;

            public ?ContactMail $mail = null;

            public function __construct(private string $outcome) {}

            public function send(ContactMail $mail): void
            {
                $this->calls++;
                $this->mail = $mail;
                if ($this->outcome === 'rejected') {
                    throw new ContactMailNotAccepted(true);
                }
                if ($this->outcome === 'uncertain') {
                    throw new \RuntimeException('Ambiguous SMTP result');
                }
            }
        };
        app()->instance(ContactMailSender::class, $transport);
        $this->browser('POST', '/api/v1/public/contact-messages', $this->input(), ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $operation = DB::table('contact_deliveries')->sole()->operation_id;
        app(OperationRunner::class)->run($operation);
        $this->assertDatabaseHas('contact_deliveries', ['state' => $state]);
        self::assertSame(1, $transport->calls);
        self::assertSame('info@holoul.ly', $transport->mail->recipient);
        self::assertNull($transport->mail->link);
        app(OperationRunner::class)->run($operation);
        self::assertSame(1, $transport->calls);
        self::assertGreaterThanOrEqual(2, DB::table('contact_delivery_attempts')->count());
    }

    public static function mailOutcomes(): array
    {
        return [['accepted', 'sent'], ['rejected', 'pending'], ['uncertain', 'uncertain']];
    }

    public function test_contact_rate_limit_does_not_create_an_extra_receipt(): void
    {
        $input = $this->input();
        for ($i = 0; $i < 3; $i++) {
            $this->browser('POST', '/api/v1/public/contact-messages', $input, ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        }
        $this->browser('POST', '/api/v1/public/contact-messages', $input, ['Idempotency-Key' => (string) Str::uuid7()])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('contact_messages', 3);
    }

    public function test_known_rejection_can_retry_but_an_expired_final_send_is_uncertain_and_never_repeated(): void
    {
        $sender = new class implements ContactMailSender
        {
            public int $calls = 0;

            public bool $expire = false;

            public function send(ContactMail $mail): void
            {
                $this->calls++;
                if ($this->expire) {
                    DB::table('async_operations')->where('kind', 'contact.email')->where('state', 'running')->update(['lease_expires_at' => DB::raw("clock_timestamp()-interval '1 second'"), 'attempts' => DB::raw('max_attempts')]);

                    return;
                }
                if ($this->calls === 1) {
                    throw new ContactMailNotAccepted(true);
                }
            }
        };
        app()->instance(ContactMailSender::class, $sender);
        $this->browser('POST', '/api/v1/public/contact-messages', $this->input(), ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $operation = DB::table('contact_deliveries')->sole()->operation_id;
        $runner = app(OperationRunner::class);
        $runner->run($operation);
        $this->assertDatabaseHas('contact_deliveries', ['operation_id' => $operation, 'state' => 'pending']);
        DB::table('async_operations')->where('id', $operation)->update(['next_attempt_at' => now()->subMinute()]);
        $runner->run($operation);
        self::assertSame(2, $sender->calls);
        $this->assertDatabaseHas('contact_deliveries', ['operation_id' => $operation, 'state' => 'sent']);
        $receipt = app(ReceiveContact::class)->handle(new ContactSubmission('Lease test', 'lease@example.test', '+12025550123', null, 'A private lease test message'), (string) Str::uuid7(), (string) Str::uuid7());
        $uncertain = DB::table('contact_deliveries')->where('message_id', $receipt['id'])->sole()->operation_id;
        $sender->expire = true;
        $runner->run($uncertain);
        $this->assertDatabaseHas('contact_deliveries', ['operation_id' => $uncertain, 'state' => 'sending']);
        $runner->run($uncertain);
        self::assertSame(1, app(ReconcileContactDelivery::class)->handle(50));
        $this->assertDatabaseHas('contact_deliveries', ['operation_id' => $uncertain, 'state' => 'uncertain']);
        $runner->run($uncertain);
        self::assertSame(3, $sender->calls);
        self::assertSame(0, app(ReconcileContactDelivery::class)->handle(50));
    }

    public function test_production_notification_link_is_blocked_until_dashboard_and_mail_are_explicitly_enabled(): void
    {
        $sender = new class implements ContactMailSender
        {
            public array $messages = [];

            public function send(ContactMail $mail): void
            {
                $this->messages[] = $mail;
            }
        };
        app()->instance(ContactMailSender::class, $sender);
        Config::set('identity.mail_sandbox', false);
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$dashboard,$mail]) {
            Config::set('public-content.contact_dashboard_accepted', $dashboard);
            Config::set('public-content.contact_production_mail_enabled', $mail);
            $receipt = app(ReceiveContact::class)->handle(new ContactSubmission('Gate test', 'gate@example.test', '+12025550123', null, 'A private notification gate test'), (string) Str::uuid7(), (string) Str::uuid7());
            $operation = DB::table('contact_deliveries')->where('message_id', $receipt['id'])->sole()->operation_id;
            app(OperationRunner::class)->run($operation);
            $this->assertDatabaseHas('contact_deliveries', ['operation_id' => $operation, 'state' => $dashboard && $mail ? 'sent' : 'blocked']);
        }
        self::assertCount(1, $sender->messages);
        self::assertStringStartsWith('https://localhost:8443/admin/contact-messages/', $sender->messages[0]->link);
        self::assertStringNotContainsString('gate@example.test', json_encode($sender->messages, JSON_THROW_ON_ERROR));
    }

    public function test_rollback_cannot_erase_receipts_or_attempt_history(): void
    {
        app(ReceiveContact::class)->handle(new ContactSubmission('History test', 'history@example.test', '+12025550123', null, 'Preserve the immutable receipt'), (string) Str::uuid7(), (string) Str::uuid7());
        try {
            DB::transaction(fn () => (require database_path('migrations/2026_09_29_000100_create_contact.php'))->down());
            self::fail('Receipt history cannot be rolled back.');
        } catch (QueryException $error) {
            self::assertNotEmpty($error->getCode());
        }
        $this->assertDatabaseCount('contact_messages', 1);
    }

    private function input(): array
    {
        return ['full_name' => 'Synthetic Contact', 'email' => Str::uuid7().'@example.test', 'phone' => '+12025550123',
            'company' => null, 'message' => 'A private synthetic contact message for verification.'];
    }
}
