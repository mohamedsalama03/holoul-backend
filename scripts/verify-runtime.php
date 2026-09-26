<?php

declare(strict_types=1);

use App\Infrastructure\Async\OperationHandlerRegistry;
use App\Infrastructure\Async\PermanentOperationFailure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$operationId = null;

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Runtime verification requires the console.');
    }

    require __DIR__.'/../vendor/autoload.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    if (! $app instanceof Application) {
        throw new RuntimeException('The runtime application could not be loaded.');
    }
    $app->make(Kernel::class)->bootstrap();

    if (! $app->environment('production') || Config::boolean('app.debug')
        || is_file(__DIR__.'/../vendor/bin/phpunit')) {
        throw new RuntimeException('The effective runtime is not the production artifact.');
    }

    $mode = $argv[1] ?? '';

    $compiled = [];
    if (in_array($mode, ['environment', 'ai-disabled'], true)) {
        foreach ([$app->getCachedConfigPath() => 'config.php', $app->getCachedRoutesPath() => 'routes-v7.php'] as $path => $filename) {
            if ($path !== $app->bootstrapPath('cache/'.$filename) || is_link($path) || ! is_file($path)) {
                throw new RuntimeException('The compiled runtime cache is unavailable or unsafe.');
            }
            $permissions = fileperms($path);
            if ($permissions === false || ($permissions & 0777) !== 0600) {
                throw new RuntimeException('The compiled runtime cache is unavailable or unsafe.');
            }
        }
        $cachePermissions = fileperms($app->bootstrapPath('cache'));
        $routeCount = count($app->make(Router::class)->getRoutes()->getRoutes());
        if (! $app->configurationIsCached() || ! $app->routesAreCached() || $routeCount !== 166
            || $cachePermissions === false || ($cachePermissions & 0777) !== 0700) {
            throw new RuntimeException('The compiled runtime configuration or routes are incomplete.');
        }
        $compiled = ['configuration_cached' => true, 'routes_cached' => true,
            'cache_permissions_private' => true, 'registered_routes' => $routeCount];
    }

    if ($mode === 'ai-disabled') {
        if (Config::boolean('ai.enabled') || Config::string('ai.driver') !== 'sandbox'
            || Config::array('ai.allowed_providers') !== ['sandbox'] || Config::array('ai.allowed_models') !== ['sandbox-v1']) {
            throw new RuntimeException('AI release defaults are unsafe.');
        }
        fwrite(STDOUT, json_encode(['environment' => 'production', 'debug' => false,
            'ai_enabled' => false, 'external_provider_configured' => false, ...$compiled], JSON_THROW_ON_ERROR)."\n");
        exit(0);
    }

    if ($mode === 'environment') {
        fwrite(STDOUT, json_encode([
            'event' => 'verification.runtime_environment',
            'environment' => 'production',
            'debug' => false,
            'testing_dependencies' => false,
            ...$compiled,
        ], JSON_THROW_ON_ERROR)."\n");
        exit(0);
    }

    if ($mode !== 'async' || Config::string('database.default') !== 'pgsql'
        || Config::string('database.connections.pgsql.username') !== 'holoul_app') {
        throw new RuntimeException('Runtime operation verification requires application credentials.');
    }

    // This reserved infrastructure type deliberately has no handler or domain
    // effect. Reaching handler_missing proves the deployed transport path ran.
    $kind = 'infrastructure.b1_probe';

    try {
        $app->make(OperationHandlerRegistry::class)->for($kind);
        throw new RuntimeException('The verification operation type is reserved.');
    } catch (PermanentOperationFailure $exception) {
        if ($exception->safeCode !== 'handler_missing') {
            throw new RuntimeException('The verification operation type is unavailable.');
        }
    }

    $operationId = (string) Str::uuid7();
    $logicalKeyHash = hash('sha256', 'b1-runtime-probe:'.$operationId);

    // Intentionally bypass the recorder's publication callback. Only the real
    // scheduled reconciler may discover and transport this committed intent.
    DB::transaction(function () use ($operationId, $kind, $logicalKeyHash): void {
        DB::table('async_operations')->insert([
            'id' => $operationId,
            'kind' => $kind,
            'logical_key_hash' => $logicalKeyHash,
            'input_hash' => hash('sha256', '{}'),
            'references' => '{}',
            'state' => 'pending',
            'attempts' => 0,
            'max_attempts' => 1,
            'fence' => 0,
            'next_attempt_at' => DB::raw('clock_timestamp()'),
            'created_at' => DB::raw('clock_timestamp()'),
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
    });

    $deadline = hrtime(true) + 150_000_000_000;

    do {
        $operation = DB::table('async_operations')->where('id', $operationId)
            ->first(['state', 'failure_code', 'attempts', 'fence', 'completed_at', 'lease_expires_at']);

        if ($operation === null) {
            throw new RuntimeException('The verification operation disappeared.');
        }

        if ($operation->state === 'failed') {
            if ($operation->failure_code !== 'handler_missing' || ! in_array($operation->attempts, [1, '1'], true)
                || ! in_array($operation->fence, [1, '1'], true) || $operation->completed_at === null
                || $operation->lease_expires_at !== null) {
                throw new RuntimeException('The verification operation reached an unexpected outcome.');
            }

            // Remove only this probe's exact, verified terminal row. Failures
            // remain available for diagnosis; no business or audit row is touched.
            $deleted = DB::table('async_operations')->where('id', $operationId)
                ->where('kind', $kind)->where('logical_key_hash', $logicalKeyHash)
                ->where('state', 'failed')->where('failure_code', 'handler_missing')
                ->where('attempts', 1)->where('fence', 1)->delete();

            if ($deleted !== 1) {
                throw new RuntimeException('The verification operation could not be cleaned up.');
            }

            fwrite(STDOUT, json_encode([
                'event' => 'verification.runtime_async',
                'status' => 'passed',
                'operation_id' => $operationId,
                'path' => 'scheduler.redis.worker.postgresql',
            ], JSON_THROW_ON_ERROR)."\n");
            exit(0);
        }

        if ($operation->state !== 'pending' && $operation->state !== 'running') {
            throw new RuntimeException('The verification operation reached an unexpected state.');
        }

        usleep(1_000_000);
    } while (hrtime(true) < $deadline);

    throw new RuntimeException('Runtime operation verification timed out.');
} catch (Throwable) {
    fwrite(STDERR, json_encode([
        'event' => 'verification.runtime_failed',
        'operation_id' => $operationId,
        'message' => 'Runtime verification failed; any reserved probe record remains available for diagnosis.',
    ], JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
