<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Infrastructure\Async\AsyncOperation;
use App\Infrastructure\Async\OperationPolicy;
use Error;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class SafeFailedJobProvider extends DatabaseUuidFailedJobProvider
{
    public function log(mixed $connection, mixed $queue, mixed $payload, mixed $exception): ?string
    {
        if (! (($connection === 'redis' && $queue === Config::string('async.queue'))
            || (in_array($connection, ['documents', 'ai', 'notifications'], true) && $queue === $connection))) {
            Log::error('queue.failed_envelope_rejected');

            return null;
        }

        $canonical = CanonicalOperationPayload::fromJson($payload);

        if ($canonical === null) {
            // Unsupported/corrupt jobs cannot be safely replayed. Their raw
            // bodies are discarded; the durable ledger remains recoverable.
            Log::error('queue.failed_payload_rejected');

            return null;
        }

        if ($canonical->documents !== ($connection === 'documents') || $canonical->ai !== ($connection === 'ai')) {
            Log::error('queue.failed_envelope_rejected');

            return null;
        }

        $operation = AsyncOperation::query()->whereKey($canonical->operationId)->first();
        if ($operation !== null && OperationPolicy::queue($operation->kind) !== ($connection === 'redis' ? 'default' : $connection)) {
            Log::error('queue.failed_envelope_rejected');

            return null;
        }
        $requestId = $operation?->request_id;
        $exceptionClass = match (true) {
            $exception instanceof TimeoutExceededException => TimeoutExceededException::class,
            $exception instanceof MaxAttemptsExceededException => MaxAttemptsExceededException::class,
            $exception instanceof QueryException => QueryException::class,
            $exception instanceof Error => Error::class,
            default => Throwable::class,
        };

        // A single write contains safe data from the outset. Never call the
        // parent's log(), which stringifies the exception and its full trace.
        $this->getTable()->insert([
            'id' => Str::uuid7()->toString(),
            'uuid' => $canonical->uuid,
            'connection' => $connection,
            'queue' => $queue,
            'payload' => $canonical->toJson(),
            'exception' => json_encode([
                'code' => 'queue_job_failed',
                'exception_class' => $exceptionClass,
                'request_id' => is_string($requestId) && Str::isUuid($requestId) ? $requestId : null,
            ], JSON_THROW_ON_ERROR),
            'failed_at' => Date::now('UTC'),
        ]);

        return $canonical->uuid;
    }
}
