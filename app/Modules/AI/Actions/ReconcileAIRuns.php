<?php

declare(strict_types=1);

namespace App\Modules\AI\Actions;

use App\Infrastructure\Async\AsyncOperation;
use App\Infrastructure\Clock\DatabaseClock;
use App\Modules\AI\Models\AIRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** A dead worker at its final attempt must leave a visible, conservative terminal outcome. */
final readonly class ReconcileAIRuns
{
    public function __construct(private AIRuns $runs) {}

    public function handle(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Invalid reconciliation limit.');
        }
        $ids = AIRun::query()->where(function (Builder $query): void {
            $query->whereIn('state', ['pending', 'processing'])->orWhere(function (Builder $cancelled): void {
                $cancelled->where('state', 'cancelled')->where('reservation_state', 'uncertain')
                    ->whereIn('id', DB::table('ai_provider_attempts')->select('run_id')->where('outcome', 'started'));
            });
        })
            ->whereIn('operation_id', AsyncOperation::query()->select('id')->where('kind', 'ai.generate')->where('state', 'failed'))
            ->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id): int {
                $run = AIRun::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $cancelled = $run->state === 'cancelled' && $run->reservation_state === 'uncertain';
                if (! in_array($run->state, ['pending', 'processing'], true) && ! $cancelled) {
                    return 0;
                }
                $uncertain = $run->dispatch_fence !== null;
                $attempts = 0;
                if ($uncertain) {
                    $attempts = DB::table('ai_provider_attempts')->where('run_id', $run->id)->where('fence', $run->dispatch_fence)
                        ->where('outcome', 'started')->update(['outcome' => 'uncertain', 'failure_code' => 'provider_outcome_unknown',
                            'completed_at' => DatabaseClock::now()]);
                }
                if ($cancelled && $attempts === 0) {
                    return 0;
                }
                $this->runs->finish($run, 'failed', $uncertain ? 'provider_outcome_unknown' : 'attempts_exhausted', $uncertain, 0, $run->requested_correlation_id);

                return 1;
            });
        }

        return $count;
    }
}
