<?php

declare(strict_types=1);

namespace App\Modules\Identity\Recovery\Models;

use App\Modules\Identity\Recovery\RecoveryPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property RecoveryPurpose $purpose
 * @property string $token_hash
 * @property string $email_hash
 * @property int $auth_version
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $revoked_at
 */
final class RecoveryToken extends Model
{
    public $incrementing = false;

    protected $table = 'identity_recovery_tokens';

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['token_hash', 'email_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => RecoveryPurpose::class,
            'auth_version' => 'integer',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
