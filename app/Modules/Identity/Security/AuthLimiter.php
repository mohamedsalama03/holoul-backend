<?php

declare(strict_types=1);

namespace App\Modules\Identity\Security;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class AuthLimiter
{
    /**
     * All buckets are checked then charged in a single Redis Lua operation. No
     * local fallback: an unavailable limiter returns 503 before credentials run.
     *
     * @param  list<array{key: string, maximum: int, seconds: int}>  $buckets
     */
    public function consume(array $buckets): int
    {
        $keys = [];
        $arguments = [];
        foreach ($buckets as $bucket) {
            $keys[] = 'identity:limit:'.hash_hmac('sha256', $bucket['key'], Config::string('app.key'));
            $arguments[] = $bucket['maximum'];
            $arguments[] = $bucket['seconds'];
        }
        $lua = <<<'LUA'
local retry = 0
for i, key in ipairs(KEYS) do
  local maximum = tonumber(ARGV[(i-1)*2+1])
  if tonumber(redis.call('GET', key) or '0') >= maximum then
    retry = math.max(retry, redis.call('TTL', key), 1)
  end
end
if retry > 0 then return {retry, 0} end
local attempt = 0
for i, key in ipairs(KEYS) do
  local count = redis.call('INCR', key)
  if i == 1 then attempt = count end
  if count == 1 then redis.call('EXPIRE', key, tonumber(ARGV[(i-1)*2+2])) end
end
return {0, attempt}
LUA;
        try {
            $result = Redis::connection('cache')->command('eval', [$lua, [...$keys, ...$arguments], count($keys)]);
            if (! is_array($result) || ! isset($result[0], $result[1]) || ! is_int($result[0]) || ! is_int($result[1])) {
                throw new \RuntimeException('Invalid limiter result.');
            }
            [$retry, $attempt] = $result;
        } catch (Throwable) {
            throw new HttpException(503);
        }
        if ($retry > 0) {
            throw new HttpException(429, headers: ['Retry-After' => (string) min($retry, 999999)]);
        }

        return $attempt;
    }
}
