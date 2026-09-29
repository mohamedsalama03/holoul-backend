<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Actions;

use App\Modules\PublicPortfolio\Contracts\PortfolioStorage;
use App\Modules\PublicPortfolio\Models\PortfolioAsset;
use App\Modules\PublicPortfolio\Models\PortfolioPublication;
use App\Modules\PublicPortfolio\PortfolioPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ReadPortfolio
{
    public function __construct(private PortfolioStorage $storage) {}

    /** @return array<string,mixed> */
    public function page(int $limit, ?string $category, ?string $cursor): array
    {
        $query = $this->published();
        if ($category !== null) {
            $query->where('category_id', $category);
        }
        if ($cursor !== null) {
            try {
                $decoded = json_decode(Crypt::decryptString($cursor), true, 4, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = null;
            }
            if (! is_array($decoded) || ($decoded['category'] ?? null) !== $category || ! is_string($decoded['id'] ?? null) || ! Str::isUuid($decoded['id'], 7)
                || ! is_string($decoded['time'] ?? null) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $decoded['time']) !== 1) {
                throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
            }
            $query->whereRaw('(published_at,id) < (?::timestamptz,?::uuid)', [$decoded['time'], $decoded['id']]);
        }
        $rows = $query->orderByDesc('published_at')->orderByDesc('id')->limit($limit + 1)->get();
        $page = $rows->take($limit);
        $last = $page->last();
        $next = $rows->count() > $limit && $last !== null ? Crypt::encryptString(json_encode(['id' => $last->id, 'category' => $category,
            'time' => $last->published_at->utc()->format('Y-m-d\TH:i:s.u\Z')], JSON_THROW_ON_ERROR)) : null;
        $data = $page->map(static function (PortfolioPublication $publication): array {
            $payload = $publication->payload;
            unset($payload['description'], $payload['images'], $payload['featured_image_id']);

            return $payload;
        })->values()->all();

        return ['data' => $data, 'meta' => ['next_cursor' => $next, 'limit' => $limit]];
    }

    /** @return array<string,mixed> */
    public function detail(string $publicId): array
    {
        if (! Str::isUuid($publicId)) {
            throw new HttpException(404);
        }
        $publication = $this->published()->whereExists(static fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')->from('portfolio_projects')
            ->whereColumn('portfolio_projects.publication_id', 'portfolio_publications.id')->where('portfolio_projects.public_id', $publicId))->first();

        return ($publication ?? throw new HttpException(404))->payload;
    }

    /** @return list<array{id:string,name:string,display_order:int,published_count:int}> */
    public function categories(): array
    {
        $counts = $this->published()->selectRaw('category_id,count(*)::integer AS count')->groupBy('category_id')->get()->pluck('count', 'category_id');
        $result = [];
        $order = 10;
        foreach (PortfolioPolicy::CATEGORIES as $id => $name) {
            $count = $counts->get($id, 0);
            $result[] = ['id' => $id, 'name' => $name, 'display_order' => $order, 'published_count' => is_int($count) ? $count : 0];
            $order += 10;
        }

        return $result;
    }

    public function image(string $publicImageId, string $variant): string
    {
        if (! Str::isUuid($publicImageId, 7) || ! in_array($variant, ['card', 'gallery'], true)) {
            throw new HttpException(404);
        }
        $query = DB::table('portfolio_public_images')->join('portfolio_projects', 'portfolio_projects.publication_id', '=', 'portfolio_public_images.publication_id')
            ->where('portfolio_public_images.id', $publicImageId);
        $reference = $query->first(['portfolio_public_images.asset_id']);
        if ($reference === null || ! is_string($reference->asset_id)) {
            throw new HttpException(404);
        }
        $asset = PortfolioAsset::query()->whereKey($reference->asset_id)->firstOrFail();
        $details = $asset->variants[$variant] ?? throw new HttpException(404);
        $bytes = $this->storage->get($asset->id, $variant, $details['version'], $details['sha256'], $details['size']);
        // Storage may be slow: a concurrent replacement/unpublication wins before delivery.
        if (! $query->exists()) {
            throw new HttpException(404);
        }

        return $bytes;
    }

    /** @return Builder<PortfolioPublication> */
    private function published(): Builder
    {
        return PortfolioPublication::query()->whereExists(static fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')->from('portfolio_projects')
            ->whereColumn('portfolio_projects.publication_id', 'portfolio_publications.id'));
    }
}
