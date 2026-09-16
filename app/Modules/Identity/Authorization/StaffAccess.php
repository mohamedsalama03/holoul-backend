<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

final readonly class StaffAccess
{
    public function __construct(private RoleAuthority $authority, private SessionSecurity $sessions) {}

    public function requirePermission(Request $request, Permission $permission, bool $recentPassword = false): User
    {
        if (! $request->hasSession()) {
            throw new AuthenticationException;
        }

        $actor = $this->sessions->authenticated($request);

        if (! $actor->enabled || $actor->kind !== 'staff'
            || $request->session()->get('identity.mfa_verified') !== true
            || $request->session()->get('identity.auth_version') !== $actor->auth_version
            || ! $this->authority->allows($actor, $permission)) {
            throw new AuthorizationException;
        }

        if ($recentPassword) {
            $this->sessions->requireRecentPassword($request);
        }

        return $actor;
    }
}
