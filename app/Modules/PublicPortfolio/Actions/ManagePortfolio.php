<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Actions;

use App\Infrastructure\Async\OperationRecorder;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\PublicPortfolio\Data\PortfolioActor;
use App\Modules\PublicPortfolio\Models\PortfolioAsset;
use App\Modules\PublicPortfolio\Models\PortfolioProject;
use App\Modules\PublicPortfolio\Models\PortfolioPublication;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ManagePortfolio
{
    public function __construct(private RecordAuditEvent $audit, private OperationRecorder $operations) {}

    /** @param array<string,mixed> $input */
    public function create(PortfolioActor $actor, array $input, string $requestId): PortfolioProject
    {
        $this->transaction();
        $actor->require('portfolio.manage');
        $id = (string) Str::uuid7();
        $project = new PortfolioProject;
        $project->forceFill([...$input, 'id' => $id, 'public_id' => $id, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()])->save();
        $this->audit->handle('portfolio.created', 'portfolio.project', $id, $requestId, $actor->id);

        return $project;
    }

    public function project(PortfolioActor $actor, string $id, string $permission = 'portfolio.read'): PortfolioProject
    {
        $this->transaction();
        $actor->require($permission);
        if (! Str::isUuid($id, 7)) {
            throw new HttpException(404);
        }

        return PortfolioProject::query()->whereKey($id)->lockForUpdate()->first() ?? throw new HttpException(404);
    }

    /** @param array<string,mixed> $input */
    public function update(PortfolioActor $actor, string $id, array $input, ?string $etag, string $requestId): PortfolioProject
    {
        $project = $this->project($actor, $id, 'portfolio.manage');
        VersionPrecondition::require($etag, $id, $project->lock_version);
        foreach (['cover_image_id', 'featured_image_id'] as $field) {
            if (isset($input[$field]) && ! PortfolioAsset::query()->where('project_id', $id)->whereKey($input[$field])->whereNotIn('state', ['removed', 'expired'])->exists()) {
                throw ValidationException::withMessages([$field => 'The image must belong to this project.']);
            }
        }
        $project->forceFill($input);
        $this->advance($project);
        $this->audit->handle('portfolio.edited', 'portfolio.project', $id, $requestId, $actor->id);

        return $project;
    }

    /** @param array<string,mixed> $input */
    public function reserve(PortfolioActor $actor, string $id, array $input, ?string $etag, string $requestId): PortfolioAsset
    {
        $project = $this->project($actor, $id, 'portfolio.manage');
        VersionPrecondition::require($etag, $id, $project->lock_version);
        if (PortfolioAsset::query()->where('project_id', $id)->whereNotIn('state', ['removed', 'expired'])->count() >= 8) {
            throw new HttpException(409);
        }
        $asset = new PortfolioAsset;
        $asset->forceFill([...$input, 'id' => (string) Str::uuid7(), 'project_id' => $id, 'owner_id' => $actor->id,
            'state' => 'reserved', 'variants' => [], 'expires_at' => now()->addHour(), 'lock_version' => 1, 'created_at' => now()])->save();
        $this->advance($project);
        $this->audit->handle('portfolio.image_reserved', 'portfolio.asset', $asset->id, $requestId, $actor->id);

        return $asset;
    }

    public function asset(PortfolioActor $actor, string $projectId, string $assetId, string $permission = 'portfolio.read'): PortfolioAsset
    {
        $this->project($actor, $projectId, $permission);
        if (! Str::isUuid($assetId, 7)) {
            throw new HttpException(404);
        }

        return PortfolioAsset::query()->where('project_id', $projectId)->whereKey($assetId)->lockForUpdate()->first() ?? throw new HttpException(404);
    }

    public function remove(PortfolioActor $actor, string $id, string $imageId, ?string $etag, string $requestId): PortfolioProject
    {
        $actor->require('portfolio.manage', true);
        $project = $this->project($actor, $id, 'portfolio.manage');
        VersionPrecondition::require($etag, $id, $project->lock_version);
        $asset = $this->asset($actor, $id, $imageId, 'portfolio.manage');
        if ($project->publication_id !== null && DB::table('portfolio_public_images')->where('publication_id', $project->publication_id)->where('asset_id', $imageId)->exists()) {
            throw new HttpException(409);
        }
        if (in_array($asset->state, ['removed', 'expired'], true)) {
            throw new HttpException(409);
        }
        $asset->forceFill(['state' => 'removed', 'retired_at' => now(), 'lock_version' => $asset->lock_version + 1])->save();
        if ($project->cover_image_id === $imageId) {
            $project->cover_image_id = null;
        }
        if ($project->featured_image_id === $imageId) {
            $project->featured_image_id = null;
        }
        $this->advance($project);
        $this->audit->handle('portfolio.image_removed', 'portfolio.asset', $imageId, $requestId, $actor->id);

        return $project;
    }

    /** @return array<string,mixed> */
    public function publication(PortfolioActor $actor, string $id, bool $publish, ?string $etag, string $key, string $requestId): array
    {
        $project = $this->project($actor, $id, 'portfolio.publish');
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A UUID key is required.']);
        }
        $keyHash = hash_hmac('sha256', 'portfolio.publication:'.$actor->id.':'.strtolower($key), Config::string('app.key'));
        $inputHash = hash('sha256', json_encode([$id, $publish, $etag], JSON_THROW_ON_ERROR));
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$keyHash]);
        $stored = DB::table('portfolio_command_keys')->where('key_hash', $keyHash)->first();
        if ($stored !== null) {
            if (! is_string($stored->input_hash) || ! hash_equals($inputHash, $stored->input_hash) || ! is_string($stored->response)) {
                throw new HttpException(409);
            }
            $response = json_decode($stored->response, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($response)) {
                throw new LogicException('Invalid stored command result.');
            }

            $result = [];
            foreach ($response as $field => $value) {
                if (! is_string($field)) {
                    throw new LogicException('Invalid stored command field.');
                }
                $result[$field] = $value;
            }

            return $result;
        }
        VersionPrecondition::require($etag, $id, $project->lock_version);
        if ($publish) {
            $assets = PortfolioAsset::query()->where('project_id', $id)->whereNotIn('state', ['removed', 'expired'])->orderBy('display_order')->orderBy('id')->lockForUpdate()->get();
            if ($assets->isEmpty() || $assets->count() > 8 || $assets->contains(fn (PortfolioAsset $asset): bool => $asset->state !== 'ready')
                || ! $assets->contains('id', $project->cover_image_id) || ! $assets->contains('id', $project->featured_image_id)) {
                throw new HttpException(409);
            }
            $publicationId = (string) Str::uuid7();
            $images = [];
            $mapping = [];
            foreach ($assets as $asset) {
                $publicImage = (string) Str::uuid7();
                $mapping[$asset->id] = $publicImage;
                $variants = [];
                foreach (['card', 'gallery'] as $name) {
                    $variant = $asset->variants[$name] ?? throw new LogicException('Ready variant missing.');
                    $variants[] = ['name' => $name, 'url' => '/api/v1/public/portfolio/images/'.$publicImage.'/'.$name,
                        'width' => $variant['width'], 'height' => $variant['height'], 'media_type' => 'image/webp'];
                }
                $images[] = ['id' => $publicImage, 'alt' => $asset->alt, 'variants' => $variants];
            }
            $cover = null;
            foreach ($images as $image) {
                if ($image['id'] === $mapping[$project->cover_image_id]) {
                    $cover = $image;
                }
            }
            $publishedAt = now()->toImmutable();
            $snapshot = new PortfolioPublication;
            $snapshot->forceFill(['id' => $publicationId, 'project_id' => $id, 'category_id' => $project->category_id,
                'actor_id' => $actor->id, 'published_at' => $publishedAt,
                'payload' => ['id' => $project->public_id, 'title' => $project->title, 'summary' => $project->summary,
                    'category_id' => $project->category_id, 'description' => $project->description, 'cover_image' => $cover,
                    'images' => $images, 'featured_image_id' => $mapping[$project->featured_image_id],
                    'updated_at' => $publishedAt->utc()->format('Y-m-d\TH:i:s.u\Z')]])->save();
            foreach ($mapping as $assetId => $publicImage) {
                DB::table('portfolio_public_images')->insert(['id' => $publicImage, 'project_id' => $id, 'publication_id' => $publicationId, 'asset_id' => $assetId]);
            }
            $project->publication_id = $publicationId;
        } else {
            if ($project->publication_id === null) {
                throw new HttpException(409);
            }
            $project->publication_id = null;
        }
        $this->advance($project);
        $intentId = (string) Str::uuid7();
        DB::table('portfolio_invalidations')->insert(['id' => $intentId, 'project_id' => $id, 'publication_id' => $project->publication_id]);
        $operation = $this->operations->record('portfolio.invalidate', $intentId, ['invalidation_id' => $intentId], $requestId);
        DB::table('portfolio_invalidations')->where('id', $intentId)->update(['operation_id' => $operation->id]);
        $this->audit->handle($publish ? 'portfolio.published' : 'portfolio.unpublished', 'portfolio.project', $id, $requestId, $actor->id);
        $response = $this->record($project);
        DB::table('portfolio_command_keys')->insert(['key_hash' => $keyHash, 'input_hash' => $inputHash, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'project_id' => $id]);

        return $response;
    }

    /** @return array<string,mixed> */
    public function record(PortfolioProject $project): array
    {
        return ['id' => $project->id, 'public_id' => $project->public_id, 'title' => $project->title, 'summary' => $project->summary,
            'description' => $project->description, 'category_id' => $project->category_id,
            'cover_image_id' => $project->cover_image_id, 'featured_image_id' => $project->featured_image_id,
            'publication_id' => $project->publication_id, 'status' => $project->publication_id === null ? 'draft' : 'published',
            'etag' => VersionPrecondition::etag($project->id, $project->lock_version),
            'created_at' => $project->created_at->toISOString(), 'updated_at' => $project->updated_at->toISOString(),
            'images' => PortfolioAsset::query()->where('project_id', $project->id)->whereNotIn('state', ['removed', 'expired'])->orderBy('display_order')->orderBy('id')
                ->get()->map(fn (PortfolioAsset $asset): array => $this->imageRecord($asset))->all()];
    }

    /** @return array<string,mixed> */
    public function imageRecord(PortfolioAsset $asset): array
    {
        return ['id' => $asset->id, 'project_id' => $asset->project_id, 'state' => $asset->state, 'alt' => $asset->alt,
            'display_order' => $asset->display_order, 'media_type' => $asset->media_type, 'byte_size' => $asset->byte_size,
            'sha256' => $asset->sha256, 'failure_code' => $asset->failure_code, 'expires_at' => $asset->expires_at->toISOString(),
            'etag' => VersionPrecondition::etag($asset->id, $asset->lock_version)];
    }

    /** @return array<string,mixed> */
    public function page(PortfolioActor $actor, int $limit, ?string $status, ?string $cursor): array
    {
        $actor->require('portfolio.read');
        $query = PortfolioProject::query();
        if ($status !== null) {
            $status === 'published' ? $query->whereNotNull('publication_id') : $query->whereNull('publication_id');
        }
        if ($cursor !== null) {
            try {
                $decoded = json_decode(Crypt::decryptString($cursor), true, 4, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = null;
            }
            if (! is_array($decoded) || ($decoded['status'] ?? null) !== $status || ! is_string($decoded['id'] ?? null) || ! Str::isUuid($decoded['id'], 7)) {
                throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
            }
            $query->where('id', '<', $decoded['id']);
        }
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $page = $rows->take($limit);
        $last = $page->last();
        $next = $rows->count() > $limit && $last !== null ? Crypt::encryptString(json_encode(['id' => $last->id, 'status' => $status], JSON_THROW_ON_ERROR)) : null;
        // Bounded lightweight list; detail owns images and the long description.
        $data = $page->map(static fn (PortfolioProject $project): array => ['id' => $project->id, 'public_id' => $project->public_id,
            'title' => $project->title, 'summary' => $project->summary, 'category_id' => $project->category_id,
            'status' => $project->publication_id === null ? 'draft' : 'published', 'updated_at' => $project->updated_at->toISOString(),
            'etag' => VersionPrecondition::etag($project->id, $project->lock_version)])->values()->all();

        return ['data' => $data, 'meta' => ['next_cursor' => $next, 'limit' => $limit]];
    }

    private function advance(PortfolioProject $project): void
    {
        $project->lock_version++;
        $project->updated_at = now()->toImmutable();
        $project->save();
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Portfolio mutations require a transaction.');
        }
    }
}
