<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Models;

use App\Modules\ProjectIntake\Data\RequestState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $guest_origin
 * @property string $id
 * @property ?string $customer_id
 * @property ?string $customer_user_id
 * @property RequestState $state
 * @property ?string $reference
 * @property int $lock_version
 * @property int $latest_revision_number
 * @property ?string $latest_revision_id
 * @property ?string $assigned_staff_id
 * @property ?string $information_request_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property ?CarbonImmutable $submitted_at
 */
final class ProjectRequest extends Model
{
    use HasUuids;

    protected $table = 'project_requests';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['guest_origin' => 'boolean', 'state' => RequestState::class, 'lock_version' => 'integer', 'latest_revision_number' => 'integer', 'submitted_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
