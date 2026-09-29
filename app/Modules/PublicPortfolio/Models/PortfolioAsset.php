<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $owner_id
 * @property string $state
 * @property string $media_type
 * @property int $byte_size
 * @property string $sha256
 * @property string $alt
 * @property int $display_order
 * @property ?string $source_version
 * @property array<string,array{version:string,sha256:string,size:int,width:int,height:int}> $variants
 * @property ?string $failure_code
 * @property ?string $operation_id
 * @property int $lock_version
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 */
final class PortfolioAsset extends Model
{
    protected $table = 'portfolio_assets';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['byte_size' => 'integer', 'display_order' => 'integer', 'lock_version' => 'integer', 'variants' => 'array', 'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
