<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $storage_key
 * @property string $storage_version
 * @property CarbonImmutable $object_modified_at
 * @property string $state
 * @property string|null $operation_id
 */
final class OrphanObject extends Model
{
    protected $table = 'document_orphan_objects';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['object_modified_at' => 'immutable_datetime'];
    }
}
