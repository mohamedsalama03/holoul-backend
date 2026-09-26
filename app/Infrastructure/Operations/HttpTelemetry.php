<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final readonly class HttpTelemetry
{
    public function __construct(private MetricRecorder $metrics, private RequestMeasurements $measurements) {}

    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('operations.started_ns', hrtime(true));
        $this->measurements->begin();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $start = $request->attributes->get('operations.started_ns');
        if (! is_int($start)) {
            return;
        }
        $duration = max(0, min(600000, (int) ((hrtime(true) - $start) / 1_000_000)));
        $family = $this->family($request->path());
        $this->measurements->active = false;
        $this->metrics->http($family, $request->method(), $response->getStatusCode(), $duration, $this->measurements->queries, $this->measurements->sqlMs);
        Log::info('telemetry.http', ['family' => $family, 'method' => $request->method(), 'status' => $response->getStatusCode(),
            'duration_ms' => $duration, 'query_count' => $this->measurements->queries, 'sql_duration_ms' => $this->measurements->sqlMs]);
    }

    private function family(string $path): string
    {
        return match (true) {
            str_starts_with($path, 'health/') => 'health',
            str_contains($path, '/auth/'), str_starts_with($path, 'sanctum/') => 'auth',
            str_contains($path, '/notification') => 'notifications',
            str_contains($path, '/ai-runs') => 'ai',
            str_contains($path, '/reports'), str_contains($path, '/dashboard') => 'reporting',
            str_contains($path, '/audit') => 'audit',
            str_contains($path, '/documents') => 'documents',
            str_contains($path, '/proposals'), str_contains($path, '/discovery') => 'commercial',
            str_contains($path, '/project-requests') => 'intake',
            str_contains($path, '/projects') => 'projects',
            default => 'other',
        };
    }
}
