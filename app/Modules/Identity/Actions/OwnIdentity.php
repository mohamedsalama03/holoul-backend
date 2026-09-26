<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Models\IdentitySession;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\IdentityInput;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final readonly class OwnIdentity
{
    public function __construct(private SessionSecurity $sessions, private RecordAuditEvent $audit,
        private WithAuthorizedIdentity $identity, private RoleAuthority $authority, private CurrentCapabilities $capabilities) {}

    /** @return array<string,mixed> */
    public function read(Request $request): array
    {
        return $this->identity->handle($request, function (AuthorizedIdentity $actor) use ($request): array {
            $user = $this->sessions->authenticated($request);
            $recent = $this->sessions->hasRecentPassword($request);
            $confirmed = $request->session()->get('identity.password_confirmed_at');
            $expires = is_int($confirmed) && $confirmed <= now()->getTimestamp()
                ? now()->setTimestamp($confirmed)->addSeconds(Config::integer('identity.recent_password_seconds'))->toIso8601String() : null;

            return ['id' => $user->id, 'full_name' => $user->full_name, 'email' => $user->email, 'email_display' => $user->email_display,
                'kind' => $user->kind, 'email_verified' => $actor->verifiedEmail,
                'roles' => array_map(static fn (Role $role): string => $role->value, $this->authority->roles($actor->id)),
                'capabilities' => $this->capabilities->forSession($actor, $recent),
                'mfa_required' => $actor->kind === 'staff',
                'mfa_satisfied' => $actor->kind !== 'staff' || $request->session()->get('identity.mfa_verified') === true,
                'recent_password_confirmation' => ['required' => ! $recent, 'expires_at' => $expires]];
        });
    }

    public function update(Request $request, string $name): void
    {
        $principal = $this->sessions->authenticated($request);
        DB::transaction(function () use ($request, $principal, $name): void {
            $user = User::query()->whereKey($principal->id)->where('enabled', true)->where('auth_version', $principal->auth_version)->lockForUpdate()->firstOrFail();
            $user->full_name = $name;
            $user->save();
            $this->audit->handle('identity.profile_updated', 'user', $user->id, IdentityInput::requestId($request), $user->id);
        });
    }

    /** @return list<array{id: string, current: bool, authenticated_at: string, last_activity_at: string, expires_at: string}> */
    public function sessions(Request $request): array
    {
        $user = $this->sessions->authenticated($request);

        return array_values(IdentitySession::query()->where('user_id', $user->id)->where('auth_version', $user->auth_version)
            ->where('last_activity_at', '>', now()->subSeconds(Config::integer('identity.'.$user->kind.'_idle_seconds')))
            ->where('expires_at', '>', now())->orderByDesc('last_activity_at')->limit(100)->get()
            ->map(fn (IdentitySession $session): array => [
                'id' => $session->id, 'current' => $session->id === $request->session()->get('identity.session_id'),
                'authenticated_at' => $session->authenticated_at->toIso8601String(),
                'last_activity_at' => $session->last_activity_at->toIso8601String(),
                'expires_at' => $session->expires_at->toIso8601String(),
            ])->all());
    }

    public function revokeOthers(Request $request): void
    {
        $user = $this->sessions->authenticated($request);
        $this->sessions->requireRecentPassword($request);
        $mfa = $request->session()->get('identity.mfa_verified') === true;
        $user->auth_version = DB::transaction(function () use ($request, $user): int {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $current->enabled || $current->auth_version !== $user->auth_version) {
                throw new AuthenticationException;
            }

            return $this->sessions->revokeAll($user->id, IdentityInput::requestId($request), $user->id);
        });
        $this->sessions->completeLogin($request, $user, $mfa);
    }
}
