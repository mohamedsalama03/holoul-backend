<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Read-only evidence for explicitly opted-in local production-image fixtures.
try {
    if (PHP_SAPI !== 'cli' || getenv('HOLOUL_ASSISTANCE_SMOKE') !== '1' || ($argv[1] ?? '') !== 'evidence') {
        throw new RuntimeException;
    }
    require __DIR__.'/../vendor/autoload.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('production') || Config::boolean('app.debug') || is_file(__DIR__.'/../vendor/bin/phpunit')
        || ! Config::boolean('identity.mail_sandbox') || Config::string('mail.mailers.smtp.host') !== 'mailpit'
        || ! Config::boolean('ai.enabled') || Config::string('ai.driver') !== 'sandbox'
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
        $user = User::query()->whereKey($row['id'])->where('email', $email)->where('enabled', true)->where('created_at', '>', now()->subDay())->firstOrFail();
        if ($user->kind !== (str_starts_with($label, 'customer_') ? 'customer' : 'staff')
            || DB::table('customers')->where('user_id', $user->id)->value('id') !== ($row['customer_id'] ?? null)) {
            throw new RuntimeException;
        }
        $ids[$label] = $user->id;
    }
    $requests = DB::table('project_requests')->whereIn('customer_user_id', array_values($ids))->get();
    if ($requests->count() !== 2) {
        throw new RuntimeException;
    }
    $drafts = [];
    $names = [];
    foreach ($requests as $request) {
        $draft = DB::table('request_drafts')->where('request_id', $request->id)->firstOrFail();
        if ($request->customer_user_id !== $ids['customer_a'] || $request->state !== 'draft'
            || ! in_array($draft->project_name, ['b3-smoke-'.$manifest['run_id'].' assistance', 'b3-smoke-'.$manifest['run_id'].' assistance-docx'], true)
            || $draft->project_description !== 'Synthetic B7 later human edit supersedes the older AI source.'
            || ! is_string($request->created_at) || now()->parse($request->created_at)->isBefore(now()->subDay())) {
            throw new RuntimeException;
        }
        $drafts[$request->id] = $draft->id;
        $names[] = $draft->project_name;
    }
    if (count(array_unique($names)) !== 2) {
        throw new RuntimeException;
    }
    $runs = DB::table('ai_runs')->whereIn('actor_id', array_values($ids))->get();
    if ($runs->count() !== 4) {
        throw new RuntimeException;
    }
    foreach ($runs as $run) {
        if ($run->actor_id !== $ids['customer_a'] || $run->parent_type !== 'request' || ! isset($drafts[$run->parent_id])
            || $run->source_type !== 'intake_draft' || $run->source_id !== $drafts[$run->parent_id] || $run->state !== 'succeeded'
            || $run->provider !== 'sandbox' || $run->model !== 'sandbox-v1' || $run->reservation_state !== 'settled'
            || $run->actual_cost_microusd !== 0 || $run->input_tokens < 1 || $run->output_tokens < 1
            || $run->provider_operation_id !== 'sandbox:'.$run->id || $run->consent_version !== 'ai-consent-v1') {
            throw new RuntimeException;
        }
        if ($run->document_id !== null) {
            $document = DB::table('documents')->where('id', $run->document_id)->firstOrFail();
            if ($run->source_text !== '' || $document->state !== 'available' || $document->parent_id !== $run->parent_id
                || $run->document_checksum !== $document->expected_sha256 || $run->document_object_version !== $document->storage_version) {
                throw new RuntimeException;
            }
        }
    }
    $runIds = $runs->pluck('id')->all();
    $notices = DB::table('notifications')->where('recipient_id', $ids['customer_a'])->where('resource_type', 'ai_run')->whereIn('resource_id', $runIds)->get();
    $noticeIds = $notices->pluck('id')->all();
    // Wait only for the identifier-only email worker to settle its durable rows;
    // this evidence command never delivers, reconciles or mutates work itself.
    $deadline = microtime(true) + 30;
    do {
        $deliveries = DB::table('notification_deliveries')->whereIn('notification_id', $noticeIds)->get();
        if (! $deliveries->contains(fn (object $row): bool => in_array($row->state, ['pending', 'processing'], true))) {
            break;
        }
        usleep(250000);
    } while (microtime(true) < $deadline);
    $operationIds = $runs->pluck('operation_id')->all();
    $suggestions = DB::table('ai_suggestions')->whereIn('run_id', $runIds)->get();
    $attempts = DB::table('ai_provider_attempts')->whereIn('run_id', $runIds)->get();
    $formats = DB::table('documents')->whereIn('id', $runs->pluck('document_id')->filter()->all())->orderBy('format')->pluck('format')->all();
    $accepted = $deliveries->where('state', 'accepted')->count();
    $suppressed = $deliveries->where('state', 'suppressed')->where('failure_code', 'preference_disabled')->count();
    $ok = $suggestions->count() === 4 && $suggestions->where('state', 'applied')->count() === 1 && $suggestions->where('state', 'pending')->count() === 3
        && $attempts->count() === 4 && $attempts->where('outcome', 'succeeded')->count() === 4
        && DB::table('async_operations')->whereIn('id', $operationIds)->where('state', 'succeeded')->count() === 4
        && $formats === ['docx', 'pdf'] && $notices->count() === 4 && $notices->where('type', 'ai.suggestion_ready')->count() === 4
        && $notices->whereNull('read_at')->count() === 0 && $accepted === 1 && $suppressed === 3
        && DB::table('notification_delivery_attempts')->whereIn('delivery_id', $deliveries->pluck('id')->all())->where('state', 'accepted')->count() === 1
        && DB::table('notification_preferences')->where('user_id', $ids['customer_a'])->value('workflow_email') === false;
    echo json_encode(['ok' => $ok, 'queued_ai_runs' => $runs->count(), 'provider_attempts' => $attempts->count(),
        'settled_zero_cost_reservations' => $runs->where('reservation_state', 'settled')->count(), 'explicit_human_applications' => $suggestions->where('state', 'applied')->count(),
        'exact_available_document_formats' => $formats, 'private_source_and_versions_verified' => true,
        'in_app_notifications' => $notices->count(), 'smtp_accepted' => $accepted, 'preference_suppressed' => $suppressed,
        'unread_notifications' => $notices->whereNull('read_at')->count()], JSON_THROW_ON_ERROR)."\n";
    exit($ok ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, "{\"event\":\"verification.assistance_fixture_failed\"}\n");
    exit(1);
}
