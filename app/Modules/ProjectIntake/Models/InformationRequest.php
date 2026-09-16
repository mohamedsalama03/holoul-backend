<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $request_id
 * @property string $customer_id
 * @property string $origin_state
 * @property string $question
 * @property string $requested_by
 * @property CarbonImmutable $created_at
 */
final class InformationRequest extends Model
{
    use HasUuids;

    protected $table = 'information_requests';

    /** @var list<string> */
    protected $guarded = ['*'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
