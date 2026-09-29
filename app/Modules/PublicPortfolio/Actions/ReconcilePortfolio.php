<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\PublicPortfolio\Contracts\PortfolioStorage;
use App\Modules\PublicPortfolio\Models\PortfolioAsset;
use App\Modules\PublicPortfolio\Models\PortfolioProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ReconcilePortfolio
{
    public function __construct(private PortfolioStorage $storage, private RecordAuditEvent $audit) {}

    /** @return array{expired:int,purged:int} */
    public function handle(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Limit must be 1..100.');
        }
        $expired = 0;
        $purged = 0;
        $ids = PortfolioAsset::query()->where('state', 'reserved')->where('expires_at', '<=', DB::raw('clock_timestamp()'))->orderBy('id')->limit($limit)->get(['id', 'project_id']);
        foreach ($ids as $candidate) {
            $expired += DB::transaction(function () use ($candidate): int {
                $project = PortfolioProject::query()->whereKey($candidate->project_id)->lockForUpdate()->firstOrFail();
                $asset = PortfolioAsset::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if ($asset->state !== 'reserved' || $asset->expires_at->isFuture()) {
                    return 0;
                }
                $asset->forceFill(['state' => 'expired', 'retired_at' => now(), 'lock_version' => $asset->lock_version + 1])->save();
                if ($project->cover_image_id === $asset->id) {
                    $project->cover_image_id = null;
                }
                if ($project->featured_image_id === $asset->id) {
                    $project->featured_image_id = null;
                }
                $project->forceFill(['lock_version' => $project->lock_version + 1, 'updated_at' => now()])->save();
                $this->audit->handle('portfolio.reservation_expired', 'portfolio.asset', $asset->id, (string) Str::uuid7());

                return 1;
            });
        }
        // Terminal infrastructure failures must not strand a processing reservation.
        $failed = PortfolioAsset::query()->where('state', 'processing')->whereIn('operation_id',
            DB::table('async_operations')->where('state', 'failed')->select('id'))->orderBy('id')->limit($limit)->get();
        foreach ($failed as $candidate) {
            DB::transaction(function () use ($candidate): void {
                $operation = DB::table('async_operations')->where('id', $candidate->operation_id)->where('state', 'failed')->lockForUpdate()->first();
                if ($operation === null) {
                    return;
                }
                $project = PortfolioProject::query()->whereKey($candidate->project_id)->lockForUpdate()->firstOrFail();
                $asset = PortfolioAsset::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if ($asset->state !== 'processing') {
                    return;
                }
                $asset->forceFill(['state' => 'rejected', 'failure_code' => 'processing_failed', 'retired_at' => now(), 'lock_version' => $asset->lock_version + 1])->save();
                $project->forceFill(['lock_version' => $project->lock_version + 1, 'updated_at' => now()])->save();
                $this->audit->handle('portfolio.image_rejected', 'portfolio.asset', $asset->id, (string) Str::uuid7());
            });
        }
        // The grace period exceeds the bounded HTTP/storage/processor deadlines.
        // Retired states cannot be revived, including when cleanup races a late completion.
        $retired = PortfolioAsset::query()->whereIn('state', ['removed', 'expired', 'rejected'])->whereNull('purged_at')
            ->whereRaw("retired_at < clock_timestamp() - interval '24 hours'")->orderBy('id')->limit($limit)->get();
        foreach ($retired as $asset) {
            if (DB::table('portfolio_public_images')->join('portfolio_projects', 'portfolio_projects.publication_id', '=', 'portfolio_public_images.publication_id')
                ->where('asset_id', $asset->id)->exists()) {
                continue;
            }
            $this->storage->purge($asset->id);
            $purged += DB::transaction(function () use ($asset): int {
                $current = PortfolioAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
                if ($current->getAttribute('purged_at') !== null) {
                    return 0;
                }
                $current->forceFill(['purged_at' => now(), 'lock_version' => $current->lock_version + 1])->save();
                $this->audit->handle('portfolio.retired_bytes_purged', 'portfolio.asset', $current->id, (string) Str::uuid7());

                return 1;
            });
        }

        return ['expired' => $expired, 'purged' => $purged];
    }
}
