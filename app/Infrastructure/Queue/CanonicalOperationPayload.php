<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Infrastructure\Async\RunOperationJob;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Support\Str;
use JsonException;

/** B1 permits only the identifier-only durable-operation transport job. */
final readonly class CanonicalOperationPayload
{
    private function __construct(public string $uuid, public string $operationId) {}

    public static function fromJson(string $payload): ?self
    {
        if (strlen($payload) > 65_536) {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! is_array($decoded['data'] ?? null)) {
            return null;
        }

        $uuid = $decoded['uuid'] ?? null;
        $command = $decoded['data']['command'] ?? null;

        if (! is_string($uuid) || ! Str::isUuid($uuid) || ! is_string($command)
            || ($decoded['job'] ?? null) !== CallQueuedHandler::class.'@call'
            || ($decoded['data']['commandName'] ?? null) !== RunOperationJob::class) {
            return null;
        }

        // Never unserialize transport input. Compare with a fresh, known job
        // after extracting its sole UUID reference; extra properties fail closed.
        if (preg_match('/s:11:"operationId";s:36:"([a-f0-9-]{36})";/', $command, $matches) !== 1
            || ! Str::isUuid($matches[1], 7)
            || ! hash_equals(serialize(new RunOperationJob($matches[1])), $command)) {
            return null;
        }

        return new self($uuid, $matches[1]);
    }

    public function toJson(): string
    {
        $job = new RunOperationJob($this->operationId);

        // Rebuild framework fields from constants. Context hooks and unknown
        // transport fields must never enter durable failed-job storage.
        return json_encode([
            'uuid' => $this->uuid,
            'displayName' => RunOperationJob::class,
            'job' => CallQueuedHandler::class.'@call',
            'maxTries' => $job->tries,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => $job->timeout,
            'retryUntil' => null,
            'deleteWhenMissingModels' => false,
            'data' => ['commandName' => RunOperationJob::class, 'command' => serialize($job)],
            'createdAt' => time(),
            'delay' => null,
            'attempts' => 0,
        ], JSON_THROW_ON_ERROR);
    }
}
