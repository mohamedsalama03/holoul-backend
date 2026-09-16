<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mfa;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property ?string $secret
 * @property ?string $pending_secret
 * @property ?CarbonImmutable $pending_expires_at
 * @property ?CarbonImmutable $confirmed_at
 * @property ?int $last_accepted_step
 */
final class MfaCredential extends Model
{
    use HasUuids;

    protected $table = 'identity_mfa';

    protected $guarded = ['id'];

    protected $hidden = ['secret', 'pending_secret', 'last_accepted_step'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'pending_secret' => 'encrypted',
            'pending_expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'last_accepted_step' => 'integer',
        ];
    }
}
