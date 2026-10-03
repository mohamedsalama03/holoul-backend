<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Models\IdentitySession;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\SessionSecurity;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class WithAuthorizedIdentity
{
    public function __construct(private SessionSecurity $sessions, private RoleAuthority $authority) {}

    /**
     * The closure must contain retry-safe database work, with external effects
     * deferred through durable intent. Two attempts bound deadlock recovery.
     *
     * @template T
     *
     * @param  Closure(AuthorizedIdentity): T  $action
     * @return T
     */
    public function handle(Request $request, Closure $action): mixed
    {
        return DB::transaction(function () use ($request, $action) {
            if (! $request->hasSession()) {
                throw new AuthenticationException;
            }
            $principal = Auth::guard('sanctum')->user();
            if (! $principal instanceof User) {
                throw new AuthenticationException;
            }
            // Serialize authority changes without blocking recipient foreign-key
            // checks from transactions already holding a business-resource lock.
            // This action never changes the identity key; session locks stay exclusive.
            $user = User::query()->whereKey($principal->id)->lock('for no key update')->first();
            if ($user === null || ! $user->enabled || $request->session()->get('identity.auth_version') !== $user->auth_version) {
                throw new AuthenticationException;
            }
            $sessionId = $request->session()->get('identity.session_id');
            if (is_string($sessionId) && Str::isUuid($sessionId)) {
                // Logout deletes this row. Hold it through the business commit,
                // after the identity lock used by credential/role mutations.
                IdentitySession::query()->whereKey($sessionId)->where('user_id', $user->id)->lockForUpdate()->first();
            }
            $this->sessions->authenticated($request);
            $permissions = $this->authority->permissionsFor($this->authority->roles($user->id));
            sort($permissions);

            return $action(new AuthorizedIdentity($user->id, $user->kind, $user->email_verified_at !== null, $permissions, $user->username !== null));
        }, 2);
    }
}
