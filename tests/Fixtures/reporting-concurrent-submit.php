<?php

declare(strict_types=1);

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Data\IntakeActor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'holoul_test') {
    throw new LogicException('Dedicated test environment required.');
}
DB::statement("SET lock_timeout='5s'");
DB::statement("SET statement_timeout='10s'");
Queue::fake();
$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
$actor = new IntakeActor($input['actor_id'], $input['customer_id'], true, []);
$request = app(ManageDraft::class)->create($actor, $input['draft'], (string) Str::uuid7());
app(SubmitRequest::class)->handle($actor, $request->id, VersionPrecondition::etag($request->id, $request->lock_version), (string) Str::uuid7(), (string) Str::uuid7());
echo json_encode(['request_id' => $request->id, 'backend' => DB::scalar('SELECT pg_backend_pid()')], JSON_THROW_ON_ERROR);
