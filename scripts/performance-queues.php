<?php

declare(strict_types=1);

use App\Application\AI\AISources;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\AI\Actions\AIRuns;
use App\Modules\Documents\Actions\StoreUpload;
use App\Modules\Identity\Actions\ReadActiveIdentity;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Recovery\RecoveryActions;
use App\Modules\Notifications\Contracts\NotificationRecorder;
use App\Modules\Projects\Actions\ProjectDocuments;
use App\Modules\Projects\Actions\ProjectStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function b8CapacityPdf(): string
{
    $stream = "BT /F1 12 Tf 10 70 Td (Synthetic capacity document.) Tj ET\n";
    $kids = implode(' ', array_map(fn ($i) => ($i + 5).' 0 R', range(0, 499)));
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids ['.$kids.'] /Count 500 >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream'];
    for ($i = 0; $i < 500; $i++) {
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 150] /Resources << /Font << /F1 3 0 R >> >> /Contents 4 0 R >>';
    }
    $pdf = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
    $offsets = [];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 ".$count."\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf.'trailer'."\n<< /Size ".$count." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}

if ($mode === 'queue-recover-seed') {
    $correlation = $manifest['queue_probe']['correlation'] ?? '';
    if (! Str::isUuid($correlation, 7)) {
        throw new RuntimeException('Invalid recovery probe.');
    }
    // B2 retains uncertain recovery mail and requires a NEW reset request.
    // Never resend the original possibly accepted security token.
    app(RecoveryActions::class)->forgotPassword($manifest['accounts']['customer_149']['email'], $correlation);
    fwrite(STDOUT, json_encode($manifest, JSON_THROW_ON_ERROR));
} elseif ($mode === 'queue-retry-seed') {
    $correlation = (string) Str::uuid7();
    $account = $manifest['accounts']['customer_149'];
    app(RecoveryActions::class)->forgotPassword($account['email'], $correlation);
    DB::transaction(fn () => app(NotificationRecorder::class)->record($account['id'], 'request.submitted', 'project_request',
        $manifest['records']['customer_149']['requests'][0], 'b8-retry-'.$correlation, $correlation));
    $manifest['queue_probe'] = ['correlation' => $correlation, 'documents' => [], 'document_pages' => 0, 'document_bytes' => 0];
    fwrite(STDOUT, json_encode($manifest, JSON_THROW_ON_ERROR));
} elseif ($mode === 'queue-seed') {
    $correlation = (string) Str::uuid7();
    $factory = new B8PerformanceFixture;
    [, $staff] = $factory->actors(User::query()->findOrFail($manifest['accounts']['admin']['id']));
    $projectId = $manifest['records']['customer_000']['projects'][0];
    $pdf = b8CapacityPdf();
    $documentIds = [];
    // Real objects, uploads, finalization, scanner/parser operations. Containers
    // for heavy queues are paused by the wrapper before this call.
    for ($i = 0; $i < 25; $i++) {
        $reservation = DB::transaction(function () use ($staff, $projectId, $pdf, $correlation) {
            $project = app(ProjectStore::class)->find($staff, $projectId);

            return app(ProjectDocuments::class)->reserve($project, $staff, VersionPrecondition::etag($project->id, $project->lock_version),
                'synthetic-capacity.pdf', strlen($pdf), hash('sha256', $pdf), 'customer', (string) Str::uuid7(), $correlation);
        });
        $stream = tmpfile();
        fwrite($stream, $pdf);
        rewind($stream);
        try {
            $object = app(StoreUpload::class)->handle($reservation, $stream);
        } finally {
            fclose($stream);
        }
        DB::transaction(function () use ($staff, $projectId, $reservation, $object, $correlation): void {
            $project = app(ProjectStore::class)->find($staff, $projectId);
            app(ProjectDocuments::class)->finalize($project, $staff, $reservation->documentId, $object,
                VersionPrecondition::etag($project->id, $project->lock_version), $correlation);
        });
        $documentIds[] = $reservation->documentId;
    }
    for ($i = 0; $i < 8; $i++) {
        $label = 'customer_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $account = $manifest['accounts'][$label];
        $draft = $manifest['records'][$label]['drafts'][0]['id'];
        DB::transaction(function () use ($account, $draft, $correlation): void {
            $actor = app(ReadActiveIdentity::class)->locked($account['id']);
            $source = app(AISources::class)->capture($actor, 'request', $draft, 'improve_description', null, $correlation);
            $run = app(AIRuns::class)->create($source, 'improve_description', (string) Str::uuid7(), $correlation);
            if ($run->state !== 'pending') {
                throw new RuntimeException('The bounded AI capacity fixture did not pass normal admission.');
            }
        });
        app(RecoveryActions::class)->forgotPassword($account['email'], $correlation);
        DB::transaction(fn () => app(NotificationRecorder::class)->record($account['id'], 'project.update_published', 'project',
            $manifest['records'][$label]['projects'][0], 'b8-capacity-'.$correlation.'-'.$i, $correlation));
    }
    $operations = DB::table('async_operations')->where('request_id', $correlation)->get(['id', 'kind'])->all();
    $manifest['queue_probe'] = ['correlation' => $correlation, 'operations' => $operations, 'documents' => $documentIds,
        'document_pages' => 500, 'document_bytes' => strlen($pdf), 'ai_provider' => 'sandbox', 'seeded_at' => now()->utc()->toIso8601String()];
    fwrite(STDOUT, json_encode($manifest, JSON_THROW_ON_ERROR));
} else {
    $probe = $manifest['queue_probe'] ?? null;
    if (! is_array($probe) || ! Str::isUuid($probe['correlation'] ?? '', 7) || ! in_array(count($probe['documents'] ?? []), [0, 25], true)) {
        throw new RuntimeException('Invalid queue probe manifest.');
    }
    $groups = DB::table('async_operations')->where('request_id', $probe['correlation'])->selectRaw('kind,state,failure_code,count(*) AS count,
        min(attempts) AS min_attempts,max(attempts) AS max_attempts,
        round(max(extract(epoch FROM coalesce(completed_at,clock_timestamp())-created_at))::numeric,3) AS oldest_seconds,
        round(percentile_cont(0.50) WITHIN GROUP (ORDER BY extract(epoch FROM completed_at-created_at))::numeric,3) AS completion_p50_seconds,
        round(percentile_cont(0.95) WITHIN GROUP (ORDER BY extract(epoch FROM completed_at-created_at))::numeric,3) AS completion_p95_seconds,
        min(created_at) AS first_created_at,min(completed_at) AS first_completed_at,max(completed_at) AS last_completed_at')
        ->groupBy('kind', 'state', 'failure_code')->orderBy('kind')->orderBy('state')->get()->all();
    $documents = DB::table('documents')->whereIn('id', $probe['documents'])->selectRaw('state,count(*) AS count')->groupBy('state')->get()->all();
    $runs = DB::table('ai_runs')->where('requested_correlation_id', $probe['correlation'])
        ->selectRaw('state,provider,model,count(*) AS count')->groupBy('state', 'provider', 'model')->get()->all();
    $attempts = DB::table('ai_provider_attempts')->whereIn('run_id', DB::table('ai_runs')->where('requested_correlation_id', $probe['correlation'])->select('id'))
        ->selectRaw('outcome,count(*) AS count')->groupBy('outcome')->get()->all();
    fwrite(STDOUT, json_encode(['queues' => $groups, 'documents' => $documents,
        'ai_runs' => $runs, 'ai_attempts' => $attempts,
        'document_pages_each' => $probe['document_pages'], 'document_bytes_each' => $probe['document_bytes'],
        'ai_provider' => 'sandbox', 'external_ai_capacity_not_measured' => true], JSON_THROW_ON_ERROR));
}
