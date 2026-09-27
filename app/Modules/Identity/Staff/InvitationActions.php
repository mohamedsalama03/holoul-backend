<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Infrastructure\Async\OperationRecorder;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Authorization\StaffAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\IdentityInput;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final readonly class InvitationActions
{
    public function __construct(private StaffAccess $access, private RoleAuthority $authority,
        private StaffGrantPolicy $grants, private RecordAuditEvent $audit, private OperationRecorder $operations) {}

    /** @param list<Role> $roles */
    public function issue(Request $request, string $email, string $name, array $roles, string $key): StaffInvitation
    {
        $actor = $this->access->requirePermission($request, Permission::ReadStaff);
        $this->access->requirePermission($request, Permission::ManageStaff);
        StaffInput::recent($request);
        if (preg_match('/\A[a-zA-Z0-9_-]{16,128}\z/D', $key) !== 1) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Invalid key.']);
        }
        $email = IdentityInput::email($email);
        $name = IdentityInput::name($name);
        $hash = hash('sha256', json_encode([$email, $name, array_map(static fn (Role $r): string => $r->value, $roles)], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $actor, $email, $name, $roles, $key, $hash): StaffInvitation {
            $this->authority->lockChanges();
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->access->requirePermission($request, Permission::ManageStaff, recentPassword: true);
            $this->grants->check($actor, $roles);
            $existing = DB::table('identity_staff_invitation_keys')->where('actor_id', $actor->id)->where('key_hash', hash('sha256', $key))->first();
            if ($existing !== null) {
                if (! is_string($existing->input_hash) || ! hash_equals($existing->input_hash, $hash)) {
                    throw new StaffFailure(409, 'IDEMPOTENCY_KEY_REUSED');
                }

                return StaffInvitation::query()->whereKey(StaffData::text($existing->invitation_id))->firstOrFail();
            }
            $this->emailAvailable($email);
            $this->expireEmail($email, IdentityInput::requestId($request));
            $pending = StaffInvitation::query()->where('email', $email)->where('status', 'pending')->first();
            if ($pending !== null) {
                throw new StaffFailure(409, 'INVITATION_ALREADY_PENDING', $pending->id);
            }
            $invite = StaffInvitation::query()->create(['id' => (string) Str::uuid7(), 'email' => $email, 'full_name' => $name,
                'invited_by' => $actor->id, 'inviter_name' => $actor->full_name, 'expires_at' => now()->addDays(7)]);
            foreach ($roles as $role) {
                DB::table('identity_staff_invitation_roles')->insert(['invitation_id' => $invite->id, 'role_id' => $this->authority->roleId($role)]);
            }
            $invite->setAttribute('roles_sealed', true);
            $invite->save();
            DB::table('identity_staff_invitation_keys')->insert(['actor_id' => $actor->id, 'key_hash' => hash('sha256', $key), 'input_hash' => $hash, 'invitation_id' => $invite->id]);
            $this->enqueue($invite->refresh(), IdentityInput::requestId($request));
            $this->audit->handle('identity.staff_invitation.issued', 'identity.staff_invitation', $invite->id, IdentityInput::requestId($request), $actor->id);

            return $invite;
        });
    }

    public function change(Request $request, string $id, int $version, bool $resend): StaffInvitation
    {
        $actor = $this->access->requirePermission($request, Permission::ReadStaff);
        $this->access->requirePermission($request, Permission::ManageStaff);
        StaffInput::recent($request);
        if (! Str::isUuid($id)) {
            throw new StaffFailure(404, 'NOT_FOUND');
        }

        return DB::transaction(function () use ($request, $actor, $id, $version, $resend): StaffInvitation {
            $this->authority->lockChanges();
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->access->requirePermission($request, Permission::ManageStaff, recentPassword: true);
            $invite = StaffInvitation::query()->whereKey($id)->lockForUpdate()->first() ?? throw new StaffFailure(404, 'NOT_FOUND');
            $this->grants->check($actor, $invite->offeredRoles());
            if ($invite->lock_version !== $version) {
                throw new StaffFailure(412, 'STALE_VERSION');
            }
            if ($invite->status === 'accepted') {
                throw new StaffFailure(409, 'INVITATION_ALREADY_ACCEPTED');
            }
            if ($invite->status === 'revoked') {
                throw new StaffFailure(409, 'INVITATION_ALREADY_REVOKED');
            }
            if ($invite->status === 'expired' || $invite->expires_at->isPast()) {
                throw new StaffFailure(409, 'INVITATION_EXPIRED');
            }
            if ($resend) {
                $this->emailAvailable($invite->email);
                $this->issuer($invite);
                if ($invite->last_requested_at->greaterThan(now()->subMinute())
                    || DB::table('identity_staff_invitation_mail')->where('invitation_id', $id)->where('created_at', '>', now()->subDay())->count() >= 5) {
                    throw new StaffFailure(429, 'INVITATION_RESEND_LIMIT');
                }
            }
            $invite->token_hash = null;
            $invite->lock_version++;
            DB::table('identity_staff_invitation_mail')->where('invitation_id', $id)->where('state', 'pending')->update(['state' => 'discarded']);
            if ($resend) {
                $invite->generation++;
                $invite->expires_at = now()->addDays(7);
                $invite->last_requested_at = now();
            } else {
                $invite->status = 'revoked';
                $invite->revoked_at = now();
            }
            $invite->save();
            if ($resend) {
                $this->enqueue($invite, IdentityInput::requestId($request));
            }
            $this->audit->handle($resend ? 'identity.staff_invitation.resent' : 'identity.staff_invitation.revoked',
                'identity.staff_invitation', $id, IdentityInput::requestId($request), $actor->id);

            return $invite;
        });
    }

    /** Public token inspection does not initialize or authenticate an identity.
     * @return array<string,mixed>
     */
    public function lookup(#[SensitiveParameter] string $token): array
    {
        return DB::transaction(function () use ($token): array {
            $this->authority->lockChanges();
            $invite = $this->usable($token);
            $this->issuer($invite);
            if (User::query()->where('email', $invite->email)->exists()) {
                throw new StaffFailure(422, 'INVITATION_UNAVAILABLE');
            }
            $at = strpos($invite->email, '@');

            return ['full_name' => $invite->full_name, 'email_hint' => substr($invite->email, 0, 1).'***'.substr($invite->email, $at === false ? 0 : $at),
                'roles' => array_map(static fn (Role $r): string => $r->value, $invite->offeredRoles()),
                'role_labels' => DB::table('roles')->whereIn('code', array_map(static fn (Role $r): string => $r->value, $invite->offeredRoles()))->orderBy('code')->get(['code', 'name'])->all(),
                'invited_by' => ['full_name' => $invite->inviter_name],
                'expires_at' => $invite->expires_at->toIso8601String(),
                'password_policy' => ['min_length' => 12, 'max_length' => 128, 'uppercase' => true, 'lowercase' => true, 'number' => true]];
        });
    }

    public function accept(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password, string $requestId): void
    {
        // Expensive hashing is rate-limited at HTTP and occurs outside the authorization lock.
        $hash = Hash::make($password);
        try {
            DB::transaction(function () use ($token, $hash, $requestId): void {
                $this->authority->lockChanges();
                $invite = $this->usable($token);
                $this->issuer($invite);
                if (User::query()->where('email', $invite->email)->exists()) {
                    throw new StaffFailure(422, 'INVITATION_UNAVAILABLE');
                }
                // Acceptance is the authorization decision. MFA remains mandatory for
                // access, and the last-admin guard excludes unactivated invitees.
                $user = User::query()->create(['full_name' => $invite->full_name, 'email' => $invite->email, 'email_display' => $invite->email,
                    'password' => $hash, 'kind' => 'staff', 'enabled' => true, 'email_verified_at' => now(), 'auth_version' => 1]);
                foreach ($invite->offeredRoles() as $role) {
                    DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $this->authority->roleId($role), 'user_kind' => 'staff']);
                    $this->audit->handle('identity.role.'.$role->value.'.granted', 'user', $user->id, $requestId, $invite->invited_by);
                }
                $invite->status = 'accepted';
                $invite->accepted_at = now();
                $invite->accepted_user_id = $user->id;
                $invite->token_hash = null;
                $invite->lock_version++;
                $invite->save();
                DB::table('identity_staff_invitation_mail')->where('invitation_id', $invite->id)->where('state', 'pending')->update(['state' => 'discarded']);
                $this->audit->handle('identity.staff_invitation.accepted', 'identity.staff_invitation', $invite->id, $requestId);
                $this->audit->handle('identity.email.verified', 'identity.user', $user->id, $requestId, $user->id);
            });
        } catch (UniqueConstraintViolationException) {
            throw new StaffFailure(422, 'INVITATION_UNAVAILABLE');
        }
    }

    /** Called under the authorization lock, user lock and MFA transaction. */
    public function activate(User $user, string $requestId): void
    {
        $invite = StaffInvitation::query()->where('accepted_user_id', $user->id)->whereNull('activated_at')->lockForUpdate()->first();
        if ($invite === null) {
            return;
        }
        $invite->activated_at = now();
        $invite->lock_version++;
        $invite->save();
        $this->audit->handle('identity.staff_invitation.activated', 'identity.staff_invitation', $invite->id, $requestId, $user->id);
    }

    public function expire(string $requestId): int
    {
        return DB::transaction(function () use ($requestId): int {
            $this->authority->lockChanges();
            $rows = StaffInvitation::query()->where('status', 'pending')->where('expires_at', '<=', DB::raw('clock_timestamp()'))->orderBy('id')->limit(100)->lockForUpdate()->get();
            foreach ($rows as $invite) {
                $this->expireOne($invite, $requestId);
            }

            return $rows->count();
        });
    }

    private function usable(#[SensitiveParameter] string $token): StaffInvitation
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new StaffFailure(422, 'INVITATION_UNAVAILABLE');
        }

        return StaffInvitation::query()->where('token_hash', hash('sha256', $token))->where('status', 'pending')
            ->where('expires_at', '>', DB::raw('clock_timestamp()'))->lockForUpdate()->first() ?? throw new StaffFailure(422, 'INVITATION_UNAVAILABLE');
    }

    private function issuer(StaffInvitation $invite): void
    {
        $issuer = User::query()->whereKey($invite->invited_by)->first();
        if ($issuer === null) {
            throw new StaffFailure(422, 'INVITATION_UNAVAILABLE');
        }
        try {
            $this->grants->check($issuer, $invite->offeredRoles());
        } catch (GrantDenied) {
            throw new StaffFailure(422, 'INVITATION_UNAVAILABLE');
        }
    }

    private function emailAvailable(string $email): void
    {
        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            throw new StaffFailure(409, $existing->kind === 'staff' ? 'EMAIL_ALREADY_STAFF' : 'EMAIL_ALREADY_CUSTOMER');
        }
    }

    private function enqueue(StaffInvitation $invite, string $requestId): void
    {
        $id = (string) Str::uuid7();
        $operation = $this->operations->record('identity.staff_invitation_mail', $id, ['mail_id' => $id], $requestId);
        DB::table('identity_staff_invitation_mail')->insert(['id' => $id, 'invitation_id' => $invite->id, 'operation_id' => $operation->id, 'generation' => $invite->generation]);
    }

    private function expireEmail(string $email, string $requestId): void
    {
        foreach (StaffInvitation::query()->where('email', $email)->where('status', 'pending')->where('expires_at', '<=', DB::raw('clock_timestamp()'))->lockForUpdate()->get() as $invite) {
            $this->expireOne($invite, $requestId);
        }
    }

    private function expireOne(StaffInvitation $invite, string $requestId): void
    {
        $invite->status = 'expired';
        $invite->token_hash = null;
        $invite->lock_version++;
        $invite->save();
        DB::table('identity_staff_invitation_mail')->where('invitation_id', $invite->id)->where('state', 'pending')->update(['state' => 'discarded']);
        $this->audit->handle('identity.staff_invitation.expired', 'identity.staff_invitation', $invite->id, $requestId);
    }
}
