<?php

declare(strict_types=1);

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Console-only fixtures for the exact production image connected to the local
// SMTP sandbox. This is deliberately unusable with an external mail provider.
try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException;
    }
    require __DIR__.'/../vendor/autoload.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('production') || Config::boolean('app.debug') || is_file(__DIR__.'/../vendor/bin/phpunit')
        || ! Config::boolean('identity.mail_sandbox') || Config::string('mail.mailers.smtp.host') !== 'mailpit'
        || Config::string('database.connections.pgsql.username') !== 'holoul_app'
        || parse_url(Config::string('app.url'), PHP_URL_HOST) !== 'localhost') {
        throw new RuntimeException;
    }
    $mode = $argv[1] ?? '';
    if ($mode === 'staff-fixture') {
        $password = bin2hex(random_bytes(24)).'Az9';
        $user = DB::transaction(function () use ($password): User {
            $user = User::query()->create([
                'full_name' => 'B2 Runtime Smoke Staff', 'email' => 'b2-smoke-'.bin2hex(random_bytes(12)).'@example.test',
                'email_display' => 'B2 Runtime Smoke Staff', 'password' => Hash::make($password),
                'kind' => 'staff', 'enabled' => true, 'auth_version' => 1,
            ]);
            app(RecordAuditEvent::class)->handle('identity.runtime_fixture_created', 'user', $user->id, (string) Str::uuid7(), $user->id);

            return $user;
        });
        // Caller redirects this one-time credential to a mode0600 temporary file,
        // never to application logs or the verification report.
        fwrite(STDOUT, json_encode(['id' => $user->id, 'email' => $user->email, 'password' => $password], JSON_THROW_ON_ERROR));
        exit(0);
    }
    if ($mode !== 'cleanup') {
        throw new RuntimeException;
    }
    $input = stream_get_contents(STDIN, 65537);
    if (! is_string($input) || strlen($input) > 65536) {
        throw new RuntimeException;
    }
    $manifest = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || ! isset($manifest['users']) || ! is_array($manifest['users']) || count($manifest['users']) > 10) {
        throw new RuntimeException;
    }
    $count = DB::transaction(function () use ($manifest): int {
        $deleted = 0;
        foreach ($manifest['users'] as $record) {
            if (! is_array($record) || ! isset($record['email']) || ! is_string($record['email'])
                || preg_match('/\Ab2-smoke-[a-f0-9]{24}@example\.test\z/', $record['email']) !== 1) {
                throw new RuntimeException;
            }
            $user = User::query()->where('email', $record['email'])->where('created_at', '>', now()->subDay())->lockForUpdate()->first();
            if ($user === null) {
                continue;
            }
            if (isset($record['user_id']) && $record['user_id'] !== $user->id) {
                throw new RuntimeException;
            }
            $operations = DB::table('identity_recovery_mail')->where('user_id', $user->id)->pluck('operation_id')->all();
            if (DB::table('async_operations')->whereIn('id', $operations)->whereNotIn('state', ['succeeded', 'failed', 'cancelled'])->exists()) {
                throw new RuntimeException;
            }
            DB::table('identity_recovery_mail')->where('user_id', $user->id)->delete();
            DB::table('identity_recovery_tokens')->where('user_id', $user->id)->delete();
            DB::table('async_operations')->whereIn('id', $operations)->delete();
            DB::table('identity_mfa_recovery_codes')->whereIn('mfa_id', DB::table('identity_mfa')->select('id')->where('user_id', $user->id))->delete();
            DB::table('identity_mfa')->where('user_id', $user->id)->delete();
            DB::table('user_roles')->where('user_id', $user->id)->delete();
            DB::table('customers')->where('user_id', $user->id)->delete();
            DB::table('identity_sessions')->where('user_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
            $deleted++;
        }

        return $deleted;
    });
    fwrite(STDOUT, json_encode(['event' => 'verification.identity_cleanup', 'deleted_users' => $count, 'append_only_audit_retained' => true], JSON_THROW_ON_ERROR)."\n");
} catch (Throwable) {
    fwrite(STDERR, "{\"event\":\"verification.identity_fixture_failed\"}\n");
    exit(1);
}
