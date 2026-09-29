<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Actions;

use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Infrastructure\Async\PermanentOperationFailure;
use App\Modules\Audit\Actions\RecordAuditEvent;
use Closure;
use Illuminate\Support\Facades\DB;

/** The local edge has no shared cache. This records origin application, never claims a CDN purge. */
final readonly class ApplyOriginInvalidation implements OperationHandler
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function execute(OperationClaim $operation): Closure
    {
        $id = $operation->references['invalidation_id'] ?? throw new PermanentOperationFailure('portfolio_invalidation_missing');

        return function () use ($id, $operation): void {
            $changed = DB::table('portfolio_invalidations')->where('id', $id)->where('operation_id', $operation->id)->whereNull('origin_applied_at')
                ->update(['origin_applied_at' => DB::raw('clock_timestamp()')]);
            if ($changed === 1) {
                $this->audit->handle('portfolio.origin_invalidation_applied', 'portfolio.invalidation', $id, $operation->requestId ?? $operation->id);
            }
        };
    }
}
