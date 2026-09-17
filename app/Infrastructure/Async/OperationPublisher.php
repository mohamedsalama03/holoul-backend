<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class OperationPublisher
{
    public function publish(string $operationId): bool
    {
        try {
            $operation = AsyncOperation::query()->find($operationId);
            if ($operation === null) {
                return false;
            }
            if (OperationPolicy::documents($operation->kind)) {
                Queue::connection('documents')->push(new RunDocumentOperationJob($operationId), '', 'documents');
            } else {
                Queue::connection('redis')->push(new RunOperationJob($operationId), '', Config::string('async.queue'));
            }

            AsyncOperation::query()->whereKey($operationId)
                ->whereIn('state', [OperationState::Pending->value, OperationState::Running->value])
                ->update(['last_dispatched_at' => DB::raw('clock_timestamp()')]);

            return true;
        } catch (Throwable) {
            // The committed intent survives a failed/ambiguous publication.
            Log::warning('async.transport_unavailable', ['operation_id' => $operationId]);

            return false;
        }
    }
}
