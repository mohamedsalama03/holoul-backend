<?php

declare(strict_types=1);

namespace App\Modules\Identity\Recovery\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $recovery_token_id
 * @property string $operation_id
 * @property string $state
 * @property string|null $encrypted_payload
 * @property int|null $send_fence
 * @property CarbonImmutable|null $send_started_at
 * @property CarbonImmutable|null $sent_at
 * @property string|null $failure_code
 */
final class RecoveryMail extends Model
{
    public $incrementing = false;

    protected $table = 'identity_recovery_mail';

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['encrypted_payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'send_fence' => 'integer',
            'send_started_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
