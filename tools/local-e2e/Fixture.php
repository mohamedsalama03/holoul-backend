<?php

declare(strict_types=1);

namespace HoloulLocalE2E;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Customers\Actions\CreateCustomer;
use App\Modules\Identity\Actions\Authentication;
use App\Modules\Identity\Authorization\Actions\AssignCustomerRole;
use App\Modules\Identity\Mfa\MfaActions;
use App\Modules\Identity\Mfa\MfaCredential;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use SensitiveParameter;

/** Development-only fixture, never registered as an HTTP endpoint or shipped in the runtime image. */
final class Fixture
{
    public static function assertLocalEnvironment(): void
    {
        if (PHP_SAPI !== 'cli' || getenv('HOLOUL_LOCAL_E2E') !== '1' || getenv('APP_ENV') !== 'local'
            || ! app()->environment('local') || ! is_file(base_path('vendor/bin/phpunit'))
            || Config::boolean('app.debug') || Config::string('operations.deployment_profile') !== 'local-verification'
            || Config::string('app.url') !== 'https://localhost:8443' || Config::string('identity.origin') !== 'https://localhost:8443'
            || ! Config::boolean('identity.mail_sandbox') || Config::string('mail.mailers.smtp.host') !== 'mailpit'
            || Config::string('database.default') !== 'pgsql' || Config::string('database.connections.pgsql.host') !== 'postgres'
            || Config::string('database.connections.pgsql.database') !== 'holoul'
            || Config::string('database.connections.pgsql.username') !== 'holoul_app') {
            throw new RuntimeException('Explicit local E2E environment required.');
        }
    }

    /** @return array{run:string,passwords:array{staff:string,enroll_staff:string,customer:string}} */
    public static function manifest(#[SensitiveParameter] string $json): array
    {
        $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (strlen($json) > 4096 || ! is_array($value) || array_keys($value) !== ['run', 'passwords']
            || ! is_string($value['run']) || preg_match('/\A[a-f0-9]{24}\z/D', $value['run']) !== 1
            || ! is_array($value['passwords']) || array_keys($value['passwords']) !== ['staff', 'enroll_staff', 'customer']) {
            throw new RuntimeException('Invalid synthetic fixture configuration.');
        }
        $passwords = $value['passwords'];
        foreach (['staff', 'enroll_staff', 'customer'] as $label) {
            if (! is_string($passwords[$label]) || preg_match('/\AE2E-[a-f0-9]{64}!aA9\z/D', $passwords[$label]) !== 1) {
                throw new RuntimeException('Generated per-account credentials required.');
            }
        }
        if (count(array_unique([$passwords['staff'], $passwords['enroll_staff'], $passwords['customer']])) !== 3) {
            throw new RuntimeException('Distinct credentials required.');
        }

        return ['run' => $value['run'], 'passwords' => ['staff' => $passwords['staff'], 'enroll_staff' => $passwords['enroll_staff'], 'customer' => $passwords['customer']]];
    }

    /** Internal test recipe. CLI calls the environment and actual database guards before this method.
     * @param  array{run:string,passwords:array{staff:string,enroll_staff:string,customer:string}}  $manifest
     * @return array<string,string>
     */
    public function reset(#[SensitiveParameter] array $manifest): array
    {
        return DB::transaction(function () use ($manifest): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['local-e2e:'.$manifest['run']]);
            $environment = ['HOLOUL_E2E_ADMIN_URL' => 'https://localhost:8443/admin'];
            foreach ($manifest['passwords'] as $label => $password) {
                $customer = $label === 'customer';
                $email = 'local-e2e-'.$manifest['run'].'-'.$label.'@example.test';
                $name = 'Synthetic local E2E '.$label.' '.$manifest['run'];
                $user = User::query()->where('email', $email)->lockForUpdate()->first();
                if ($user !== null && ($user->full_name !== $name || $user->kind !== ($customer ? 'customer' : 'staff'))) {
                    throw new RuntimeException('Refusing to reset an identity outside this fixture.');
                }
                $user ??= new User;
                $user->fill(['full_name' => $name, 'email' => $email, 'email_display' => $email,
                    'password' => $password, 'kind' => $customer ? 'customer' : 'staff', 'enabled' => true, 'email_verified_at' => now()]);
                if (! $user->exists) {
                    $user->auth_version = 1;
                }
                $user->save();
                $requestId = (string) Str::uuid7();
                app(SessionSecurity::class)->revokeAll($user->id, $requestId);
                $credential = MfaCredential::query()->where('user_id', $user->id)->lockForUpdate()->first();
                if ($credential !== null) {
                    DB::table('identity_mfa_recovery_codes')->where('mfa_id', $credential->id)->delete();
                    $credential->delete();
                }
                if ($customer) {
                    if (! DB::table('customers')->where('user_id', $user->id)->exists()) {
                        app(CreateCustomer::class)->handle($user->id, '+12025550123', '+12025550123');
                    }
                    app(AssignCustomerRole::class)->handle($user->id, $requestId);
                } else {
                    $role = DB::table('roles')->where('code', 'project_manager')->sole();
                    DB::table('user_roles')->insertOrIgnore(['user_id' => $user->id, 'role_id' => $role->id, 'user_kind' => 'staff']);
                }
                $prefix = 'HOLOUL_E2E_'.strtoupper($label);
                $environment[$prefix.'_EMAIL'] = $email;
                $environment[$prefix.'_PASSWORD'] = $password;
                if ($label === 'staff') {
                    $environment[$prefix.'_TOTP_SECRET'] = $this->enroll($user, $password);
                }
                app(RecordAuditEvent::class)->handle('identity.local_e2e.reset', 'user', $user->id, $requestId);
            }

            return $environment;
        });
    }

    private function enroll(User $user, #[SensitiveParameter] string $password): string
    {
        $session = new Store('local-e2e-setup', new ArraySessionHandler(120));
        $session->start();
        $request = Request::create('https://localhost:8443/api/v1/auth/login');
        $request->setLaravelSession($session);
        $request->attributes->set('request_id', (string) Str::uuid7());
        app()->instance('request', $request);
        app()->instance('session.store', $session);
        Auth::forgetGuards();
        $result = app(Authentication::class)->login($request, $user->email, $password);
        if ($result['next_step'] !== 'mfa_enrollment') {
            throw new RuntimeException('Real MFA enrollment required.');
        }
        $secret = app(MfaActions::class)->beginEnrollment($request)['secret'];
        // Use the already-supported previous-step window; current-step E2E login remains fresh.
        $code = (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30) - 1);
        app(MfaActions::class)->confirmEnrollment($request, $code);
        app(SessionSecurity::class)->revokeAll($user->id, (string) Str::uuid7());
        Auth::forgetGuards();

        return $secret;
    }
}
