<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Modules\Identity\Authorization\Role;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string $email
 * @property string $full_name
 * @property string $inviter_name
 * @property string $invited_by
 * @property string $status
 * @property string|null $token_hash
 * @property int $generation
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface $last_requested_at
 * @property CarbonInterface|null $last_sent_at
 * @property int $send_count
 * @property CarbonInterface|null $accepted_at
 * @property string|null $accepted_user_id
 * @property CarbonInterface|null $activated_at
 * @property CarbonInterface|null $revoked_at
 */
final class StaffInvitation extends Model
{
    protected $table = 'identity_staff_invitations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['generation' => 'integer', 'lock_version' => 'integer', 'send_count' => 'integer',
            'created_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'last_requested_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    /** @return list<Role> */
    public function offeredRoles(): array
    {
        return array_values(DB::table('identity_staff_invitation_roles')->join('roles', 'roles.id', '=', 'identity_staff_invitation_roles.role_id')
            ->where('invitation_id', $this->id)->orderBy('roles.code')->pluck('roles.code')->map(static function (mixed $v): Role {
                if (! is_string($v)) {
                    throw new \LogicException('Invalid role catalog.');
                }

                return Role::from($v);
            })->values()->all());
    }
}
