<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class ProcessTelemetry
{
    public function heartbeat(?string $role = null, string $event = 'idle'): void
    {
        try {
            $role ??= Config::string('operations.process_role');
            if (! in_array($role, [...MetricRecorder::QUEUES, 'scheduler'], true) || ! in_array($event, ['idle', 'started', 'finished', 'failed'], true)) {
                return;
            }
            $slot = Config::integer('operations.worker_slot');
            if ($slot < 1 || $slot > 64) {
                return;
            }
            $lua = <<<'LUA'
local now=tonumber(ARGV[1])
redis.call('HSETNX',KEYS[1],'observed_since_ms',now)
redis.call('HSET',KEYS[1],'last_seen_ms',now)
if ARGV[2]=='started' then
  redis.call('HSET',KEYS[1],'busy',1,'started_ms',now)
elseif ARGV[2]=='finished' or ARGV[2]=='failed' then
  local start=tonumber(redis.call('HGET',KEYS[1],'started_ms') or now)
  redis.call('HINCRBY',KEYS[1],'busy_ms',math.max(0,math.min(600000,now-start)))
  redis.call('HINCRBY',KEYS[1],ARGV[2]..'_jobs',1)
  redis.call('HSET',KEYS[1],'busy',0)
else redis.call('HSET',KEYS[1],'busy',0) end
redis.call('EXPIRE',KEYS[1],600)
return 1
LUA;
            Redis::connection('cache')->command('eval', [$lua, ['operations:process:'.$role.':'.$slot, (int) floor(microtime(true) * 1000), $event], 1]);
        } catch (Throwable) {
            // Local container heartbeat checks remain available during Redis outage.
        }
    }

    /** @return array<string,list<array<string,int>>> */
    public function snapshot(): array
    {
        $result = [];
        foreach ([...MetricRecorder::QUEUES, 'scheduler'] as $role) {
            $result[$role] = [];
            // Fixed bounded inventory, never SCAN over arbitrary workload keys.
            for ($slot = 1; $slot <= max(1, min(64, Config::integer('operations.worker_slots'))); $slot++) {
                $row = Redis::connection('cache')->command('hgetall', ['operations:process:'.$role.':'.$slot]);
                if (! is_array($row) || $row === []) {
                    continue;
                }
                $safe = ['slot' => $slot];
                foreach (['observed_since_ms', 'last_seen_ms', 'busy', 'started_ms', 'busy_ms', 'finished_jobs', 'failed_jobs'] as $key) {
                    $value = $row[$key] ?? 0;
                    $safe[$key] = is_numeric($value) ? max(0, (int) $value) : 0;
                }
                $safe['heartbeat_age_ms'] = max(0, (int) floor(microtime(true) * 1000) - ($safe['last_seen_ms'] ?? 0));
                $result[$role][] = $safe;
            }
        }

        return $result;
    }
}
