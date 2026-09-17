<?php

declare(strict_types=1);

namespace App\Modules\Documents\Processing;

use App\Infrastructure\Async\AsyncOperation;
use App\Infrastructure\Async\LostOperationLease;
use App\Infrastructure\Async\OperationClaim;
use Illuminate\Support\Facades\DB;
use LogicException;

final class DocumentOperationFence
{
    public static function requireOutsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Document external effects cannot run inside a transaction.');
        }
    }

    public static function lock(OperationClaim $operation): void
    {
        if (AsyncOperation::query()->whereKey($operation->id)->where('state', 'running')->where('fence', $operation->fence)
            ->where('lease_expires_at', '>', DB::raw('clock_timestamp()'))->lockForUpdate()->first() === null) {
            throw new LostOperationLease;
        }
    }
}
