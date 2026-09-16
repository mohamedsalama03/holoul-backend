<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Tests\Support\IdentityHttp;
use Tests\TestCase;

final class IdentityAtomicityTest extends TestCase
{
    use DatabaseMigrations;
    use IdentityHttp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeBrowser();
    }

    public function test_failed_login_audit_cannot_issue_a_usable_browser_session(): void
    {
        $user = $this->customerUser();
        [$logger, $capture] = $this->captureErrors();
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT identity_atomicity_reject_audit CHECK (event_type <> 'identity.login_succeeded')");

        try {
            $response = $this->signIn($user)->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
            $this->assertDatabaseCount('identity_sessions', 0);
            $this->assertDatabaseCount('audit_events', 0);
            $this->assertSafeFailure($response, $capture);
            // Replay any cookie written while the failed login unwinds through session middleware.
            $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
            $this->assertDatabaseCount('identity_sessions', 0);
        } finally {
            DB::statement('ALTER TABLE audit_events DROP CONSTRAINT identity_atomicity_reject_audit');
            $logger->popHandler();
        }
    }

    public function test_failed_registration_audit_rolls_back_identity_profile_authority_and_mail_intent(): void
    {
        [$logger, $capture] = $this->captureErrors();
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT identity_atomicity_reject_audit CHECK (event_type <> 'identity.registered')");

        try {
            $response = $this->browser('POST', '/api/v1/auth/register', [
                'full_name' => 'Atomic Registration', 'email' => Str::uuid7().'@example.test',
                'password' => 'Correct-Horse-72-River', 'password_confirmation' => 'Correct-Horse-72-River',
                'phone' => '+12025550123',
            ])->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');

            foreach (['users', 'customers', 'user_roles', 'identity_recovery_tokens', 'identity_recovery_mail', 'async_operations', 'identity_sessions', 'audit_events'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }

            $this->assertSafeFailure($response, $capture);
            $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
        } finally {
            DB::statement('ALTER TABLE audit_events DROP CONSTRAINT identity_atomicity_reject_audit');
            $logger->popHandler();
        }
    }

    public function test_verified_user_snapshot_cannot_complete_login_after_auth_version_changes(): void
    {
        $verifiedUser = $this->customerUser();
        self::assertTrue(Hash::check('Correct-Horse-72-River', $verifiedUser->password));
        $verifiedVersion = $verifiedUser->auth_version;
        $sessions = app(SessionSecurity::class);
        $sessions->revokeAll($verifiedUser->id, (string) Str::uuid7(), $verifiedUser->id);
        $request = Request::create('https://localhost:8443/api/v1/auth/login', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();
        $request->attributes->set('request_id', (string) Str::uuid7());

        try {
            $sessions->completeLogin($request, $verifiedUser, false);
            self::fail('A stale verified snapshot issued a session.');
        } catch (AuthenticationException) {
            self::assertSame($verifiedVersion, $verifiedUser->auth_version);
            self::assertSame($verifiedVersion + 1, $verifiedUser->fresh()->auth_version);
            self::assertFalse(Auth::guard('web')->check());
            self::assertFalse($request->session()->has('identity.session_id'));
            $this->assertDatabaseCount('identity_sessions', 0);
            self::assertSame(0, DB::table('audit_events')->where('event_type', 'identity.login_succeeded')->count());
        }
    }

    /** @return array{Logger, TestHandler} */
    private function captureErrors(): array
    {
        $logger = Log::channel('stdout')->getLogger();
        self::assertInstanceOf(Logger::class, $logger);
        $capture = new TestHandler(Level::Error, bubble: false);
        // Keep the configured processors; only capture their already-sanitized records.
        $logger->pushHandler($capture);

        return [$logger, $capture];
    }

    private function assertSafeFailure(TestResponse $response, TestHandler $capture): void
    {
        $requestId = $response->headers->get('X-Request-ID');
        self::assertTrue(Str::isUuid($requestId));
        $records = $capture->getRecords();
        self::assertNotEmpty($records);

        foreach ($records as $record) {
            self::assertSame('request.failed', $record->message);
            self::assertSame([
                'request_id' => $requestId,
                'exception_type' => QueryException::class,
                'error_code' => 'INTERNAL_ERROR',
            ], $record->context);
            self::assertSame([], $record->extra);
        }

        $visible = $response->getContent().json_encode($records, JSON_THROW_ON_ERROR);

        foreach (['identity_atomicity_reject_audit', 'insert into', 'SQLSTATE', '/var/www', 'Correct-Horse-72-River'] as $privateDetail) {
            self::assertStringNotContainsString($privateDetail, $visible);
        }
    }
}
