<?php

declare(strict_types=1);

namespace App\Modules\Discovery\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $discovery_id
 * @property string $request_id
 * @property string $customer_id
 * @property int $revision_number
 * @property string $source_intake_revision_id
 * @property string $state
 * @property int $lock_version
 * @property string $summary
 * @property string $internal_notes
 * @property string $author_id
 * @property ?CarbonImmutable $completed_at
 */
final class DiscoveryRevision extends Model
{
    use HasUuids;

    protected $table = 'discovery_revisions';

    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['revision_number' => 'integer', 'lock_version' => 'integer', 'completed_at' => 'immutable_datetime'];
    }
}
