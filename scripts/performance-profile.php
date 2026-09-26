<?php

declare(strict_types=1);

// Included only after performance-fixture.php validates the local synthetic run.
use App\Application\Commercial\CommercialWorkflow;
use App\Modules\Audit\Queries\InvestigateAudit;
use App\Modules\Identity\Actions\ReadActiveIdentity;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Actions\NotificationAccess;
use App\Modules\ProjectIntake\Queries\ReadIntake;
use App\Modules\Projects\Actions\ProjectDocuments;
use App\Modules\Projects\Actions\ProjectRead;
use App\Modules\Projects\Actions\ProjectStore;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Reporting\Actions\OperationalReports;
use App\Modules\Reporting\Data\ReportWindow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$factory = new B8PerformanceFixture;
[$customer, $projectActor] = $factory->actors(User::query()->findOrFail($manifest['accounts']['customer_000']['id']));
[$staff, $staffProjectActor] = $factory->actors(User::query()->findOrFail($manifest['accounts']['admin']['id']));
$identity = DB::transaction(fn () => app(ReadActiveIdentity::class)->locked($customer->id));
$project = app(ProjectStore::class)->find($staffProjectActor, $manifest['records']['customer_000']['projects'][0], false);
$request = $manifest['records']['customer_000']['requests'][0];
$reference = DB::table('project_requests')->where('id', $request)->value('reference');
$window = ReportWindow::fromInput([]);
$capturing = false;
$captured = [];
DB::listen(function (QueryExecuted $query) use (&$capturing, &$captured): void {
    if ($capturing) {
        $captured[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'ms' => $query->time];
    }
});
function b8Plan(array $node): array
{
    // Never export expressions, literal bindings, document text or identity IDs.
    $safe = array_intersect_key($node, array_flip(['Node Type', 'Relation Name', 'Index Name', 'Actual Rows',
        'Actual Loops', 'Actual Total Time', 'Rows Removed by Filter', 'Shared Hit Blocks', 'Shared Read Blocks', 'Plan Rows']));
    if (isset($node['Plans'])) {
        $safe['Plans'] = array_map(b8Plan(...), $node['Plans']);
    }

    return $safe;
}
function b8Profile(string $name, Closure $read): array
{
    global $capturing, $captured;
    $captured = [];
    $capturing = true;
    $start = hrtime(true);
    $result = $read();
    $milliseconds = (hrtime(true) - $start) / 1e6;
    $capturing = false;
    $queries = $captured;
    $plans = [];
    foreach ($queries as $query) {
        if (! preg_match('/\ASELECT\b/i', ltrim($query['sql']))) {
            continue;
        }
        $hash = hash('sha256', $query['sql']);
        if (isset($plans[$hash])) {
            $plans[$hash]['occurrences']++;

            continue;
        }
        $plan = json_decode(DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query['sql'], $query['bindings'])->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
        $plans[$hash] = ['occurrences' => 1, 'observed_ms' => $query['ms'], 'planning_ms' => $plan['Planning Time'],
            'execution_ms' => $plan['Execution Time'], 'plan' => b8Plan($plan['Plan'])];
    }

    return ['workflow' => $name, 'elapsed_ms' => round($milliseconds, 3), 'query_count' => count($queries),
        'database_ms' => round(array_sum(array_column($queries, 'ms')), 3), 'plans' => $plans,
        'page_rows' => isset($result['data']) && is_array($result['data']) ? count($result['data']) : null];
}
$profiles = [];
foreach ([1, 25, 100] as $limit) {
    foreach (['customer' => $customer, 'admin' => $staff] as $label => $actor) {
        $profiles[] = b8Profile($label.'_requests_'.$limit, fn () => app(ReadIntake::class)->listing($actor, ['limit' => $limit]));
    }
    $profiles[] = b8Profile('projects_'.$limit, fn () => app(ProjectRead::class)->listing($staffProjectActor, null, $limit));
    $profiles[] = b8Profile('proposals_'.$limit, fn () => app(CommercialWorkflow::class)->handle($staff, true, $request, '',
        'proposal.list', null, null, ['limit' => $limit], (string) Str::uuid7()));
    $profiles[] = b8Profile('documents_'.$limit, fn () => DB::transaction(function () use ($staff, $project, $limit) {
        $identity = app(ReadActiveIdentity::class)->locked($staff->id);
        $current = new ProjectActor($identity->id, null, $identity->verifiedEmail, false, $identity->permissions);
        $parent = app(ProjectStore::class)->find($current, $project->id);

        return app(ProjectDocuments::class)->listing($parent, $current, (string) Str::uuid7(), 1, $limit);
    }));
    $profiles[] = b8Profile('notifications_'.$limit, fn () => app(NotificationAccess::class)->handle($identity, 'list', '', ['limit' => $limit], null, null, (string) Str::uuid7()));
    $profiles[] = b8Profile('audit_'.$limit, fn () => app(InvestigateAudit::class)->read($window->from, $window->until, [], $limit));
}
$profiles[] = b8Profile('request_search', fn () => app(ReadIntake::class)->listing($staff, ['q' => 'portal', 'limit' => 25]));
$profiles[] = b8Profile('request_reference', fn () => ['id' => app(ReadIntake::class)->byReference($staff, $reference)]);
$page = app(ReadIntake::class)->listing($staff, ['limit' => 25]);
$profiles[] = b8Profile('requests_cursor_second_page', fn () => app(ReadIntake::class)->listing($staff, ['cursor' => $page['meta']['next_cursor'], 'limit' => 25]));
$page = app(InvestigateAudit::class)->read($window->from, $window->until, [], 25);
$profiles[] = b8Profile('audit_cursor_second_page', fn () => app(InvestigateAudit::class)->read($window->from, $window->until, [], 25, $page['meta']['next_cursor']));
foreach (['dashboard', 'requests', 'projects', 'customers'] as $report) {
    $profiles[] = b8Profile('report_'.$report, fn () => app(OperationalReports::class)->read($report, $window));
}
$indexes = DB::select('SELECT relname AS table_name,indexrelname AS index_name,idx_scan,pg_relation_size(indexrelid) AS bytes
    FROM pg_stat_user_indexes ORDER BY relname,indexrelname');
$duplicates = DB::select("SELECT a.relname AS first,b.relname AS second FROM pg_index x JOIN pg_index y ON x.indrelid=y.indrelid
    AND x.indexrelid<y.indexrelid AND x.indkey=y.indkey AND x.indclass=y.indclass AND x.indoption=y.indoption
    AND coalesce(x.indexprs::text,'')=coalesce(y.indexprs::text,'') AND coalesce(x.indpred::text,'')=coalesce(y.indpred::text,'')
    JOIN pg_class a ON a.oid=x.indexrelid JOIN pg_class b ON b.oid=y.indexrelid JOIN pg_namespace n ON n.oid=a.relnamespace WHERE n.nspname='public'");
fwrite(STDOUT, json_encode(['profiles' => $profiles, 'indexes' => $indexes, 'duplicate_index_candidates' => $duplicates,
    'database_bytes' => DB::scalar('SELECT pg_database_size(current_database())'),
    'connections' => DB::selectOne("SELECT count(*) AS total,count(*) FILTER(WHERE state='active') AS active FROM pg_stat_activity WHERE datname=current_database()"),
    'limitations' => 'Warm local PostgreSQL plans; no SQL text or bindings exported. CLI owner query timings exclude HTTP authentication and network.'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
