<?php

declare(strict_types=1);

use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Actions\TransitionRequest;
use App\Modules\ProjectIntake\Data\IntakeActor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    throw new LogicException('Concurrency fixture requires the testing environment.');
}

/** @var array{action:string,actor_id:string,customer_id:string,id:string,etag:string,key:string,application:string} $input */
$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
DB::select('SELECT set_config(?, ?, false)', ['application_name', $input['application']]);
DB::statement("SET lock_timeout = '12s'");
DB::statement("SET statement_timeout = '15s'");
$backend = DB::scalar('SELECT pg_backend_pid()');
$actor = new IntakeActor($input['actor_id'], $input['customer_id'], true);
$result = ['action' => $input['action'], 'backend' => $backend];

try {
    if ($input['action'] === 'submit') {
        $claim = $app->make(SubmitRequest::class)->handle($actor, $input['id'], $input['etag'], $input['key'], (string) Str::uuid7());
        $result += ['status' => 200, 'request_id' => $claim->request_id, 'revision_id' => $claim->revision_id,
            'revision_number' => $claim->revision_number, 'version' => $claim->result_version,
            'state' => $claim->result_state, 'reference' => $claim->reference];
    } elseif ($input['action'] === 'update') {
        $record = $app->make(ManageDraft::class)->update($actor, $input['id'], $input['etag'],
            ['project_name' => 'Concurrent draft edit'], (string) Str::uuid7());
        $result += ['status' => 200, 'version' => $record->lock_version, 'state' => $record->state->value];
    } elseif ($input['action'] === 'withdraw') {
        $record = $app->make(TransitionRequest::class)->handle($actor, $input['id'], $input['etag'], 'withdraw', null, (string) Str::uuid7());
        $result += ['status' => 200, 'version' => $record->lock_version, 'state' => $record->state->value];
    } else {
        throw new LogicException('Unknown concurrency fixture operation.');
    }
} catch (HttpExceptionInterface $exception) {
    $result += ['status' => $exception->getStatusCode()];
}

echo json_encode($result, JSON_THROW_ON_ERROR);
