<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final readonly class OperationalSnapshot
{
    private const QUEUE_SQL = "CASE WHEN kind IN ('documents.scan','documents.delete','documents.delete_orphan') THEN 'documents' WHEN kind='ai.generate' THEN 'ai' WHEN kind='notifications.email' THEN 'notifications' ELSE 'default' END";

    public function __construct(private MetricRecorder $metrics, private ProcessTelemetry $processes) {}

    /** @return array<string,mixed> Fixed non-secret operational projection; no SQL text or business identifiers. */
    public function collect(): array
    {
        return ['schema_version' => 1, 'event' => 'operations.snapshot', 'observed_at' => gmdate(DATE_ATOM),
            'profile' => Config::string('operations.deployment_profile') === 'local-verification' ? 'local-verification' : 'production',
            'postgres' => $this->postgres(), 'redis' => $this->redis(), 'metrics' => $this->metrics->snapshot(),
            'storage' => $this->storage(), 'scanner_socket_present' => file_exists(Config::string('documents.scanner_socket')),
            'inspector_socket_present' => file_exists(Config::string('documents.inspector_socket')),
            'backup' => $this->statusFile(Config::string('operations.backup_status_file')),
            'restore' => $this->statusFile(Config::string('operations.restore_status_file'))];
    }

    /** @return array<string,mixed> */
    private function postgres(): array
    {
        $start = hrtime(true);
        try {
            return DB::transaction(function () use ($start): array {
                DB::statement("SET LOCAL statement_timeout='2500ms'");
                $health = DB::selectOne("SELECT count(*)::bigint AS connections,
                    count(*) FILTER(WHERE state='active')::bigint AS active_connections,
                    count(*) FILTER(WHERE wait_event_type='Lock')::bigint AS waiting_connections,
                    (SELECT count(*)::bigint FROM pg_locks WHERE NOT granted) AS waiting_locks,
                    current_setting('max_connections')::bigint AS max_connections
                    FROM pg_stat_activity WHERE datname=current_database()");
                $backlog = DB::select('SELECT '.self::QUEUE_SQL." AS queue,
                    count(*) FILTER(WHERE state='pending')::bigint AS pending,
                    count(*) FILTER(WHERE state='running')::bigint AS running,
                    count(*) FILTER(WHERE state='pending' AND next_attempt_at<=clock_timestamp())::bigint AS due,
                    greatest(0,extract(epoch FROM clock_timestamp()-min(created_at) FILTER(WHERE state='pending'))) AS oldest_pending_seconds
                    FROM async_operations WHERE state IN ('pending','running') GROUP BY 1");
                $recent = DB::select('WITH recent AS (SELECT kind,state,created_at,completed_at FROM async_operations ORDER BY id DESC LIMIT 10000)
                    SELECT '.self::QUEUE_SQL." AS queue,count(*)::bigint AS sampled,count(*) FILTER(WHERE completed_at<created_at)::bigint AS negative_interval_count,
                    count(*) FILTER(WHERE state='failed')::bigint AS failed,
                    count(*) FILTER(WHERE state='succeeded')::bigint AS succeeded,
                    percentile_cont(0.50) WITHIN GROUP(ORDER BY CASE WHEN completed_at IS NULL THEN NULL ELSE greatest(0,extract(epoch FROM completed_at-created_at)*1000) END) AS completion_p50_ms,
                    percentile_cont(0.95) WITHIN GROUP(ORDER BY CASE WHEN completed_at IS NULL THEN NULL ELSE greatest(0,extract(epoch FROM completed_at-created_at)*1000) END) AS completion_p95_ms,
                    percentile_cont(0.99) WITHIN GROUP(ORDER BY CASE WHEN completed_at IS NULL THEN NULL ELSE greatest(0,extract(epoch FROM completed_at-created_at)*1000) END) AS completion_p99_ms
                    FROM recent WHERE created_at>=clock_timestamp()-interval '24 hours' GROUP BY 1");
                $scan = DB::selectOne("SELECT count(*)::bigint AS quarantined,
                    greatest(0,extract(epoch FROM clock_timestamp()-min(created_at))) AS oldest_quarantine_seconds
                    FROM documents WHERE state='quarantined'");
                $ai = DB::select("WITH recent AS (SELECT outcome,created_at,completed_at FROM ai_provider_attempts ORDER BY id DESC LIMIT 10000)
                    SELECT outcome AS state,count(*)::bigint AS attempts,count(*) FILTER(WHERE completed_at<created_at)::bigint AS negative_interval_count,
                    avg(CASE WHEN completed_at IS NULL THEN NULL ELSE greatest(0,extract(epoch FROM completed_at-created_at)*1000) END) AS mean_attempt_ms,
                    percentile_cont(0.95) WITHIN GROUP(ORDER BY CASE WHEN completed_at IS NULL THEN NULL ELSE greatest(0,extract(epoch FROM completed_at-created_at)*1000) END) AS attempt_p95_ms
                    FROM recent WHERE created_at>=clock_timestamp()-interval '24 hours' GROUP BY outcome");
                $cost = DB::selectOne('SELECT reserved_microusd,spent_microusd FROM ai_budget_days WHERE day=(clock_timestamp() AT TIME ZONE \'UTC\')::date');
                $mail = DB::select("WITH recent AS (SELECT state,started_at,completed_at FROM notification_delivery_attempts ORDER BY id DESC LIMIT 10000)
                    SELECT state,count(*)::bigint AS attempts,count(*) FILTER(WHERE completed_at<started_at)::bigint AS negative_interval_count,avg(CASE WHEN completed_at IS NULL THEN NULL ELSE greatest(0,extract(epoch FROM completed_at-started_at)*1000) END) AS mean_attempt_ms,
                    percentile_cont(0.95) WITHIN GROUP(ORDER BY CASE WHEN completed_at IS NULL THEN NULL ELSE greatest(0,extract(epoch FROM completed_at-started_at)*1000) END) AS attempt_p95_ms
                    FROM recent WHERE started_at>=clock_timestamp()-interval '24 hours' GROUP BY state");

                return ['available' => true, 'probe_ms' => (int) ((hrtime(true) - $start) / 1_000_000),
                    'health' => $health === null ? [] : $this->numbers($health), 'durable_backlog' => $this->rows($backlog, 'queue', MetricRecorder::QUEUES),
                    'timestamp_precision_ms' => 1000, 'recent_limit' => 10000, 'recent_window_seconds' => 86400, 'recent_durable' => $this->rows($recent, 'queue', MetricRecorder::QUEUES),
                    'document_scan' => $scan === null ? [] : $this->numbers($scan),
                    'ai_attempts' => $this->rows($ai, 'state', ['started', 'retryable', 'succeeded', 'failed', 'uncertain', 'cancelled']),
                    'ai_cost' => $cost === null ? ['reserved_microusd' => 0, 'spent_microusd' => 0] : $this->numbers($cost),
                    'notification_attempts' => $this->rows($mail, 'state', ['processing', 'accepted', 'failed', 'uncertain'])];
            });
        } catch (Throwable) {
            return ['available' => false, 'error' => 'postgres_observation_unavailable'];
        }
    }

    /** @return array<string,mixed> */
    private function redis(): array
    {
        $start = hrtime(true);
        try {
            Redis::connection()->command('ping');
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);
            $memory = Redis::connection()->command('info', ['memory']);
            $stats = Redis::connection()->command('info', ['stats']);
            $safe = [];
            foreach (['used_memory', 'used_memory_peak', 'maxmemory'] as $key) {
                $value = is_array($memory) ? ($memory[$key] ?? null) : null;
                $safe[$key] = is_numeric($value) ? (int) $value : null;
            }
            $evicted = is_array($stats) ? ($stats['evicted_keys'] ?? null) : null;
            $safe['evicted_keys'] = is_numeric($evicted) ? (int) $evicted : null;
            $queues = [];
            foreach (MetricRecorder::QUEUES as $queue) {
                $queues[$queue] = ['ready' => $this->redisCount('llen', 'queues:'.$queue),
                    'reserved' => $this->redisCount('zcard', 'queues:'.$queue.':reserved'),
                    'delayed' => $this->redisCount('zcard', 'queues:'.$queue.':delayed')];
            }

            return ['available' => true, 'ping_ms' => $latency, 'memory' => $safe, 'queues' => $queues, 'processes' => $this->processes->snapshot()];
        } catch (Throwable) {
            return ['available' => false, 'error' => 'redis_observation_unavailable'];
        }
    }

    private function redisCount(string $command, string $key): ?int
    {
        $value = Redis::connection()->command($command, [$key]);

        return is_int($value) && $value >= 0 ? $value : null;
    }

    /** @return array<string,mixed> */
    private function storage(): array
    {
        $start = hrtime(true);
        try {
            $url = parse_url(Config::string('documents.s3.endpoint'));
            if (! is_array($url) || ($url['scheme'] ?? null) !== 'https' || ! is_string($url['host'] ?? null)) {
                return ['tls_reachable' => false];
            }
            $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true,
                'peer_name' => $url['host'], 'cafile' => Config::string('documents.s3.ca_bundle')]]);
            $socket = @stream_socket_client('tls://'.$url['host'].':'.($url['port'] ?? 443), $error, $message, 1.0, STREAM_CLIENT_CONNECT, $context);
            if (is_resource($socket)) {
                fclose($socket);

                return ['tls_reachable' => true, 'connect_ms' => (int) ((hrtime(true) - $start) / 1_000_000)];
            }
        } catch (Throwable) {
            // Transport reachability is distinct from bucket policy and object availability.
        }

        return ['tls_reachable' => false, 'connect_ms' => (int) ((hrtime(true) - $start) / 1_000_000)];
    }

    /** @return array<string,int|float|null> */
    private function numbers(mixed $row): array
    {
        $result = [];
        if (! is_object($row)) {
            return $result;
        }
        foreach (get_object_vars($row) as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (is_int($value) || is_float($value) || $value === null) {
                $result[$key] = $value;
            } elseif (is_string($value) && is_numeric($value)) {
                $result[$key] = ctype_digit($value) ? (int) $value : (float) $value;
            }
        }

        return $result;
    }

    /** @param array<array-key,mixed> $rows
     * @param  list<string>  $allowed
     * @return array<string,array<string,int|float|null>>
     */
    private function rows(array $rows, string $label, array $allowed): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }
            $value = get_object_vars($row)[$label] ?? null;
            if (is_string($value) && in_array($value, $allowed, true)) {
                $result[$value] = $this->numbers($row);
            }
        }

        return $result;
    }

    /** @return array{state:string,completed_at:?string,duration_ms:?int} */
    private function statusFile(string $path): array
    {
        $unknown = ['state' => 'unknown', 'completed_at' => null, 'duration_ms' => null];
        try {
            if (! is_file($path) || ! is_readable($path) || filesize($path) > 512) {
                return $unknown;
            }
            $contents = file_get_contents($path, length: 513);
            $row = is_string($contents) ? json_decode($contents, true, 4, JSON_THROW_ON_ERROR) : null;
            if (! is_array($row) || array_diff(array_keys($row), ['state', 'completed_at', 'duration_ms']) !== []
                || ! in_array($row['state'] ?? null, ['succeeded', 'failed', 'never'], true)
                || ! is_int($row['duration_ms'] ?? null) || $row['duration_ms'] < 0 || $row['duration_ms'] > 86400000) {
                return $unknown;
            }
            $completed = $row['completed_at'] ?? null;
            if ($row['state'] !== 'never' && $completed === null) {
                return $unknown;
            }
            if ($completed !== null && (! is_string($completed) || strlen($completed) > 40
                || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $completed) !== 1
                || (new DateTimeImmutable($completed))->getTimestamp() > time() + 60)) {
                return $unknown;
            }

            return ['state' => $row['state'], 'completed_at' => $completed, 'duration_ms' => $row['duration_ms']];
        } catch (Throwable) {
            return $unknown;
        }
    }
}
