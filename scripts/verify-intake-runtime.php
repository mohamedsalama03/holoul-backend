<?php

declare(strict_types=1);

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Categories\Actions\ManageTaxonomy;
use App\Modules\Categories\Data\TaxonomyActor;
use App\Modules\Categories\Data\TaxonomyChanges;
use App\Modules\Customers\Actions\CreateCustomer;
use App\Modules\Identity\Authorization\Actions\AssignCustomerRole;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Recovery\RecoveryActions;
use App\Modules\Identity\Security\SessionSecurity;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Actions\TransitionRequest;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// This local production-image fixture is never an HTTP route or an account
// provisioning command. Its explicit opt-in also requires the local SMTP sandbox.
try {
    if (PHP_SAPI !== 'cli' || getenv('HOLOUL_INTAKE_SMOKE') !== '1') {
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
    if ($mode === 'fixtures') {
        $run = $argv[2] ?? '';
        if (preg_match('/\A[a-f0-9]{24}\z/D', $run) !== 1) {
            throw new RuntimeException;
        }
        $accounts = DB::transaction(function () use ($run): array {
            app(RoleAuthority::class)->lockChanges();
            $accounts = [];
            foreach (['customer_a' => 'customer', 'customer_b' => 'customer', 'admin' => 'super_admin', 'manager' => 'project_manager'] as $label => $role) {
                $kind = $role === 'customer' ? 'customer' : 'staff';
                $password = 'B3!aA9-'.bin2hex(random_bytes(24));
                $email = 'b3-smoke-'.$run.'-'.str_replace('_', '-', $label).'@example.test';
                $user = User::query()->create([
                    'full_name' => 'B3 Runtime Smoke '.$label, 'email' => $email, 'email_display' => $email,
                    'password' => Hash::make($password), 'kind' => $kind, 'enabled' => true, 'auth_version' => 1,
                ]);
                $requestId = (string) Str::uuid7();
                $profile = null;
                if ($kind === 'customer') {
                    $profile = app(CreateCustomer::class)->handle($user->id, '+12025550123', '+12025550123');
                    app(AssignCustomerRole::class)->handle($user->id, $requestId);
                } else {
                    $roleId = DB::table('roles')->where('code', $role)->where('kind', 'staff')->value('id');
                    if (! is_string($roleId)) {
                        throw new RuntimeException;
                    }
                    // Synthetic grants exist only inside this explicitly guarded fixture.
                    DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $roleId, 'user_kind' => 'staff']);
                }
                app(RecoveryActions::class)->issueVerification($user, $requestId);
                app(RecordAuditEvent::class)->handle('identity.runtime_fixture_created', 'user', $user->id, $requestId, $user->id);
                $accounts[$label] = ['id' => $user->id, 'email' => $email, 'password' => $password, 'customer_id' => $profile?->id];
            }

            return $accounts;
        });
        // The wrapper redirects these one-time credentials only to a mode0600 file.
        fwrite(STDOUT, json_encode(['run_id' => $run, 'accounts' => $accounts], JSON_THROW_ON_ERROR));
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
    if (! is_array($manifest) || ! is_string($manifest['run_id'] ?? null)
        || preg_match('/\A[a-f0-9]{24}\z/D', $manifest['run_id']) !== 1
        || ! is_array($manifest['accounts'] ?? null)
        || array_keys($manifest['accounts']) !== ['customer_a', 'customer_b', 'admin', 'manager']) {
        throw new RuntimeException;
    }
    $run = $manifest['run_id'];
    $users = [];
    // Validate every exact fixture identity and its age before making any change.
    foreach ($manifest['accounts'] as $label => $record) {
        $email = 'b3-smoke-'.$run.'-'.str_replace('_', '-', $label).'@example.test';
        if (! is_array($record) || ! is_string($record['id'] ?? null) || ! Str::isUuid($record['id'], 7) || ($record['email'] ?? null) !== $email) {
            throw new RuntimeException;
        }
        $user = User::query()->whereKey($record['id'])->where('email', $email)->where('created_at', '>', now()->subDay())->firstOrFail();
        $kind = str_starts_with($label, 'customer_') ? 'customer' : 'staff';
        if ($user->kind !== $kind) {
            throw new RuntimeException;
        }
        $customerId = DB::table('customers')->where('user_id', $user->id)->value('id');
        if (($record['customer_id'] ?? null) !== $customerId) {
            throw new RuntimeException;
        }
        $users[$label] = $user;
    }
    $userIds = array_map(fn (User $user): string => $user->id, array_values($users));
    $requests = ProjectRequest::query()->whereIn('customer_user_id', $userIds)->get();
    if ($requests->count() > 10) {
        throw new RuntimeException;
    }
    foreach ($requests as $request) {
        $draft = DB::table('request_drafts')->where('request_id', $request->id)->first();
        if ($request->created_at->isBefore(now()->subDay()) || $draft === null
            || ! is_string($draft->project_name) || ! str_starts_with($draft->project_name, 'b3-smoke-'.$run.' ')) {
            throw new RuntimeException;
        }
    }
    $category = DB::table('categories')->where('slug', 'b3-smoke-'.$run.'-category')->first();
    $subcategory = $category === null ? null : DB::table('subcategories')->where('category_id', $category->id)
        ->where('slug', 'b3-smoke-'.$run.'-subcategory')->first();
    foreach ([$category, $subcategory] as $entry) {
        if ($entry !== null && (! is_string($entry->id) || ! Str::isUuid($entry->id, 7)
            || ! is_string($entry->created_at) || now()->parse($entry->created_at)->isBefore(now()->subDay()))) {
            throw new RuntimeException;
        }
    }
    $ok = true;
    foreach ($requests as $request) {
        try {
            $owner = User::query()->whereKey($request->customer_user_id)->firstOrFail();
            $actor = new IntakeActor($owner->id, $request->customer_id, $owner->email_verified_at !== null);
            if ($request->state === RequestState::Draft) {
                app(SubmitRequest::class)->handle($actor, $request->id, VersionPrecondition::etag($request->id, $request->lock_version),
                    'b3-cleanup-'.Str::uuid7(), (string) Str::uuid7());
                $request->refresh();
            }
            if ($request->state->withdrawable()) {
                app(TransitionRequest::class)->handle($actor, $request->id, VersionPrecondition::etag($request->id, $request->lock_version),
                    'withdraw', 'Synthetic runtime verification completed.', (string) Str::uuid7());
            }
        } catch (Throwable) {
            $ok = false;
        }
    }
    // Deactivate through the same versioned, audited action as the HTTP boundary.
    $taxonomyActor = new TaxonomyActor($users['admin']->id, true);
    foreach ([['subcategory', $subcategory], ['category', $category]] as [$type, $entry]) {
        if ($entry === null) {
            continue;
        }
        try {
            $manager = app(ManageTaxonomy::class);
            $current = $type === 'category' ? $manager->category($taxonomyActor, $entry->id) : $manager->subcategory($taxonomyActor, $entry->id);
            if ($current->active) {
                if ($type === 'category') {
                    $manager->updateCategory($taxonomyActor, $entry->id, new TaxonomyChanges(active: false), $current->lockVersion, (string) Str::uuid7());
                } else {
                    $manager->updateSubcategory($taxonomyActor, $entry->id, new TaxonomyChanges(active: false), $current->lockVersion, (string) Str::uuid7());
                }
            }
        } catch (Throwable) {
            $ok = false;
        }
    }
    // Account quarantine still runs if request or taxonomy cleanup failed. No
    // synthetic grant may block the genuine first Super Admin bootstrap later.
    DB::transaction(function () use ($userIds): void {
        app(RoleAuthority::class)->lockChanges();
        foreach (User::query()->whereIn('id', $userIds)->orderBy('id')->lockForUpdate()->get() as $user) {
            $user->enabled = false;
            $user->save();
            DB::table('user_roles')->where('user_id', $user->id)->delete();
            app(SessionSecurity::class)->revokeAll($user->id, (string) Str::uuid7(), $user->id);
            DB::table('identity_recovery_tokens')->where('user_id', $user->id)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            DB::table('identity_recovery_mail')->where('user_id', $user->id)->where('state', 'pending')
                ->update(['state' => 'discarded', 'encrypted_payload' => null, 'failure_code' => 'token_superseded']);
            app(RecordAuditEvent::class)->handle('identity.runtime_fixture_disabled', 'user', $user->id, (string) Str::uuid7(), $user->id);
        }
    });
    $requestIds = $requests->modelKeys();
    $remaining = ProjectRequest::query()->whereIn('id', $requestIds)->whereNotIn('state', ['withdrawn', 'rejected'])->count();
    $roles = DB::table('user_roles')->whereIn('user_id', $userIds)->count();
    $sessions = DB::table('identity_sessions')->whereIn('user_id', $userIds)->count();
    $enabled = User::query()->whereIn('id', $userIds)->where('enabled', true)->count();
    $ok = $ok && $remaining === 0 && $roles === 0 && $sessions === 0 && $enabled === 0;
    $retained = [];
    foreach (['request_revisions', 'request_assignments', 'information_requests', 'information_responses', 'information_resolutions', 'request_state_changes', 'intake_notification_intents'] as $table) {
        $retained[$table] = DB::table($table)->whereIn('request_id', $requestIds)->count();
    }
    $retained['audit_events'] = DB::table('audit_events')->whereIn('actor_id', $userIds)->count();
    fwrite(STDOUT, json_encode(['event' => 'verification.intake_cleanup', 'ok' => $ok, 'disabled_users' => count($users),
        'remaining_roles' => $roles, 'remaining_sessions' => $sessions, 'nonterminal_requests' => $remaining,
        'retained_requests' => count($requestIds), 'retained_immutable_rows' => $retained], JSON_THROW_ON_ERROR)."\n");
    exit($ok ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, "{\"event\":\"verification.intake_fixture_failed\"}\n");
    exit(1);
}
