<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class OperationReconciler
{
    public function __construct(private OperationPublisher $publisher) {}

    /** Republish a bounded page, including jobs previously accepted then lost by Redis. */
    public function reconcile(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Reconciliation limit must be between 1 and 1000.');
        }

        $staleSeconds = Config::integer('async.republish_seconds');
        $operations = AsyncOperation::query()
            ->where(function (Builder $query): void {
                $query->where(function (Builder $pending): void {
                    $pending->where('state', OperationState::Pending->value)
                        ->where('next_attempt_at', '<=', DB::raw('clock_timestamp()'));
                })->orWhere(function (Builder $running): void {
                    $running->where('state', OperationState::Running->value)
                        ->where('lease_expires_at', '<=', DB::raw('clock_timestamp()'));
                });
            })
            ->where(function (Builder $query) use ($staleSeconds): void {
                $query->whereNull('last_dispatched_at')
                    ->orWhereRaw('last_dispatched_at <= clock_timestamp() - make_interval(secs => ?)', [$staleSeconds]);
            })
            ->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->get(['id']);

        $published = 0;

        foreach ($operations as $operation) {
            if (! $this->publisher->publish($operation->id)) {
                // Bound outage latency and avoid a page of repeated failures.
                break;
            }

            $published++;
        }

        return $published;
    }
}
