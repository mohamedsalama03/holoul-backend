<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Application\Intake\GuestIntakeThrottle;
use App\Infrastructure\Http\StartSecureSession;
use App\Modules\Identity\Http\ExactOrigin;
use App\Modules\Identity\Http\SessionAuthenticated;
use App\Modules\Identity\Http\StrictCsrf;
use App\Modules\Identity\Models\User;
use Laravel\Sanctum\HasApiTokens;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class FoundationArchitectureTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const MODULE_DEPENDENCIES = [
        'Identity' => ['Audit'],
        'Customers' => ['Identity', 'Audit'],
        'Categories' => ['Audit'],
        'ProjectIntake' => ['Customers', 'Categories', 'Documents', 'Audit'],
        'Documents' => ['Audit'],
        'AI' => ['Documents', 'Audit'],
        'Discovery' => ['ProjectIntake', 'Documents', 'Audit'],
        'Proposals' => ['ProjectIntake', 'Discovery', 'Documents', 'Audit'],
        'Projects' => ['ProjectIntake', 'Proposals', 'Documents', 'Audit'],
        'Notifications' => ['Identity', 'Customers', 'Audit'],
        'Administration' => ['Identity', 'Customers', 'Categories', 'ProjectIntake', 'Documents', 'AI', 'Discovery', 'Proposals', 'Projects', 'Notifications', 'Audit', 'Reporting'],
        'Audit' => [],
        'Reporting' => ['Identity', 'Customers', 'Categories', 'ProjectIntake', 'Documents', 'AI', 'Discovery', 'Proposals', 'Projects', 'Notifications', 'Administration', 'Audit'],
    ];

    /** @var list<string> */
    private const INFRASTRUCTURE_TABLES = ['audit_events', 'async_operations', 'failed_jobs', 'job_batches', 'sessions', 'migrations',
        'users', 'identity_sessions', 'customers', 'roles', 'permissions', 'role_permissions', 'user_roles',
        'identity_recovery_tokens', 'identity_recovery_mail', 'identity_mfa', 'identity_mfa_recovery_codes',
        'identity_staff_invitations', 'identity_staff_invitation_roles', 'identity_staff_invitation_keys', 'identity_staff_invitation_mail',
        'currencies', 'categories', 'subcategories', 'project_requests', 'request_drafts', 'request_revisions',
        'request_assignments', 'information_requests', 'information_responses', 'information_resolutions',
        'request_state_changes', 'intake_submission_keys', 'intake_notification_intents',
        'documents', 'document_quotas', 'document_orphan_objects', 'document_reconciliation_cursors',
        'intake_draft_documents', 'intake_revision_documents', 'intake_guest_access', 'intake_guest_claims',
        'discovery_records', 'discovery_revisions', 'discovery_requirements', 'discovery_signoffs',
        'proposal_series', 'proposals', 'proposal_items', 'proposal_deliverables', 'proposal_contributors',
        'proposal_approvals', 'proposal_decisions', 'proposal_events', 'proposal_command_keys', 'proposal_documents',
        'projects', 'project_members', 'project_membership_history', 'project_phase_evidence',
        'project_completion_confirmations', 'project_state_changes', 'milestones', 'milestone_changes',
        'project_updates', 'project_activity', 'project_document_uploads', 'project_documents', 'project_command_keys',
        'ai_budget_days', 'ai_runs', 'ai_suggestions', 'ai_provider_attempts', 'ai_command_keys',
        'notifications', 'notification_inboxes', 'notification_preferences', 'notification_deliveries',
        'notification_delivery_attempts', 'notification_replays', 'notification_command_keys'];

    /** @var array<string, list<string>> */
    private const CONTRACT_ONLY_DEPENDENCIES = [
        'Discovery' => ['ProjectIntake'],
        'Proposals' => ['ProjectIntake', 'Discovery'],
        'Projects' => ['ProjectIntake', 'Proposals'],
        'Notifications' => ['Identity', 'Customers'],
    ];

    public function test_module_dependencies_follow_the_approved_map(): void
    {
        foreach ($this->phpFiles(app_path('Modules')) as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen(app_path('Modules')) + 1));
            $owner = explode('/', $relative)[0];
            self::assertArrayHasKey($owner, self::MODULE_DEPENDENCIES, $file);

            foreach ($this->names($this->parse($file)) as $name) {
                if (! str_starts_with($name, 'App\\Modules\\')) {
                    continue;
                }

                $parts = explode('\\', $name);
                $dependency = $parts[2];

                if ($dependency === $owner) {
                    continue;
                }

                self::assertContains($dependency, self::MODULE_DEPENDENCIES[$owner], "$owner may not depend on $name ($file)");
                self::assertNotContains('Models', array_slice($parts, 3), "Cross-module models bypass owned entry points: $name ($file)");

                if ($owner === 'Reporting' || in_array($dependency, self::CONTRACT_ONLY_DEPENDENCIES[$owner] ?? [], true)) {
                    self::assertSame('Contracts', $parts[3] ?? null, "$owner accesses $dependency through its approved read/recipient contract ($file)");
                }

                if ($owner === 'Administration') {
                    self::assertSame('Actions', $parts[3] ?? null, "Administration invokes authorized public actions ($file)");
                }
            }
        }
    }

    public function test_shared_infrastructure_does_not_depend_on_business_modules(): void
    {
        foreach ($this->phpFiles(app_path('Infrastructure')) as $file) {
            foreach ($this->names($this->parse($file)) as $name) {
                if (str_starts_with($name, 'App\\Modules\\')) {
                    self::assertTrue(str_starts_with($name, 'App\\Modules\\Audit\\'), "Shared infrastructure must not depend on a business module ($file)");
                }
            }
        }
    }

    public function test_external_provider_clients_are_confined_to_adapters(): void
    {
        $providerPrefixes = ['Aws\\', 'OpenAI', 'Anthropic\\', 'Stripe\\', 'Twilio\\', 'Resend', 'Postmark\\', 'Google\\Cloud\\', 'MicrosoftAzure\\', 'GuzzleHttp\\Client'];

        foreach ($this->phpFiles(app_path()) as $file) {
            if (str_contains(str_replace('\\', '/', $file), '/Adapters/')) {
                continue;
            }

            $nodes = $this->parse($file);

            foreach ($this->names($nodes) as $name) {
                foreach ($providerPrefixes as $prefix) {
                    self::assertFalse(str_starts_with($name, $prefix), "Provider client $name belongs in an adapter ($file)");
                }

                self::assertNotSame('Illuminate\\Support\\Facades\\Http', $name, "External HTTP access belongs in an adapter ($file)");
            }

            foreach ((new NodeFinder)->findInstanceOf($nodes, Expr\FuncCall::class) as $call) {
                if ($call->name instanceof Name) {
                    self::assertFalse(str_starts_with(strtolower($call->name->toString()), 'curl_'), "Direct cURL access belongs in an adapter ($file)");
                }
            }
        }
    }

    public function test_http_controllers_only_coordinate_http_concerns(): void
    {
        foreach ($this->phpFiles(app_path()) as $file) {
            if (! str_contains(str_replace('\\', '/', $file), '/Controllers/')) {
                continue;
            }

            $nodes = $this->parse($file);

            foreach ($this->names($nodes) as $name) {
                self::assertFalse(str_starts_with($name, 'Illuminate\\Database\\'), "Controllers may not execute database logic ($file)");
                self::assertNotContains($name, ['Illuminate\\Support\\Facades\\DB', 'Illuminate\\Support\\Facades\\Redis', 'Illuminate\\Support\\Facades\\Queue'], "Controllers delegate infrastructure work ($file)");
                self::assertFalse(str_contains($name, '\\Models\\'), "Controllers may not access domain models ($file)");
            }

            foreach ((new NodeFinder)->findInstanceOf($nodes, Stmt\ClassMethod::class) as $method) {
                self::assertLessThanOrEqual(8, count($method->stmts ?? []), "Controller {$method->name} should delegate work ($file)");

                foreach ([Stmt\For_::class, Stmt\Foreach_::class, Stmt\While_::class, Stmt\Do_::class] as $loop) {
                    self::assertSame([], (new NodeFinder)->findInstanceOf($method->stmts ?? [], $loop), "Controller iteration belongs in an action/resource ($file)");
                }
            }
        }
    }

    public function test_postgresql_is_the_only_configured_application_database(): void
    {
        self::assertSame('pgsql', config('database.default'));
        $connections = config('database.connections');
        self::assertIsArray($connections);
        self::assertNotEmpty($connections);

        foreach ($connections as $connection) {
            self::assertIsArray($connection);
            self::assertSame('pgsql', $connection['driver'] ?? null);
        }

        foreach ($this->phpFiles(config_path()) as $file) {
            foreach ((new NodeFinder)->findInstanceOf($this->parse($file), String_::class) as $literal) {
                self::assertFalse(str_contains(strtolower($literal->value), 'sqlite'), "SQLite configuration is forbidden ($file)");
            }
        }

        $manifest = json_decode($this->contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        foreach (['require', 'require-dev'] as $section) {
            self::assertIsArray($manifest[$section] ?? []);

            foreach (array_keys($manifest[$section] ?? []) as $package) {
                self::assertFalse(str_contains(strtolower((string) $package), 'sqlite'));
            }
        }

        $xml = simplexml_load_string($this->contents(base_path('phpunit.xml')));
        self::assertNotFalse($xml);

        foreach ($xml->xpath('/phpunit/php/env[@name="DB_CONNECTION"]') ?: [] as $connection) {
            self::assertSame('pgsql', (string) $connection['value']);
        }
    }

    public function test_production_configuration_cannot_enable_debug_from_environment(): void
    {
        $process = new Process([
            PHP_BINARY,
            '-r',
            'require "vendor/autoload.php"; $config = require "config/app.php"; echo json_encode($config["debug"], JSON_THROW_ON_ERROR);',
        ], base_path(), ['APP_ENV' => 'production', 'APP_DEBUG' => 'true'], null, 15);
        $process->mustRun();

        self::assertSame('false', trim($process->getOutput()));
    }

    public function test_only_authorized_b1_through_b8_modules_and_tables_are_implemented(): void
    {
        foreach (array_keys(self::MODULE_DEPENDENCIES) as $module) {
            self::assertDirectoryExists(app_path("Modules/$module"));

            if (! in_array($module, ['Audit', 'Identity', 'Customers', 'Categories', 'ProjectIntake', 'Documents', 'Discovery', 'Proposals', 'Projects', 'AI', 'Notifications', 'Reporting'], true)) {
                self::assertSame([], $this->phpFiles(app_path("Modules/$module")), "$module implementation belongs to a later approved batch");
            }
        }

        self::assertSame([], $this->phpFiles(app_path('Models')), 'Business Eloquent models belong to a later batch');

        foreach ($this->phpFiles(database_path('migrations')) as $file) {
            $nodes = $this->parse($file);

            foreach ((new NodeFinder)->findInstanceOf($nodes, Expr\StaticCall::class) as $call) {
                if (! $call->class instanceof Name || ! $call->name instanceof Node\Identifier || $call->name->toString() !== 'create') {
                    continue;
                }

                if ($call->class->toString() !== 'Illuminate\\Support\\Facades\\Schema') {
                    continue;
                }

                $argument = $call->args[0] ?? null;
                self::assertInstanceOf(Node\Arg::class, $argument, $file);
                self::assertInstanceOf(String_::class, $argument->value, "Migration table names must be explicit ($file)");
                self::assertContains($argument->value->value, self::INFRASTRUCTURE_TABLES, "Migration is outside approved B1/B2/B3/B4/B5/B6/B7 ($file)");
            }

            foreach ((new NodeFinder)->findInstanceOf($nodes, String_::class) as $literal) {
                preg_match_all('/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:public\.)?"?([a-z_][a-z_0-9]*)/i', $literal->value, $tables);

                foreach ($tables[1] as $table) {
                    self::assertContains(strtolower($table), self::INFRASTRUCTURE_TABLES, "SQL is outside approved B1/B2/B3/B4/B5/B6/B7 ($file)");
                }
            }
        }
    }

    public function test_registered_routes_are_limited_to_approved_b1_through_b8(): void
    {
        $routes = app('router')->getRoutes();
        $actual = [];

        foreach ($routes as $route) {
            $actual[] = implode('|', $route->methods()).' '.$route->uri();
        }

        sort($actual);
        $expected = [
            'GET|HEAD api/v1/intake/categories', 'GET|HEAD api/v1/intake/categories/{category}/subcategories',
            'POST api/v1/guest/project-requests', 'POST api/v1/guest/project-requests/{projectRequest}/submissions',
            'POST api/v1/guest/project-requests/{projectRequest}/documents',
            'PUT api/v1/guest/project-requests/{projectRequest}/documents/{document}/content',
            'GET|HEAD api/v1/guest/project-requests/{projectRequest}/documents/{document}', 'POST api/v1/project-request-claims',
            'GET|HEAD api/v1/admin/customers',
            'GET|HEAD api/v1/admin/customers/{customer}',
            'GET|HEAD api/v1/admin/customers/{customer}/project-requests',
            'GET|HEAD api/v1/admin/customers/{customer}/projects',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/eligible-assignees',
            'GET|HEAD api/v1/admin/projects/{project}/eligible-staff',
            'GET|HEAD api/v1/admin/reports/dashboard', 'GET|HEAD api/v1/admin/reports/requests',
            'GET|HEAD api/v1/admin/reports/projects', 'GET|HEAD api/v1/admin/reports/customers',
            'GET|HEAD api/v1/admin/audit-events',
            'GET|HEAD api/v1/ai-runs', 'POST api/v1/ai-runs', 'GET|HEAD api/v1/ai-runs/{aiRun}',
            'POST api/v1/ai-runs/{aiRun}/applications', 'POST api/v1/ai-runs/{aiRun}/dismissals', 'POST api/v1/ai-runs/{aiRun}/cancellations',
            'GET|HEAD api/v1/notifications', 'GET|HEAD api/v1/notifications/unread-count', 'POST api/v1/notifications/read-all',
            'GET|HEAD api/v1/notifications/preferences', 'PATCH api/v1/notifications/preferences',
            'GET|HEAD api/v1/notifications/{notification}', 'POST api/v1/notifications/{notification}/read',
            'GET|HEAD api/v1/admin/notification-deliveries', 'GET|HEAD api/v1/admin/notification-deliveries/{delivery}',
            'POST api/v1/admin/notification-deliveries/{delivery}/replays',
            'GET|HEAD api/v1', 'GET|HEAD health/live', 'GET|HEAD health/ready', 'GET|HEAD sanctum/csrf-cookie',
            'POST api/v1/admin/project-requests/{projectRequest}/conversions',
            'GET|HEAD api/v1/admin/projects', 'GET|HEAD api/v1/projects',
            'GET|HEAD api/v1/admin/projects/{project}', 'GET|HEAD api/v1/projects/{project}',
            'GET|HEAD api/v1/admin/projects/{project}/milestones', 'GET|HEAD api/v1/projects/{project}/milestones',
            'GET|HEAD api/v1/admin/projects/{project}/updates', 'GET|HEAD api/v1/projects/{project}/updates',
            'GET|HEAD api/v1/admin/projects/{project}/documents', 'GET|HEAD api/v1/projects/{project}/documents',
            'GET|HEAD api/v1/admin/projects/{project}/documents/{document}', 'GET|HEAD api/v1/projects/{project}/documents/{document}',
            'GET|HEAD api/v1/admin/projects/{project}/documents/{document}/download', 'GET|HEAD api/v1/projects/{project}/documents/{document}/download',
            'POST api/v1/projects/{project}/completion-confirmations',
            'GET|HEAD api/v1/admin/projects/{project}/activity',
            'GET|HEAD api/v1/admin/projects/{project}/team-members',
            'POST api/v1/admin/projects/{project}/team-members',
            'DELETE api/v1/admin/projects/{project}/team-members/{member}',
            'GET|HEAD api/v1/admin/projects/{project}/evidence', 'POST api/v1/admin/projects/{project}/evidence',
            'POST api/v1/admin/projects/{project}/advances', 'POST api/v1/admin/projects/{project}/holds',
            'POST api/v1/admin/projects/{project}/resumptions', 'POST api/v1/admin/projects/{project}/failures',
            'POST api/v1/admin/projects/{project}/cancellations',
            'POST api/v1/admin/projects/{project}/milestones',
            'PATCH api/v1/admin/projects/{project}/milestones/{milestone}',
            'POST api/v1/admin/projects/{project}/milestones/{milestone}/starts',
            'POST api/v1/admin/projects/{project}/milestones/{milestone}/delays',
            'POST api/v1/admin/projects/{project}/milestones/{milestone}/completions',
            'POST api/v1/admin/projects/{project}/updates',
            'POST api/v1/admin/projects/{project}/documents', 'DELETE api/v1/admin/projects/{project}/documents/{document}',
            'PUT api/v1/admin/projects/{project}/documents/{document}/content',
            'POST api/v1/admin/projects/{project}/documents/{document}/scan-retries',
            'POST api/v1/auth/staff-invitations/lookup', 'POST api/v1/auth/staff-invitations/accept',
            'GET|HEAD api/v1/identity/capabilities', 'GET|HEAD api/v1/identity/staff',
            'GET|HEAD api/v1/identity/staff/invitations', 'POST api/v1/identity/staff/invitations',
            'POST api/v1/identity/staff/invitations/{invitation}/resends', 'POST api/v1/identity/staff/invitations/{invitation}/revocations',
            'GET|HEAD api/v1/identity/staff/{user}/authorization', 'PUT api/v1/identity/staff/{user}/authorization',
            'POST api/v1/auth/register', 'POST api/v1/auth/login', 'POST api/v1/auth/logout',
            'POST api/v1/auth/email/verify', 'POST api/v1/auth/email/resend', 'POST api/v1/auth/password/forgot',
            'POST api/v1/auth/password/reset', 'POST api/v1/auth/password/confirm', 'POST api/v1/auth/password/change',
            'POST api/v1/auth/mfa/enrollment', 'POST api/v1/auth/mfa/enrollment/confirm', 'POST api/v1/auth/mfa/challenge',
            'POST api/v1/auth/mfa/recovery', 'POST api/v1/auth/mfa/recovery-codes', 'DELETE api/v1/auth/mfa',
            'GET|HEAD api/v1/identity/me', 'PATCH api/v1/identity/me', 'GET|HEAD api/v1/identity/sessions',
            'POST api/v1/identity/sessions/revoke-others', 'GET|HEAD api/v1/customers',
            'GET|HEAD api/v1/customers/{customer}', 'PATCH api/v1/customers/{customer}',
            'GET|HEAD api/v1/identities/{identity}/customers/{customer}', 'PATCH api/v1/identities/{identity}/customers/{customer}',
            'GET|HEAD api/v1/identity/staff/{user}', 'PATCH api/v1/identity/staff/{user}/authorization',
            'GET|HEAD api/v1/categories',
            'GET|HEAD api/v1/categories/{category}/subcategories',
            'GET|HEAD api/v1/admin/categories',
            'GET|HEAD api/v1/admin/categories/{category}/subcategories',
            'POST api/v1/admin/categories',
            'POST api/v1/admin/categories/{category}/subcategories',
            'PATCH api/v1/admin/categories/{category}',
            'PATCH api/v1/admin/subcategories/{subcategory}',
            'GET|HEAD api/v1/project-requests',
            'POST api/v1/project-requests',
            'GET|HEAD api/v1/project-requests/by-reference/{reference}',
            'GET|HEAD api/v1/project-requests/{projectRequest}',
            'GET|HEAD api/v1/customers/{customer}/project-requests/{projectRequest}',
            'PATCH api/v1/project-requests/{projectRequest}/draft',
            'POST api/v1/project-requests/{projectRequest}/amendments',
            'POST api/v1/project-requests/{projectRequest}/submissions',
            'GET|HEAD api/v1/project-requests/{projectRequest}/revisions',
            'GET|HEAD api/v1/project-requests/{projectRequest}/revisions/{revision}',
            'GET|HEAD api/v1/project-requests/{projectRequest}/information-requests',
            'POST api/v1/project-requests/{projectRequest}/information-requests/{information}/responses',
            'POST api/v1/project-requests/{projectRequest}/withdrawals',
            'GET|HEAD api/v1/project-requests/{projectRequest}/history',
            'GET|HEAD api/v1/admin/project-requests',
            'GET|HEAD api/v1/admin/project-requests/by-reference/{reference}',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/revisions',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/revisions/{revision}',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/information-requests',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/history',
            'POST api/v1/admin/project-requests/{projectRequest}/assignments',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/assignments',
            'POST api/v1/admin/project-requests/{projectRequest}/reviews',
            'POST api/v1/admin/project-requests/{projectRequest}/information-requests',
            'POST api/v1/admin/project-requests/{projectRequest}/information-requests/{information}/acknowledgements',
            'POST api/v1/admin/project-requests/{projectRequest}/discovery-handoffs',
            'POST api/v1/admin/project-requests/{projectRequest}/rejections',
            'GET|HEAD api/v1/admin/categories/{category}',
            'GET|HEAD api/v1/admin/subcategories/{subcategory}',
            'POST api/v1/project-requests/{projectRequest}/documents',
            'GET|HEAD api/v1/project-requests/{projectRequest}/documents/{document}',
            'PUT api/v1/project-requests/{projectRequest}/documents/{document}/content',
            'GET|HEAD api/v1/project-requests/{projectRequest}/documents/{document}/download',
            'DELETE api/v1/project-requests/{projectRequest}/documents/{document}',
            'POST api/v1/project-requests/{projectRequest}/documents/{document}/scan-retries',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/documents/{document}',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/documents/{document}/download',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/discovery',
            'POST api/v1/admin/project-requests/{projectRequest}/discovery',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/discovery/{revision}',
            'PUT api/v1/admin/project-requests/{projectRequest}/discovery/{revision}',
            'PUT api/v1/admin/project-requests/{projectRequest}/discovery/{revision}/requirements',
            'POST api/v1/admin/project-requests/{projectRequest}/discovery/{revision}/starts',
            'POST api/v1/admin/project-requests/{projectRequest}/discovery/{revision}/completions',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/proposals',
            'POST api/v1/admin/project-requests/{projectRequest}/proposals',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}',
            'PUT api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}',
            'POST api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/approvals',
            'POST api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/issuances',
            'POST api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/supersessions',
            'POST api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/withdrawals',
            'POST api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/documents',
            'DELETE api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}',
            'GET|HEAD api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}/download',
            'GET|HEAD api/v1/project-requests/{projectRequest}/proposals',
            'GET|HEAD api/v1/project-requests/{projectRequest}/proposals/{proposal}',
            'POST api/v1/project-requests/{projectRequest}/proposals/{proposal}/acceptances',
            'POST api/v1/project-requests/{projectRequest}/proposals/{proposal}/declines',
            'POST api/v1/project-requests/{projectRequest}/proposals/{proposal}/rescissions',
            'GET|HEAD api/v1/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}',
            'GET|HEAD api/v1/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}/download',
        ];
        sort($expected);
        self::assertSame($expected, $actual);
    }

    public function test_identity_routes_retain_session_origin_and_csrf_controls(): void
    {
        $router = app('router');
        foreach ($router->getRoutes() as $route) {
            if (in_array($route->uri(), ['api/v1', 'health/live', 'health/ready'], true)) {
                self::assertSame(['GET', 'HEAD'], $route->methods());

                continue;
            }
            $middleware = $router->gatherRouteMiddleware($route);
            self::assertContains(ExactOrigin::class, $middleware);
            if (in_array($route->uri(), ['api/v1/intake/categories', 'api/v1/intake/categories/{category}/subcategories'], true)) {
                self::assertSame(['GET', 'HEAD'], $route->methods());
                self::assertContains(GuestIntakeThrottle::class, $middleware);
                self::assertNotContains(StartSecureSession::class, $middleware);

                continue;
            }
            self::assertContains(StrictCsrf::class, $middleware);
            self::assertContains(StartSecureSession::class, $middleware);
            if (str_contains($route->uri(), '/customers') || str_contains($route->uri(), '/identity/')
                || str_starts_with($route->uri(), 'api/v1/admin/') || str_starts_with($route->uri(), 'api/v1/categories')
                || str_starts_with($route->uri(), 'api/v1/project-request-claims') || str_starts_with($route->uri(), 'api/v1/project-requests') || str_starts_with($route->uri(), 'api/v1/projects') || str_starts_with($route->uri(), 'api/v1/documents')
                || str_starts_with($route->uri(), 'api/v1/ai-runs') || str_starts_with($route->uri(), 'api/v1/notifications')) {
                self::assertContains(SessionAuthenticated::class, $middleware);
            }
        }
        self::assertSame('database', config('session.driver'));
        self::assertSame('__Host-holoul_session', config('session.cookie'));
        self::assertTrue(config('session.encrypt'));
        self::assertTrue(config('session.secure'));
        self::assertTrue(config('session.http_only'));
        self::assertSame('lax', config('session.same_site'));
        self::assertNull(config('session.domain'));
        self::assertSame('/', config('session.path'));
        self::assertNotContains(HasApiTokens::class, class_uses_recursive(User::class));
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<Stmt> */
    private function parse(string $file): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($this->contents($file));
        self::assertNotNull($nodes, $file);
        $traverser = new NodeTraverser(new NameResolver);

        return $traverser->traverse($nodes);
    }

    /**
     * @param  list<Stmt>  $nodes
     * @return list<string>
     */
    private function names(array $nodes): array
    {
        return array_values(array_unique(array_map(
            static fn (Name $name): string => $name->toString(),
            (new NodeFinder)->findInstanceOf($nodes, Name::class),
        )));
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, $path);

        return $contents;
    }
}
