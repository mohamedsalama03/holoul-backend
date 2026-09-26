<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $request_id
 * @property string $capability_hash
 * @property string $session_hash
 * @property CarbonImmutable $expires_at
 * @property ?string $submission_key_hash
 * @property ?string $submission_input_hash
 * @property ?int $result_version
 * @property ?string $claim_token_hash
 * @property ?CarbonImmutable $claim_expires_at
 */
final class GuestAccess extends Model
{
    protected $table = 'intake_guest_access';

    protected $primaryKey = 'request_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['capability_hash', 'session_hash', 'claim_token_hash', 'submission_input_hash', 'submission_key_hash'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'claim_expires_at' => 'immutable_datetime', 'result_version' => 'integer'];
    }
}
