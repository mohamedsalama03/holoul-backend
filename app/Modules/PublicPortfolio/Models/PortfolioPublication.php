<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $category_id
 * @property array<string,mixed> $payload
 * @property string $actor_id
 * @property CarbonImmutable $published_at
 */
final class PortfolioPublication extends Model
{
    protected $table = 'portfolio_publications';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['payload' => 'array', 'published_at' => 'immutable_datetime'];
    }
}
