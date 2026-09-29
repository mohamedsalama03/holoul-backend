<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $public_id
 * @property string $title
 * @property string $summary
 * @property string $description
 * @property string $category_id
 * @property ?string $cover_image_id
 * @property ?string $featured_image_id
 * @property ?string $publication_id
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PortfolioProject extends Model
{
    protected $table = 'portfolio_projects';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
