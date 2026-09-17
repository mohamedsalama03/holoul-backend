<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class OperationRunner
{
    public function __construct(private OperationHandlerRegistry $handlers) {}

    public function run(string $operationId): void
    {
        $claim = $this->claim($operationId);

        if ($claim === null) {
            return;
        }

        Log::withContext(['operation_id' => $claim->id, 'request_id' => $claim->requestId]);

        try {
            $writer = $this->handlers->for($claim->kind)->execute($claim);
            $this->complete($claim, $writer);
        } catch (LostOperationLease) {
            // A newer claim owns the outcome. Its fence cannot be overwritten.
        } catch (PermanentOperationFailure $exception) {
            $this->fail($claim, $exception->safeCode, false);
        } catch (Throwable) {
            $this->fail($claim, 'execution_failed', true);
        } finally {
            Log::withoutContext();
        }
    }

    public function claim(string $operationId): ?OperationClaim
    {
        return DB::transaction(function () use ($operationId): ?OperationClaim {
            $operation = AsyncOperation::query()->whereKey($operationId)
                ->where(function (Builder $query): void {
                    $query->where(function (Builder $pending): void {
                        $pending->where('state', OperationState::Pending->value)
                            ->where('next_attempt_at', '<=', DB::raw('clock_timestamp()'));
                    })->orWhere(function (Builder $running): void {
                        $running->where('state', OperationState::Running->value)
                            ->where('lease_expires_at', '<=', DB::raw('clock_timestamp()'));
                    });
                })->lockForUpdate()->first();

            if ($operation === null) {
                return null;
            }

            if ($operation->attempts >= $operation->max_attempts) {
                AsyncOperation::query()->whereKey($operation->id)->update([
                    'state' => OperationState::Failed->value,
                    'failure_code' => 'attempts_exhausted',
                    'lease_expires_at' => null,
                    'completed_at' => DB::raw('clock_timestamp()'),
                ]);

                return null;
            }

            $leaseSeconds = OperationPolicy::leaseSeconds($operation->kind);
            DB::update(<<<'SQL'
                UPDATE async_operations
                   SET state = ?, attempts = attempts + 1, fence = fence + 1,
                       lease_expires_at = clock_timestamp() + make_interval(secs => ?),
                       updated_at = clock_timestamp()
                 WHERE id = ?
                SQL, [OperationState::Running->value, $leaseSeconds, $operation->id]);

            return new OperationClaim(
                $operation->id, $operation->kind, $operation->references,
                $operation->fence + 1, $operation->attempts + 1, $operation->request_id,
            );
        });
    }

    /** @param Closure(): void $writeResult */
    public function complete(OperationClaim $claim, Closure $writeResult): void
    {
        DB::transaction(function () use ($claim, $writeResult): void {
            if ($this->currentClaim($claim)->lockForUpdate()->first() === null) {
                throw new LostOperationLease;
            }

            $writeResult();

            // Check the database clock again: a slow result transaction that
            // exceeds its lease must roll back its writes as well as success.
            $updated = $this->currentClaim($claim)->update([
                'state' => OperationState::Succeeded->value,
                'lease_expires_at' => null,
                'failure_code' => null,
                'completed_at' => DB::raw('clock_timestamp()'),
            ]);

            if ($updated !== 1) {
                throw new LostOperationLease;
            }
        });
    }

    private function fail(OperationClaim $claim, string $safeCode, bool $retryable): void
    {
        DB::transaction(function () use ($claim, $safeCode, $retryable): void {
            $operation = $this->currentClaim($claim)->lockForUpdate()->first();

            if ($operation === null) {
                return;
            }

            $retry = $retryable && $operation->attempts < $operation->max_attempts;
            $backoff = OperationPolicy::backoffSeconds($operation->kind, $operation->attempts);
            $delay = $backoff + random_int(0, max(1, intdiv($backoff, 5)));
            $state = $retry ? OperationState::Pending->value : OperationState::Failed->value;
            DB::update(<<<'SQL'
                UPDATE async_operations
                   SET state = ?, failure_code = ?, lease_expires_at = NULL,
                       last_dispatched_at = NULL,
                       next_attempt_at = clock_timestamp() + make_interval(secs => ?),
                       completed_at = CASE WHEN ? = 'failed' THEN clock_timestamp() ELSE NULL END,
                       updated_at = clock_timestamp()
                 WHERE id = ? AND state = 'running' AND fence = ?
                   AND lease_expires_at > clock_timestamp()
                SQL, [$state, $safeCode, $delay, $state, $claim->id, $claim->fence]);
        });
    }

    /** @return Builder<AsyncOperation> */
    private function currentClaim(OperationClaim $claim): Builder
    {
        return AsyncOperation::query()->whereKey($claim->id)
            ->where('state', OperationState::Running->value)
            ->where('fence', $claim->fence)
            ->where('lease_expires_at', '>', DB::raw('clock_timestamp()'));
    }
}
