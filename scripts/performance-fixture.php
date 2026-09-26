<?php

declare(strict_types=1);

// CLI-only synthetic fixture. Reuses the same owner-action fixture recipes as
// the regression suite, without PHPUnit, fake providers or database bypasses.
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Categories\Actions\ManageTaxonomy;
use App\Modules\Categories\Data\TaxonomyActor;
use App\Modules\Customers\Actions\CreateCustomer;
use App\Modules\Identity\Actions\ReadActiveIdentity;
use App\Modules\Identity\Authorization\Actions\AssignCustomerRole;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Actions\TransitionRequest;
use App\Modules\ProjectIntake\Data\IntakeActor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\ProjectFixtures;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || getenv('HOLOUL_B8_PERFORMANCE') !== '1' || ! $app->environment('production')
    || Config::boolean('app.debug') || is_file(__DIR__.'/../vendor/bin/phpunit')
    || Config::string('operations.deployment_profile') !== 'local-verification'
    || ! Config::boolean('identity.mail_sandbox') || Config::string('mail.mailers.smtp.host') !== 'mailpit'
    || Config::string('database.connections.pgsql.username') !== 'holoul_app'
    || parse_url(Config::string('app.url'), PHP_URL_HOST) !== 'localhost') {
    throw new RuntimeException('Synthetic local production-image fixture only.');
}
set_exception_handler(function (Throwable $error): never {
    // Diagnose fixture code without exposing exception messages/SQL bindings.
    fwrite(STDERR, json_encode(['event' => 'performance.fixture_failed', 'type' => $error::class,
        'file' => basename($error->getFile()), 'line' => $error->getLine(),
        'trace' => array_map(fn (array $frame): array => array_intersect_key($frame, array_flip(['class', 'function', 'line'])), array_slice($error->getTrace(), 0, 8))], JSON_THROW_ON_ERROR));
    exit(1);
});
foreach (['IntakeFixtures', 'CommercialFixtures', 'ProjectFixtures'] as $trait) {
    require __DIR__.'/../tests/Support/'.$trait.'.php';
}

final class B8PerformanceFixture
{
    use ProjectFixtures;

    public User $customer;

    public User $author;

    public User $approver;

    public array $taxonomy;

    private int $staffCall = 0;

    private function intakeCustomer(bool $verified = true): User
    {
        return $this->customer;
    }

    private function intakeStaff(string $role = 'project_manager'): User
    {
        return ++$this->staffCall % 2 === 1 ? $this->author : $this->approver;
    }

    private function intakeTaxonomy(): array
    {
        return $this->taxonomy;
    }

    private function intakeActor(User $user): IntakeActor
    {
        // Bulk current permission projection avoids one fixture query per enum
        // case. Business state/actions, grants and their guards are unchanged.
        $identity = DB::transaction(fn () => app(ReadActiveIdentity::class)->locked($user->id));
        $customer = $identity->kind === 'customer' ? DB::table('customers')->where('user_id', $identity->id)->value('id') : null;

        return new IntakeActor($identity->id, $customer, $identity->verifiedEmail, $identity->permissions);
    }

    public function actors(User $user): array
    {
        return [$this->intakeActor($user), $this->projectActor($user)];
    }

    public function seed(array $users): array
    {
        $result = [];
        for ($i = 0; $i < 150; $i++) {
            $this->customer = $users[$i];
            $label = 'customer_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $result[$label] = ['projects' => [], 'requests' => [], 'drafts' => []];
            if ($i < 100) {
                $fixture = $this->projectFixture();
                $result[$label]['projects'][] = $fixture['project']->id;
                $result[$label]['requests'][] = $fixture['request']->id;
            }
            $actor = $this->intakeActor($this->customer);
            // 900 additional requests, including mutable drafts for HTTP load.
            for ($j = 0; $j < 6; $j++) {
                $record = app(ManageDraft::class)->create($actor, $this->intakeInput(), (string) Str::uuid7());
                if ($j < 4) {
                    app(SubmitRequest::class)->handle($actor, $record->id, $this->commercialEtag($record), (string) Str::uuid7(), (string) Str::uuid7());
                    $result[$label]['requests'][] = $record->id;
                    if ($j === 0 && $i % 2 === 0) {
                        $record->refresh();
                        app(TransitionRequest::class)->handle($actor, $record->id, $this->commercialEtag($record), 'withdraw',
                            'Synthetic workload distribution.', (string) Str::uuid7());
                    }
                } else {
                    $result[$label]['drafts'][] = ['id' => $record->id, 'etag' => $this->commercialEtag($record)];
                }
            }
        }

        return $result;
    }
}

function b8Manifest(): array
{
    $raw = stream_get_contents(STDIN, 1048577);
    $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (strlen($raw) > 1048576 || ! is_array($manifest) || ! preg_match('/\A[a-f0-9]{24}\z/D', $manifest['run_id'] ?? '')
        || ! is_array($manifest['accounts'] ?? null) || count($manifest['accounts']) !== 152) {
        throw new RuntimeException('Invalid synthetic manifest.');
    }
    foreach ($manifest['accounts'] as $label => $account) {
        $email = 'b8-load-'.$manifest['run_id'].'-'.$label.'@example.test';
        if (! is_array($account) || ($account['email'] ?? null) !== $email || ! Str::isUuid($account['id'] ?? '', 7)
            || ! User::query()->whereKey($account['id'])->where('email', $email)->where('created_at', '>', now()->subDay())->exists()) {
            throw new RuntimeException('Synthetic identity validation failed.');
        }
    }

    return $manifest;
}

$mode = $argv[1] ?? '';
if ($mode === 'create') {
    $run = $argv[2] ?? '';
    if (! preg_match('/\A[a-f0-9]{24}\z/D', $run)) {
        throw new RuntimeException('Invalid run marker.');
    }
    $password = 'B8!aA9-'.bin2hex(random_bytes(24));
    $hash = Hash::make($password);
    $accounts = DB::transaction(function () use ($run, $password, $hash): array {
        $accounts = [];
        for ($i = 0; $i < 152; $i++) {
            $customer = $i < 150;
            $label = $customer ? 'customer_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT) : ($i === 150 ? 'admin' : 'approver');
            $email = 'b8-load-'.$run.'-'.$label.'@example.test';
            $user = User::query()->create(['full_name' => 'Synthetic performance '.$label, 'email' => $email, 'email_display' => $email,
                'password' => $hash, 'kind' => $customer ? 'customer' : 'staff', 'enabled' => true, 'auth_version' => 1, 'email_verified_at' => now()]);
            $profile = null;
            if ($customer) {
                $profile = app(CreateCustomer::class)->handle($user->id, '+12025550123', '+12025550123');
                app(AssignCustomerRole::class)->handle($user->id, (string) Str::uuid7());
            } else {
                DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('code', 'super_admin')->value('id'), 'user_kind' => 'staff']);
            }
            $accounts[$label] = ['id' => $user->id, 'email' => $email, 'password' => $password, 'customer_id' => $profile?->id];
        }

        return $accounts;
    });
    // Account creation is atomic. The wrapper has a narrowly scoped cleanup-run
    // fallback for termination between this commit and writing the manifest.
    fwrite(STDOUT, json_encode(['run_id' => $run, 'accounts' => $accounts], JSON_THROW_ON_ERROR));
} elseif ($mode === 'seed') {
    $manifest = b8Manifest();
    $fixture = new B8PerformanceFixture;
    $fixture->author = User::query()->findOrFail($manifest['accounts']['admin']['id']);
    $fixture->approver = User::query()->findOrFail($manifest['accounts']['approver']['id']);
    $actor = new TaxonomyActor($fixture->author->id, true);
    $category = app(ManageTaxonomy::class)->createCategory($actor, 'B8 synthetic software', 'b8-load-'.$manifest['run_id'], true, 0, (string) Str::uuid7());
    $child = app(ManageTaxonomy::class)->createSubcategory($actor, $category->id, 'B8 synthetic portals', 'b8-load-'.$manifest['run_id'], true, 0, (string) Str::uuid7());
    $fixture->taxonomy = ['category_id' => $category->id, 'subcategory_id' => $child->id];
    $customers = [];
    foreach ($manifest['accounts'] as $label => $account) {
        if (str_starts_with($label, 'customer_')) {
            $customers[] = User::query()->findOrFail($account['id']);
        }
    }
    $data = $fixture->seed($customers);
    fwrite(STDOUT, json_encode([...$manifest, 'records' => $data, 'taxonomy' => $fixture->taxonomy,
        'dataset' => ['synthetic_customers' => 150, 'synthetic_projects' => 100, 'synthetic_requests' => 1000,
            'total_customers' => DB::table('customers')->count(), 'total_projects' => DB::table('projects')->count(),
            'total_requests' => DB::table('project_requests')->count(), 'audit_events' => DB::table('audit_events')->count()]], JSON_THROW_ON_ERROR));
} elseif (in_array($mode, ['cleanup', 'cleanup-run'], true)) {
    if ($mode === 'cleanup') {
        $manifest = b8Manifest();
    } else {
        $run = $argv[2] ?? '';
        if (! preg_match('/\A[a-f0-9]{24}\z/D', $run)) {
            throw new RuntimeException('Invalid cleanup marker.');
        }
        $emails = [];
        for ($i = 0; $i < 152; $i++) {
            $label = $i < 150 ? 'customer_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT) : ($i === 150 ? 'admin' : 'approver');
            $emails[] = 'b8-load-'.$run.'-'.$label.'@example.test';
        }
        $accounts = User::query()->whereIn('email', $emails)->where('created_at', '>', now()->subDay())->get(['id', 'email'])->toArray();
        $manifest = ['run_id' => $run, 'accounts' => $accounts];
    }
    DB::transaction(function () use ($manifest): void {
        $ids = array_column($manifest['accounts'], 'id');
        DB::table('users')->whereIn('id', $ids)->update(['enabled' => false, 'auth_version' => DB::raw('auth_version + 1')]);
        DB::table('identity_sessions')->whereIn('user_id', $ids)->delete();
        DB::table('sessions')->whereIn('user_id', $ids)->delete();
        DB::table('user_roles')->whereIn('user_id', $ids)->delete();
        foreach ($ids as $id) {
            app(RecordAuditEvent::class)->handle('identity.runtime_fixture_disabled', 'user', $id, (string) Str::uuid7(), $id);
        }
    });
    fwrite(STDOUT, json_encode(['disabled' => count($manifest['accounts']), 'immutable_history_retained' => true], JSON_THROW_ON_ERROR));
} elseif ($mode === 'profile') {
    $manifest = b8Manifest();
    require __DIR__.'/performance-profile.php';
} elseif (in_array($mode, ['queue-seed', 'queue-evidence', 'queue-retry-seed', 'queue-recover-seed'], true)) {
    $manifest = b8Manifest();
    require __DIR__.'/performance-queues.php';
} else {
    throw new RuntimeException('Unknown bounded fixture operation.');
}
