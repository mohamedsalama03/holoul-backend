<?php

declare(strict_types=1);

namespace App\Modules\Categories\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property bool $active
 * @property int $display_order
 * @property int $lock_version
 */
abstract class TaxonomyModel extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'display_order' => 'integer', 'lock_version' => 'integer'];
    }
}
