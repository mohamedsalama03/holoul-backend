<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Disposable, bounded metric buckets; never an authority for business outcomes. */
final class MetricRecorder
{
    public const QUEUES = ['default', 'documents', 'ai', 'notifications'];

    public const BUCKETS_MS = [5, 10, 25, 50, 100, 200, 300, 500, 1000, 2000, 5000, 15000, 120000, 600000];

    private const FAMILIES = ['auth', 'intake', 'commercial', 'projects', 'documents', 'ai', 'notifications', 'reporting', 'audit', 'health', 'other'];

    public function http(string $family, string $method, int $status, int $durationMs, int $queries, int $sqlMs): void
    {
        $family = in_array($family, self::FAMILIES, true) ? $family : 'other';
        $method = in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true) ? $method : 'OTHER';
        $status = max(1, min(5, intdiv($status, 100)));
        $this->record('http.'.$family.'.'.$method.'.'.$status.'xx', $durationMs, ['queries' => $queries, 'sql_ms' => $sqlMs]);
    }

    public function slowQuery(): void
    {
        $this->record('postgres.slow_query', 0);
    }

    public function operation(string $queue, string $phase, int $durationMs): void
    {
        if (in_array($queue, self::QUEUES, true) && in_array($phase, ['queue_wait', 'execution'], true)) {
            $this->record('operation.'.$queue.'.'.$phase, $durationMs);
        }
    }

    /** @template T
     * @param  Closure():T  $action
     * @return T
     */
    public function provider(string $provider, Closure $action): mixed
    {
        if (! in_array($provider, ['ai', 'notifications'], true)) {
            throw new \LogicException('Invalid provider metric dimension.');
        }
        $start = hrtime(true);
        $outcome = 'success';
        try {
            return $action();
        } catch (Throwable $error) {
            $outcome = 'failure';
            throw $error;
        } finally {
            $this->record('provider.'.$provider.'.'.$outcome, (int) ((hrtime(true) - $start) / 1_000_000));
        }
    }

    /** @template T
     * @param  Closure():T  $action
     * @return T
     */
    public function storage(string $operation, Closure $action): mixed
    {
        if (! in_array($operation, ['put', 'open', 'stat', 'delete', 'list'], true)) {
            throw new \LogicException('Invalid storage metric dimension.');
        }
        $start = hrtime(true);
        $outcome = 'success';
        try {
            return $action();
        } catch (Throwable $error) {
            $outcome = 'failure';
            throw $error;
        } finally {
            $this->record('storage.'.$operation.'.'.$outcome, (int) ((hrtime(true) - $start) / 1_000_000));
        }
    }

    /** @return array{available:bool,window_seconds:int,counters:array<string,int>} */
    public function snapshot(int $minutes = 5): array
    {
        if ($minutes < 1 || $minutes > 15) {
            throw new \InvalidArgumentException('Metric window must be between one and fifteen minutes.');
        }
        $counters = [];
        try {
            for ($offset = 0; $offset < $minutes; $offset++) {
                $row = Redis::connection('cache')->command('hgetall', ['operations:metrics:'.(intdiv(time(), 60) - $offset)]);
                if (! is_array($row)) {
                    throw new \RuntimeException('Metric store unavailable.');
                }
                foreach ($row as $key => $value) {
                    if (! is_string($key) || preg_match('/\A[a-zA-Z0-9_.]{1,100}\z/D', $key) !== 1 || ! is_numeric($value)) {
                        continue;
                    }
                    $counters[$key] = ($counters[$key] ?? 0) + max(0, (int) $value);
                }
            }
            ksort($counters);

            return ['available' => true, 'window_seconds' => $minutes * 60, 'counters' => $counters];
        } catch (Throwable) {
            return ['available' => false, 'window_seconds' => $minutes * 60, 'counters' => []];
        }
    }

    /** @param array<string,int> $additional */
    private function record(string $metric, int $durationMs, array $additional = []): void
    {
        // Recording failure must never undo a committed business action or hide an exception.
        try {
            $durationMs = max(0, min(600000, $durationMs));
            $bucket = 600000;
            foreach (self::BUCKETS_MS as $bound) {
                if ($durationMs <= $bound) {
                    $bucket = $bound;
                    break;
                }
            }
            $values = ['count' => 1, 'duration_ms' => $durationMs, 'bucket_'.$bucket => 1, ...$additional];
            $arguments = ['operations:metrics:'.intdiv(time(), 60), Config::integer('operations.metric_retention_seconds')];
            foreach ($values as $name => $value) {
                $arguments[] = $metric.'.'.$name;
                $arguments[] = max(0, min(10000000, $value));
            }
            $lua = <<<'LUA'
for i=2,#ARGV,2 do redis.call('HINCRBY',KEYS[1],ARGV[i],ARGV[i+1]) end
redis.call('EXPIRE',KEYS[1],ARGV[1])
return 1
LUA;
            Redis::connection('cache')->command('eval', [$lua, $arguments, 1]);
        } catch (Throwable) {
            // Safe structured access logs remain the independent HTTP evidence.
        }
    }
}
