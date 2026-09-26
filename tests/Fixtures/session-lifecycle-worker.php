<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'holoul_test' || ! $app->environment('testing')) {
    exit(2);
}
$spec = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
Config::set('identity.origin', 'https://localhost:8443');
Config::set('app.url', 'https://localhost:8443');
Config::set('database.redis.options.prefix', $spec['redis_prefix']);
Config::set('logging.channels.stdout.handler_with.stream', 'php://stderr');
Log::forgetChannel('stdout');
DB::select('SELECT set_config(?, ?, false)', ['application_name', $spec['name']]);
$pid = DB::scalar('SELECT pg_backend_pid()');
$app->instance('session-race.spec', $spec);

final class SessionLifecycleBarrier
{
    public function handle(Request $request, Closure $next): Response
    {
        $spec = app('session-race.spec');
        if ($spec['phase'] === 'before') {
            $this->wait($spec['barrier']);
        }
        $response = $next($request);
        if ($spec['phase'] === 'after') {
            $this->wait($spec['barrier']);
        }

        return $response;
    }

    private function wait(string $key): void
    {
        DB::select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$key]);
        DB::select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$key]);
    }
}

Route::aliasMiddleware('test.session-race', SessionLifecycleBarrier::class);
Route::pushMiddlewareToGroup('identity.spa', 'test.session-race');
$request = Request::create('https://localhost:8443'.$spec['path'], $spec['method'], [], $spec['cookies'], [],
    ['HTTPS' => 'on', 'HTTP_HOST' => 'localhost:8443', 'SERVER_PORT' => '8443', 'REMOTE_ADDR' => $spec['ip'],
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        'HTTP_ORIGIN' => 'https://localhost:8443', 'HTTP_X_XSRF_TOKEN' => $spec['cookies']['XSRF-TOKEN'] ?? ''], '{}');
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);
$cookies = [];
foreach ($response->headers->getCookies() as $cookie) {
    $cookies[$cookie->getName()] = (string) $cookie->getValue();
}
// Private pipe to the parent test only; never persist cookies in evidence/logs.
echo json_encode(['status' => $response->getStatusCode(), 'cookies' => $cookies, 'pid' => $pid], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
