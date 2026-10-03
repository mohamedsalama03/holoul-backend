<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Authorization\StaffAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\IdentityInput;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final readonly class CreateStaff
{
    public function __construct(private StaffAccess $access, private RoleAuthority $authority,
        private StaffGrantPolicy $grants, private RecordAuditEvent $audit) {}

    /** @param list<Role> $roles */
    public function handle(Request $request, string $username, string $email, string $name,
        #[SensitiveParameter] string $password, array $roles, string $key): User
    {
        $actor = $this->access->requirePermission($request, Permission::ReadStaff);
        $this->access->requirePermission($request, Permission::ManageStaff);
        StaffInput::recent($request);
        $this->grants->check($actor, $roles);
        if (preg_match('/\A[a-zA-Z0-9_-]{16,128}\z/D', $key) !== 1) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Invalid key.']);
        }
        // A keyed digest binds secret input without storing a password or an offline password oracle.
        $hash = hash_hmac('sha256', json_encode([$username, $email, $name, $password,
            array_map(static fn (Role $r): string => $r->value, $roles)], JSON_THROW_ON_ERROR), Config::string('app.key'));
        $passwordHash = Hash::make($password);
        try {
            return DB::transaction(function () use ($request, $actor, $username, $email, $name, $passwordHash, $roles, $key, $hash): User {
                $this->authority->lockChanges();
                $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $this->access->requirePermission($request, Permission::ReadStaff);
                $this->access->requirePermission($request, Permission::ManageStaff, recentPassword: true);
                $this->grants->check($actor, $roles);
                $existing = DB::table('identity_staff_creation_keys')->where('actor_id', $actor->id)->where('key_hash', hash('sha256', $key))->first();
                if ($existing !== null) {
                    if (! is_string($existing->input_hash) || ! hash_equals($existing->input_hash, $hash)) {
                        throw new StaffFailure(409, 'IDEMPOTENCY_KEY_REUSED');
                    }

                    return User::query()->findOrFail(StaffData::text($existing->user_id));
                }
                if (User::query()->where('username', $username)->exists()) {
                    throw new StaffFailure(409, 'USERNAME_UNAVAILABLE');
                }
                $other = User::query()->where('email', $email)->first();
                if ($other !== null) {
                    throw new StaffFailure(409, $other->kind === 'staff' ? 'EMAIL_ALREADY_STAFF' : 'EMAIL_ALREADY_CUSTOMER');
                }
                $pending = StaffInvitation::query()->where('email', $email)->where('status', 'pending')->where('expires_at', '>', DB::raw('clock_timestamp()'))->first();
                if ($pending !== null) {
                    throw new StaffFailure(409, 'INVITATION_ALREADY_PENDING', $pending->id);
                }
                $user = User::query()->create(['username' => $username, 'email' => $email,
                    'email_display' => $email, 'full_name' => $name, 'password' => $passwordHash, 'kind' => 'staff', 'enabled' => true]);
                $requestId = IdentityInput::requestId($request);
                foreach ($roles as $role) {
                    DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $this->authority->roleId($role), 'user_kind' => 'staff']);
                    $this->audit->handle('identity.role.'.$role->value.'.granted', 'user', $user->id, $requestId, $actor->id);
                }
                DB::table('identity_staff_creation_keys')->insert(['actor_id' => $actor->id, 'key_hash' => hash('sha256', $key),
                    'input_hash' => $hash, 'user_id' => $user->id]);
                $this->audit->handle('identity.staff_account.created', 'user', $user->id, $requestId, $actor->id);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent public registration also owns the unique email constraint.
            throw new StaffFailure(409, 'ACCOUNT_UNAVAILABLE');
        }
    }
}
