<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $state
 * @property int $lock_version
 * @property bool $customer_visible
 */
final class Milestone extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'display_order' => 'integer', 'customer_visible' => 'boolean'];
    }
}
