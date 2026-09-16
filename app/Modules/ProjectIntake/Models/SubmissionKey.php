<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $actor_id
 * @property string $key_hash
 * @property string $input_hash
 * @property string $request_id
 * @property ?string $revision_id
 * @property ?int $revision_number
 * @property ?int $result_version
 * @property ?string $result_state
 * @property ?string $reference
 * @property CarbonImmutable $expires_at
 */
final class SubmissionKey extends Model
{
    use HasUuids;

    protected $table = 'intake_submission_keys';

    /** @var list<string> */
    protected $guarded = ['*'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision_number' => 'integer', 'result_version' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
