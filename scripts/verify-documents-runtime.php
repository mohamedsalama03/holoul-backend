<?php

declare(strict_types=1);

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\IntakeDocumentReferences;
use App\Modules\ProjectIntake\Actions\IntakeDocuments;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Only the explicitly enabled local production-image sandbox may inspect or
// clean these synthetic documents. The B3 helper owns account quarantine.
try {
    if (PHP_SAPI !== 'cli' || getenv('HOLOUL_DOCUMENTS_SMOKE') !== '1' || getenv('HOLOUL_INTAKE_SMOKE') !== '1') {
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
    if (! in_array($mode, ['evidence', 'cleanup'], true)) {
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
    $userIds = [];
    foreach ($manifest['accounts'] as $label => $record) {
        $email = 'b3-smoke-'.$run.'-'.str_replace('_', '-', $label).'@example.test';
        if (! is_array($record) || ! is_string($record['id'] ?? null) || ! Str::isUuid($record['id'], 7)
            || ($record['email'] ?? null) !== $email || array_key_exists('password', $record)) {
            throw new RuntimeException;
        }
        $kind = str_starts_with($label, 'customer_') ? 'customer' : 'staff';
        $user = User::query()->whereKey($record['id'])->where('email', $email)->where('kind', $kind)
            ->where('created_at', '>', now()->subDay())->firstOrFail();
        $customerId = DB::table('customers')->where('user_id', $user->id)->value('id');
        if (($record['customer_id'] ?? null) !== $customerId) {
            throw new RuntimeException;
        }
        $userIds[] = $user->id;
    }
    $requests = ProjectRequest::query()->whereIn('customer_user_id', $userIds)->get();
    if ($requests->count() > 10) {
        throw new RuntimeException;
    }
    foreach ($requests as $request) {
        $name = DB::table('request_drafts')->where('request_id', $request->id)->value('project_name');
        if ($request->created_at->isBefore(now()->subDay()) || ! is_string($name)
            || ! str_starts_with($name, 'b3-smoke-'.$run.' ')) {
            throw new RuntimeException;
        }
    }
    $requestIds = $requests->modelKeys();
    $documents = Document::query()->whereIn('customer_user_id', $userIds)->get();
    if ($documents->count() > 20) {
        throw new RuntimeException;
    }
    foreach ($documents as $document) {
        if (! in_array($document->parent_id, $requestIds, true) || ! in_array($document->uploader_id, $userIds, true)
            || $document->created_at->isBefore(now()->subDay())
            || ! str_starts_with($document->display_name, 'b4-smoke-'.$run.'-')) {
            throw new RuntimeException;
        }
    }
    $ids = $documents->modelKeys();
    if ($mode === 'evidence') {
        $available = Document::query()->whereIn('id', $ids)->where('state', 'available')->count();
        $malware = Document::query()->whereIn('id', $ids)->where('state', 'rejected')->where('failure_code', 'malware_detected')->count();
        $scans = DB::table('async_operations')->whereIn('id', $documents->pluck('scan_operation_id'))
            ->where('kind', 'documents.scan')->where('state', 'succeeded')->count();
        $attachments = DB::table('intake_revision_documents')->whereIn('document_id', $ids)->count();
        $downloads = DB::table('audit_events')->whereIn('subject_id', $ids)->where('event_type', 'documents.download_authorized')->count();
        $ok = count($ids) === 3 && $available === 2 && $malware === 1 && $scans === 3 && $attachments === 2 && $downloads >= 4;
        fwrite(STDOUT, json_encode(['event' => 'verification.documents_evidence', 'ok' => $ok,
            'available_documents' => $available, 'scanner_malware_rejections' => $malware,
            'completed_scan_operations' => $scans, 'retained_revision_attachments' => $attachments,
            'audited_download_authorizations' => $downloads], JSON_THROW_ON_ERROR)."\n");
        exit($ok ? 0 : 1);
    }
    $ok = true;
    foreach ($documents as $candidate) {
        try {
            DB::transaction(function () use ($candidate): void {
                // Preserve the HTTP mutation lock order and use the same owned
                // actions. Never disable a trigger or delete retained history.
                $user = User::query()->whereKey($candidate->customer_user_id)->lockForUpdate()->firstOrFail();
                $request = ProjectRequest::query()->whereKey($candidate->parent_id)->lockForUpdate()->firstOrFail();
                $document = Document::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $actor = new IntakeActor($user->id, $request->customer_id, $user->email_verified_at !== null);
                $requestId = (string) Str::uuid7();
                $attachments = app(IntakeDocuments::class);
                $draft = DB::table('intake_draft_documents')->join('request_drafts', 'request_drafts.id', '=', 'intake_draft_documents.draft_id')
                    ->where('intake_draft_documents.document_id', $document->id)->where('request_drafts.is_open', true)->exists();
                if ($draft) {
                    $attachments->remove($request, $actor, $document->id,
                        VersionPrecondition::etag($request->id, $request->lock_version), $requestId);
                } elseif (! app(IntakeDocumentReferences::class)->hasAny($document->id)) {
                    app(DocumentService::class)->requestDeletion($attachments->owner($request, $actor, $requestId), $document->id);
                }
            });
        } catch (Throwable) {
            $ok = false;
        }
    }
    // Worker deletion is observable and bounded; this helper never deletes S3
    // bytes itself or changes a queued operation's state to manufacture success.
    $deadline = microtime(true) + 90;
    do {
        $pending = Document::query()->whereIn('id', $ids)->whereNotIn('state', ['deleted'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('intake_draft_documents')->whereColumn('document_id', 'documents.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('intake_revision_documents')->whereColumn('document_id', 'documents.id'))->count();
        if ($pending === 0 || microtime(true) >= $deadline) {
            break;
        }
        sleep(1);
    } while (true);
    $retained = DB::table('intake_revision_documents')->whereIn('document_id', $ids)->count();
    $deleted = Document::query()->whereIn('id', $ids)->where('state', 'deleted')->count();
    $ok = $ok && $pending === 0;
    fwrite(STDOUT, json_encode(['event' => 'verification.documents_cleanup', 'ok' => $ok,
        'deleted_unreferenced_documents' => $deleted, 'pending_unreferenced_documents' => $pending,
        'retained_revision_attachments' => $retained, 'retained_document_tombstones' => count($ids)], JSON_THROW_ON_ERROR)."\n");
    exit($ok ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, "{\"event\":\"verification.documents_fixture_failed\"}\n");
    exit(1);
}
