<?php

declare(strict_types=1);

use App\Application\Commercial\ExpireProposalsCommand;
use App\Application\Projects\ExpireProjectUploadsCommand;
use App\Infrastructure\Async\ReconcileOperationsCommand;
use App\Infrastructure\Exceptions\SafeExceptionHandler;
use App\Infrastructure\Http\ApiError;
use App\Infrastructure\Http\RequestId;
use App\Infrastructure\Http\RequestLimits;
use App\Infrastructure\Http\StartSecureSession;
use App\Infrastructure\Operations\HttpTelemetry;
use App\Modules\Identity\Http\ExactOrigin;
use App\Modules\Identity\Http\SecurityThrottle;
use App\Modules\Identity\Http\SessionAuthenticated;
use App\Modules\Identity\Http\StrictCsrf;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        then: function (): void {
            Route::middleware('api')->group(__DIR__.'/../routes/health.php');
            Route::get('/sanctum/csrf-cookie', CsrfCookieController::class.'@show')
                ->middleware('identity.spa')->name('sanctum.csrf-cookie');
        },
    )
    ->withCommands([ReconcileOperationsCommand::class, ExpireProposalsCommand::class, ExpireProjectUploadsCommand::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->prepend(HttpTelemetry::class);
        $middleware->append(RequestLimits::class);
        $middleware->group('identity.spa', [
            ExactOrigin::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSecureSession::class,
            StrictCsrf::class,
        ]);
        $middleware->alias([
            'identity.auth' => SessionAuthenticated::class,
            'identity.throttle' => SecurityThrottle::class,
        ]);
        $middleware->trustHosts(
            at: fn (): array => array_map(fn (string $host): string => '^'.preg_quote($host, '/').'$', array_values(array_filter(Config::array('app.trusted_hosts'), is_string(...)))),
            subdomains: false,
        );
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (): bool => true);
        $exceptions->render(fn (Throwable $exception, Request $request) => ApiError::render($exception, $request));
        $exceptions->report(function (Throwable $exception): bool {
            Log::error('request.failed', ['exception_type' => $exception::class, 'error_code' => 'INTERNAL_ERROR']);

            return false;
        });
    })->create()->dontMergeFrameworkConfiguration();

$app->singleton(ExceptionHandler::class, SafeExceptionHandler::class);

return $app;
