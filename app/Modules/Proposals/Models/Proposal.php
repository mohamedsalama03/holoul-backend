<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $request_id
 * @property string $customer_id
 * @property string $discovery_revision_id
 * @property int $revision_number
 * @property string $state
 * @property ?string $number
 * @property int $lock_version
 * @property int $content_version
 * @property string $scope_summary
 * @property string $timeline
 * @property string $commercial_notes
 * @property string $pricing_mode
 * @property int $amount_minor
 * @property string $currency
 * @property CarbonImmutable $valid_until
 * @property string $author_id
 * @property ?string $current_approval_id
 * @property ?CarbonImmutable $issued_at
 * @property ?string $issued_by
 */
final class Proposal extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['revision_number' => 'integer', 'lock_version' => 'integer', 'content_version' => 'integer', 'amount_minor' => 'integer',
            'valid_until' => 'immutable_datetime', 'issued_at' => 'immutable_datetime'];
    }
}
