<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Actions;

use App\Infrastructure\Async\OperationRecorder;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\PublicPortfolio\Data\PortfolioActor;
use App\Modules\PublicPortfolio\Models\PortfolioAsset;
use App\Modules\PublicPortfolio\Models\PortfolioProject;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class UploadPortfolioImage
{
    public function __construct(private ManagePortfolio $portfolio, private OperationRecorder $operations, private RecordAuditEvent $audit) {}

    public function authorize(PortfolioActor $actor, string $projectId, string $imageId, ?string $etag): PortfolioAsset
    {
        $asset = $this->portfolio->asset($actor, $projectId, $imageId, 'portfolio.manage');
        VersionPrecondition::require($etag, $asset->id, $asset->lock_version);
        if ($asset->owner_id !== $actor->id || $asset->state !== 'reserved' || $asset->expires_at->isPast()) {
            throw new HttpException(409);
        }

        return $asset;
    }

    public function finish(PortfolioActor $actor, string $projectId, string $imageId, ?string $etag, string $version, string $requestId): PortfolioAsset
    {
        $asset = $this->authorize($actor, $projectId, $imageId, $etag);
        $operation = $this->operations->record('portfolio.process_image', $asset->id, ['asset_id' => $asset->id], $requestId);
        $asset->forceFill(['source_version' => $version, 'state' => 'processing', 'operation_id' => $operation->id, 'lock_version' => $asset->lock_version + 1])->save();
        $project = PortfolioProject::query()->whereKey($projectId)->firstOrFail();
        $project->forceFill(['lock_version' => $project->lock_version + 1, 'updated_at' => now()])->save();
        $this->audit->handle('portfolio.image_received', 'portfolio.asset', $asset->id, $requestId, $actor->id);

        return $asset;
    }
}
