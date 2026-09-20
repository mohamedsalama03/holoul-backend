<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $source_request_id
 * @property string $accepted_proposal_id
 * @property string $accepted_decision_id
 * @property int $accepted_proposal_version
 * @property string $customer_id
 * @property string $customer_user_id
 * @property string $reference
 * @property string $name
 * @property string $state
 * @property ?string $previous_phase
 * @property int $phase_epoch
 * @property int $lock_version
 * @property string $created_by
 */
final class Project extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['accepted_proposal_version' => 'integer', 'phase_epoch' => 'integer', 'lock_version' => 'integer'];
    }
}
