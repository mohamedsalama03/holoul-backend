<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $session_hash
 * @property int $auth_version
 * @property CarbonImmutable $authenticated_at
 * @property CarbonImmutable $last_activity_at
 * @property CarbonImmutable $expires_at
 */
final class IdentitySession extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'identity_sessions';

    protected $guarded = [];

    protected $hidden = ['session_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['auth_version' => 'integer', 'authenticated_at' => 'immutable_datetime', 'last_activity_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
