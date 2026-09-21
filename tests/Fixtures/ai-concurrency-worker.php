<?php

declare(strict_types=1);

use App\Modules\AI\Actions\AIRuns;
use App\Modules\AI\Contracts\AICompletionNotifier;
use App\Modules\AI\Contracts\AIContextVerifier;
use App\Modules\AI\Data\AISource;
use App\Modules\AI\Models\AIRun;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AIContextDouble;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'holoul_test') {
    throw new LogicException('Dedicated test environment required.');
}
$input = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
DB::select('SELECT set_config(?, ?, false)', ['application_name', $input['application']]);
DB::statement("SET lock_timeout='12s'");
DB::statement("SET statement_timeout='15s'");
Bus::fake();
Config::set('ai.enabled', true);
Config::set('ai.daily_budget_microusd', $input['budget']);
Config::set('ai.concurrent_runs', $input['concurrent']);
$app->instance(AIContextVerifier::class, new AIContextDouble);
$app->instance(AICompletionNotifier::class, new class implements AICompletionNotifier
{
    public function completed(AIRun $run): void {}
});
$backend = DB::scalar('SELECT pg_backend_pid()');
$run = app(AIRuns::class)->create(new AISource(...$input['source']), 'improve_description', $input['key'], (string) Str::uuid7());
echo json_encode(['id' => $run->id, 'state' => $run->state, 'failure' => $run->failure_code, 'backend' => $backend], JSON_THROW_ON_ERROR);
