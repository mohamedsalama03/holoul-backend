<?php

declare(strict_types=1);

use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\Projects\Actions\ProjectLifecycle;
use App\Modules\Projects\Actions\ProjectStore;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Explicitly local, temporary production-image acceptance fixtures only.
try {
    if (PHP_SAPI !== 'cli' || getenv('HOLOUL_PROJECTS_SMOKE') !== '1') {
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
    $input = stream_get_contents(STDIN, 65537);
    if (! is_string($input) || strlen($input) > 65536) {
        throw new RuntimeException;
    }
    $manifest = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || ! is_string($manifest['run_id'] ?? null) || preg_match('/\A[a-f0-9]{24}\z/D', $manifest['run_id']) !== 1
        || ! is_array($manifest['accounts'] ?? null) || array_keys($manifest['accounts']) !== ['customer_a', 'customer_b', 'admin', 'manager']) {
        throw new RuntimeException;
    }
    $ids = [];
    foreach ($manifest['accounts'] as $label => $row) {
        $email = 'b3-smoke-'.$manifest['run_id'].'-'.str_replace('_', '-', $label).'@example.test';
        if (! is_array($row) || ! is_string($row['id'] ?? null) || ! Str::isUuid($row['id'], 7) || ($row['email'] ?? null) !== $email) {
            throw new RuntimeException;
        }
        $user = User::query()->whereKey($row['id'])->where('email', $email)->where('created_at', '>', now()->subDay())->firstOrFail();
        if ($user->kind !== (str_starts_with($label, 'customer_') ? 'customer' : 'staff')
            || DB::table('customers')->where('user_id', $user->id)->value('id') !== ($row['customer_id'] ?? null)) {
            throw new RuntimeException;
        }
        $ids[] = $user->id;
    }
    $requests = ProjectRequest::query()->whereIn('customer_user_id', $ids)->get();
    if ($requests->count() > 10) {
        throw new RuntimeException;
    }
    foreach ($requests as $request) {
        $name = DB::table('request_drafts')->where('request_id', $request->id)->value('project_name');
        if (! is_string($name) || ! str_starts_with($name, 'b3-smoke-'.$manifest['run_id'].' ') || $request->created_at->isBefore(now()->subDay())) {
            throw new RuntimeException;
        }
    }
    $requestIds = $requests->modelKeys();
    $projects = Project::query()->whereIn('source_request_id', $requestIds)->get();
    foreach ($projects as $project) {
        if (! in_array($project->created_by, $ids, true) || $project->created_at->isBefore(now()->subDay())) {
            throw new RuntimeException;
        }
    }
    $projectIds = $projects->modelKeys();
    $mode = $argv[1] ?? '';
    if ($mode === 'cleanup') {
        foreach ($projects as $candidate) {
            if (in_array($candidate->state, ['completed', 'cancelled'], true)) {
                continue;
            }
            DB::transaction(function () use ($candidate): void {
                app(RoleAuthority::class)->lockChanges();
                $user = User::query()->whereKey($candidate->created_by)->where('enabled', true)->lockForUpdate()->firstOrFail();
                $authority = app(RoleAuthority::class);
                $actor = new ProjectActor($user->id, null, $user->email_verified_at !== null, true, $authority->permissionsFor($authority->roles($user->id)));
                $project = app(ProjectStore::class)->find($actor, $candidate->id, true);
                if (! in_array($project->state, ['completed', 'cancelled'], true)) {
                    app(ProjectLifecycle::class)->cancel($actor, $project, ['reason' => 'Synthetic B6 verification cleanup.',
                        'customer_communication' => 'Synthetic acceptance fixture is closed.'], (string) Str::uuid7());
                }
            });
        }
        $active = Project::query()->whereIn('id', $projectIds)->whereNotIn('state', ['completed', 'cancelled'])->count();
        $states = Project::query()->whereIn('id', $projectIds)->orderBy('id')->pluck('state')->all();
        echo json_encode(['ok' => $active === 0, 'event' => 'verification.projects_cleanup', 'active_projects' => $active,
            'retained_projects' => count($projectIds), 'retained_project_states' => $states,
            'retained_acceptances' => DB::table('proposal_decisions')->whereIn('request_id', $requestIds)->where('decision', 'accepted')->count(),
            'retained_state_changes' => DB::table('project_state_changes')->whereIn('project_id', $projectIds)->count()], JSON_THROW_ON_ERROR)."\n";
        exit($active === 0 ? 0 : 1);
    }
    if ($mode !== 'evidence' || count($projectIds) !== 1 || count($requestIds) !== 1) {
        throw new RuntimeException;
    }
    $project = $projects->firstOrFail();
    $proposal = DB::table('proposals')->where('id', $project->accepted_proposal_id)->first();
    $decision = DB::table('proposal_decisions')->where('id', $project->accepted_decision_id)->first();
    $states = DB::table('project_state_changes')->where('project_id', $project->id)->whereNotNull('from_state')->orderBy('entity_version')->pluck('to_state')->all();
    $expectedStates = ['design', 'development', 'on_hold', 'development', 'testing', 'development', 'testing', 'deployment', 'testing', 'deployment', 'completed'];
    $documents = DB::table('project_documents as reference')->join('documents as document', 'document.id', '=', 'reference.document_id')
        ->where('reference.project_id', $project->id)->where('document.state', 'available')->count();
    $confirmation = DB::table('project_completion_confirmations as confirmation')
        ->join('project_phase_evidence as evidence', 'evidence.id', '=', 'confirmation.deployment_evidence_id')
        ->where('confirmation.project_id', $project->id)->where('confirmation.confirmed_by', $project->customer_user_id)
        ->where('evidence.kind', 'deployment_succeeded')->whereColumn('confirmation.phase_epoch', 'evidence.phase_epoch')->count();
    $members = DB::table('project_members')->where('project_id', $project->id)->where('active', true)->count();
    $milestones = DB::table('milestones')->where('project_id', $project->id)->where('state', 'completed')->count();
    $published = DB::table('project_updates')->where('project_id', $project->id)->where('content', 'Synthetic customer-visible delivery update.')->count();
    $baseline = $proposal !== null && $decision !== null && $proposal->state === 'accepted' && $decision->decision === 'accepted'
        && $proposal->request_id === $project->source_request_id && $proposal->customer_id === $project->customer_id
        && $decision->proposal_id === $proposal->id && $decision->customer_id === $project->customer_id
        && $decision->proposal_version === $project->accepted_proposal_version
        && is_string($proposal->number) && preg_match('/\APROP-[0-9]{4}-[0-9]{5,19}\z/D', $proposal->number) === 1
        && $proposal->amount_minor === 30369 && $proposal->currency === 'LYD'
        && $proposal->scope_summary === 'Synthetic immutable accepted delivery scope.'
        && $proposal->timeline === 'Six weeks after agreed start.'
        && DB::table('proposal_deliverables')->where('proposal_id', $proposal->id)->orderBy('position')->pluck('description')->all() === ['Portal', 'Handover']
        && DB::table('proposal_documents')->where('proposal_id', $proposal->id)->count() === 1;
    $ok = $project->state === 'completed' && $requests->firstOrFail()->state->value === 'converted'
        && $baseline && $states === $expectedStates && $documents === 2 && $confirmation === 1 && $members === 2
        && $milestones === 2 && $published === 1;
    echo json_encode(['ok' => $ok, 'request_state' => $requests->firstOrFail()->state->value, 'project_state' => $project->state,
        'immutable_accepted_baseline' => $baseline, 'lifecycle_states' => $states, 'customer_completion_confirmations' => $confirmation,
        'available_private_project_documents' => $documents, 'active_internal_members' => $members,
        'completed_milestones' => $milestones, 'explicit_published_updates' => $published,
        'retained_activity_events' => DB::table('project_activity')->where('project_id', $project->id)->count()], JSON_THROW_ON_ERROR)."\n";
    exit($ok ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, "{\"event\":\"verification.projects_fixture_failed\"}\n");
    exit(1);
}
