<?php

declare(strict_types=1);

use App\Modules\Notifications\Contracts\NotificationRecorder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'holoul_test') {
    throw new LogicException('Dedicated PostgreSQL test database required.');
}
$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
Queue::fake();
DB::select('SELECT set_config(?, ?, false)', ['application_name', $input['application']]);
DB::statement("SET lock_timeout='12s'");
$id = DB::transaction(fn () => app(NotificationRecorder::class)->record($input['recipient'], 'project.created', 'project', $input['resource'], $input['key'], (string) Str::uuid7()));
echo json_encode(['id' => $id, 'backend' => DB::scalar('SELECT pg_backend_pid()')], JSON_THROW_ON_ERROR);
