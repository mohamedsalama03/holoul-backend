<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Infrastructure\Async\AsyncOperation;
use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ReconcileNotifications
{
    public function __construct(private DeliverNotification $delivery) {}

    public function handle(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Invalid notification reconciliation limit.');
        }
        $ids = DB::table('notification_deliveries as d')->join('async_operations as o', 'o.id', '=', 'd.operation_id')
            ->whereIn('d.state', ['pending', 'processing'])->where(function (Builder $q): void {
                $q->whereIn('o.state', ['failed', 'succeeded'])->orWhere(function (Builder $expired): void {
                    $expired->where('d.state', 'processing')->where(function (Builder $inactive): void {
                        $inactive->where('o.state', 'pending')->orWhere('o.lease_expires_at', '<=', DB::raw('clock_timestamp()'));
                    });
                });
            })->orderBy('d.id')->limit($limit)->pluck('d.id')->all();
        $count = 0;
        foreach ($ids as $id) {
            if (! is_string($id)) {
                continue;
            }
            $count += DB::transaction(function () use ($id): int {
                $candidate = NotificationDelivery::query()->findOrFail($id);
                $operation = AsyncOperation::query()->whereKey($candidate->operation_id)->lockForUpdate()->firstOrFail();
                $delivery = NotificationDelivery::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($delivery->operation_id !== $operation->id || ! in_array($delivery->state, ['pending', 'processing'], true)) {
                    return 0;
                }
                if ($operation->state->value === 'running' && DB::table('async_operations')->where('id', $operation->id)->where('lease_expires_at', '>', DB::raw('clock_timestamp()'))->exists()) {
                    return 0;
                }
                if ($delivery->state === 'processing') {
                    $this->delivery->uncertain($delivery);
                } elseif ($operation->state->value === 'failed') {
                    $delivery->forceFill(['state' => 'failed', 'failure_code' => 'attempts_exhausted', 'completed_at' => DB::raw('clock_timestamp()'), 'lock_version' => $delivery->lock_version + 1])->save();
                } else {
                    return 0;
                }

                return 1;
            });
        }

        return $count;
    }
}
