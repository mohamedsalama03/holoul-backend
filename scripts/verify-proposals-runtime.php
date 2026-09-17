<?php

declare(strict_types=1);

use App\Application\Commercial\WithdrawCommercialRequest;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

try {
    if (PHP_SAPI !== 'cli' || getenv('HOLOUL_PROPOSALS_SMOKE') !== '1') {
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
        if (DB::table('customers')->where('user_id', $user->id)->value('id') !== ($row['customer_id'] ?? null)) {
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
    $mode = $argv[1] ?? '';
    if ($mode === 'cleanup') {
        foreach ($requests as $candidate) {
            DB::transaction(function () use ($candidate): void {
                $user = User::query()->whereKey($candidate->customer_user_id)->lockForUpdate()->firstOrFail();
                $request = ProjectRequest::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if (! in_array($request->state, [RequestState::Proposal, RequestState::Approved], true)) {
                    return;
                }
                $authority = app(RoleAuthority::class);
                $actor = new IntakeActor($user->id, $request->customer_id, $user->email_verified_at !== null, $authority->permissionsFor($authority->roles($user->id)));
                app(WithdrawCommercialRequest::class)->handle($actor, $request, true, 'Synthetic B5 verification completed.', (string) Str::uuid7());
            });
        }
        echo json_encode(['ok' => true, 'event' => 'verification.proposals_cleanup', 'active_proposals' => DB::table('proposals')->whereIn('request_id', $requestIds)->whereIn('state', ['issued', 'accepted'])->count(),
            'retained_decisions' => DB::table('proposal_decisions')->whereIn('request_id', $requestIds)->count()], JSON_THROW_ON_ERROR)."\n";
    } elseif ($mode === 'evidence') {
        $states = DB::table('proposals')->whereIn('request_id', $requestIds)->orderBy('revision_number')->pluck('state')->all();
        $revisions = DB::table('discovery_revisions')->whereIn('request_id', $requestIds)->where('state', 'completed')->count();
        $accepted = DB::table('proposal_decisions')->whereIn('request_id', $requestIds)->where('decision', 'accepted')->count();
        $attachments = DB::table('proposal_documents')->whereIn('request_id', $requestIds)->count();
        $ok = $states === ['superseded', 'accepted'] && $revisions === 2 && $accepted === 1 && $attachments === 2
            && ProjectRequest::query()->whereIn('id', $requestIds)->where('state', 'approved')->count() === 1;
        echo json_encode(['ok' => $ok, 'proposal_states' => $states, 'completed_discovery_revisions' => $revisions, 'customer_acceptances' => $accepted, 'private_document_references' => $attachments], JSON_THROW_ON_ERROR)."\n";
        exit($ok ? 0 : 1);
    } else {
        throw new RuntimeException;
    }
} catch (Throwable) {
    fwrite(STDERR, "{\"event\":\"verification.proposals_fixture_failed\"}\n");
    exit(1);
}
