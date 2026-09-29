<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Actions;

use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Infrastructure\Async\PermanentOperationFailure;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\PublicPortfolio\Contracts\ImageProcessor;
use App\Modules\PublicPortfolio\Contracts\PortfolioAuthority;
use App\Modules\PublicPortfolio\Contracts\PortfolioStorage;
use App\Modules\PublicPortfolio\Models\PortfolioAsset;
use App\Modules\PublicPortfolio\Models\PortfolioProject;
use Closure;

final readonly class ProcessPortfolioImage implements OperationHandler
{
    public function __construct(private PortfolioStorage $storage, private ImageProcessor $processor, private PortfolioAuthority $authority, private RecordAuditEvent $audit) {}

    public function execute(OperationClaim $operation): Closure
    {
        $id = $operation->references['asset_id'] ?? throw new PermanentOperationFailure('portfolio_asset_missing');
        $asset = PortfolioAsset::query()->whereKey($id)->first() ?? throw new PermanentOperationFailure('portfolio_asset_missing');
        if ($asset->state !== 'processing' || $asset->operation_id !== $operation->id || $asset->source_version === null) {
            return static function (): void {};
        }
        $source = $this->storage->get($id, 'source', $asset->source_version, $asset->sha256, $asset->byte_size);
        $processed = $this->processor->process($source, $asset->media_type);
        $variants = [];
        if ($processed->rejection === null) {
            foreach ($processed->variants as $name => $variant) {
                $version = $this->storage->put($id, $name, $variant['bytes']);
                $variants[$name] = ['version' => $version, 'sha256' => hash('sha256', $variant['bytes']), 'size' => strlen($variant['bytes']),
                    'width' => $variant['width'], 'height' => $variant['height']];
            }
            if (array_keys($variants) !== ['card', 'gallery']) {
                throw new PermanentOperationFailure('portfolio_invalid_variants');
            }
        }

        return function () use ($asset, $operation, $processed, $variants): void {
            $allowed = $this->authority->lockManager($asset->owner_id);
            $project = PortfolioProject::query()->whereKey($asset->project_id)->lockForUpdate()->firstOrFail();
            $current = PortfolioAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ($current->state !== 'processing' || $current->operation_id !== $operation->id || $current->source_version !== $asset->source_version) {
                return;
            }
            $failure = $allowed ? $processed->rejection : 'authority_revoked';
            $current->forceFill(['state' => $failure === null ? 'ready' : 'rejected', 'failure_code' => $failure, 'retired_at' => $failure === null ? null : now(),
                'variants' => $failure === null ? $variants : [], 'lock_version' => $current->lock_version + 1])->save();
            $project->forceFill(['lock_version' => $project->lock_version + 1, 'updated_at' => now()])->save();
            $this->audit->handle($failure === null ? 'portfolio.image_ready' : 'portfolio.image_rejected', 'portfolio.asset', $current->id, $operation->requestId ?? $operation->id);
            // Processing never changes a project's publication pointer.
        };
    }
}
