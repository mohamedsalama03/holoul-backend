<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\PublicPortfolio\Data\LegacyProject;
use App\Modules\PublicPortfolio\Data\PortfolioActor;
use App\Modules\PublicPortfolio\Models\PortfolioProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ImportPortfolio
{
    public function __construct(private ManagePortfolio $manage, private RecordAuditEvent $audit) {}

    /** @return array{project_id:string,images:array<string,string>} */
    public function reserve(PortfolioActor $actor, LegacyProject $source, string $requestId): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Import requires an authorized transaction.');
        }
        $actor->require('portfolio.manage');
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['portfolio.import:'.$source->id]);
        $existing = DB::table('portfolio_imports')->where('source_id', $source->id)->first();
        if ($existing !== null) {
            if ($existing->manifest_hash !== $source->hash || ! is_string($existing->project_id) || ! is_string($existing->image_map)) {
                throw new HttpException(409);
            }
            $decoded = json_decode($existing->image_map, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new LogicException('Invalid import mapping.');
            }
            $images = [];
            foreach ($decoded as $old => $new) {
                if (! is_string($old) || ! is_string($new)) {
                    throw new LogicException('Invalid import identity.');
                }
                $images[$old] = $new;
            }

            return ['project_id' => $existing->project_id, 'images' => $images];
        }
        $project = new PortfolioProject;
        $project->forceFill(['id' => (string) Str::uuid7(), 'public_id' => $source->id, 'title' => $source->title, 'summary' => $source->summary,
            'description' => $source->description, 'category_id' => $source->category, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()])->save();
        $mapping = [];
        foreach ($source->images as $image) {
            $project->refresh();
            $asset = $this->manage->reserve($actor, $project->id, ['media_type' => 'image/webp', 'byte_size' => $image->size, 'sha256' => $image->sha256, 'alt' => $image->alt, 'display_order' => $image->order],
                '"'.$project->id.':'.$project->lock_version.'"', $requestId);
            $mapping[$image->id] = $asset->id;
        }
        $project->refresh();
        if ($mapping !== []) {
            $first = reset($mapping);
            $this->manage->update($actor, $project->id, ['cover_image_id' => $first, 'featured_image_id' => $first], '"'.$project->id.':'.$project->lock_version.'"', $requestId);
        }
        DB::table('portfolio_imports')->insert(['source_id' => $source->id, 'project_id' => $project->id, 'manifest_hash' => $source->hash, 'source_status' => $source->sourceStatus, 'image_map' => json_encode($mapping, JSON_THROW_ON_ERROR)]);
        $this->audit->handle('portfolio.legacy_imported', 'portfolio.project', $project->id, $requestId, $actor->id);

        return ['project_id' => $project->id, 'images' => $mapping];
    }
}
