<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

trait PublicContentHttp
{
    use IdentityHttp;

    private function publicContentOperator(Role $role = Role::SuperAdmin): User
    {
        $user = $this->customerUser();
        $user->kind = 'staff';
        $user->email_verified_at = now();
        $user->save();
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => app(RoleAuthority::class)->roleId($role), 'user_kind' => 'staff']);
        $this->signIn($user)->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();

        return $user;
    }
}
