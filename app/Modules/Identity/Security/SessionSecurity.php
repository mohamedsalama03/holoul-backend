<?php

declare(strict_types=1);

namespace App\Modules\Identity\Security;

use App\Infrastructure\Http\SessionResponse;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Data\SafeAuditMetadata;
use App\Modules\Identity\Models\IdentitySession;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SessionSecurity
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function completeLogin(Request $request, User $user, bool $mfaVerified): void
    {
        DB::transaction(function () use ($request, $user, $mfaVerified): void {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $current->enabled || $current->auth_version !== $user->auth_version || ($current->kind === 'staff' && ! $mfaVerified)) {
                throw new AuthenticationException;
            }
            $this->forgetCurrent($request);
            Auth::guard('web')->login($current, false);
            $request->session()->regenerate(true);
            $request->session()->regenerateToken();
            $now = now();
            $record = IdentitySession::query()->create([
                'user_id' => $current->id,
                'session_hash' => $this->sessionHash($request),
                'auth_version' => $current->auth_version,
                'authenticated_at' => $now,
                'last_activity_at' => $now,
                'expires_at' => $now->copy()->addSeconds(Config::integer('identity.'.$current->kind.'_absolute_seconds')),
            ]);
            $request->session()->put([
                'identity.session_id' => $record->id,
                'identity.auth_version' => $current->auth_version,
                'identity.authenticated_at' => $now->timestamp,
                'identity.mfa_verified' => $mfaVerified,
                'identity.password_confirmed_at' => $request->session()->get('identity.password_confirmed_at', $now->timestamp),
            ]);
            $request->session()->forget(['identity.pending_user_id', 'identity.pending_auth_version', 'identity.pending_started_at']);
            $this->audit->handle('identity.login_succeeded', 'user', $current->id, IdentityInput::requestId($request), $current->id);
        });
    }

    public function beginStaffLogin(Request $request, User $user): void
    {
        $this->forgetCurrent($request);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put([
            'identity.pending_user_id' => $user->id,
            'identity.pending_auth_version' => $user->auth_version,
            'identity.pending_started_at' => now()->getTimestamp(),
            'identity.password_confirmed_at' => now()->getTimestamp(),
        ]);
    }

    public function authenticated(Request $request): User
    {
        $principal = Auth::guard('sanctum')->user();
        $user = $principal instanceof User ? User::query()->find($principal->id) : null;
        $sessionId = $request->session()->get('identity.session_id');
        $record = is_string($sessionId) && Str::isUuid($sessionId)
            ? IdentitySession::query()->where('user_id', $user?->id)->find($sessionId) : null;
        if ($user === null || ! $user->enabled || $record === null
            || $request->session()->get('identity.auth_version') !== $user->auth_version
            || $record->auth_version !== $user->auth_version
            || ! hash_equals($record->session_hash, $this->sessionHash($request))
            || $record->expires_at->isPast()
            || $record->last_activity_at->timestamp <= now()->getTimestamp() - Config::integer('identity.'.$user->kind.'_idle_seconds')
            || ($user->kind === 'staff' && $request->session()->get('identity.mfa_verified') !== true)) {
            $this->reject($request, $principal !== null);
            throw new AuthenticationException;
        }
        $record->last_activity_at = now()->toImmutable();
        $record->save();
        $request->setUserResolver(fn (): User => $user);

        return $user;
    }

    public function requireRecentPassword(Request $request): void
    {
        if (! $this->hasRecentPassword($request)) {
            throw new HttpException(403);
        }
    }

    public function hasRecentPassword(Request $request): bool
    {
        $confirmed = $request->session()->get('identity.password_confirmed_at');

        return is_int($confirmed) && $confirmed <= now()->getTimestamp()
            && $confirmed > now()->getTimestamp() - Config::integer('identity.recent_password_seconds');
    }

    /** Returns the new credential generation, not a session count. */
    public function revokeAll(string $userId, string $requestId, ?string $actorId = null): int
    {
        return DB::transaction(function () use ($userId, $requestId, $actorId): int {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $user->auth_version++;
            $user->save();
            $count = IdentitySession::query()->where('user_id', $userId)->delete();
            DB::table('sessions')->where('user_id', $userId)->delete();
            $this->audit->handle('identity.sessions_revoked', 'user', $userId, $requestId, $actorId, new SafeAuditMetadata(['affected_count' => $count]));

            return $user->auth_version;
        });
    }

    public function invalidate(Request $request): void
    {
        // Explicit logout/security exit still destroys and rotates server state, but
        // its delayed response must never replace a subsequent successful login.
        SessionResponse::discard($request);
        $this->forgetCurrent($request);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function reject(Request $request, bool $hadPrincipal): void
    {
        SessionResponse::discard($request);
        $this->forgetCurrent($request);
        // A passive anonymous identity probe must preserve an existing CSRF bootstrap.
        if (! $hadPrincipal && ! $request->session()->has('identity.session_id')
            && ! $request->session()->has('identity.pending_user_id')) {
            return;
        }
        $request->session()->getHandler()->destroy($request->session()->getId());
        $request->session()->flush();
        Auth::forgetGuards();
    }

    private function forgetCurrent(Request $request): void
    {
        IdentitySession::query()->where('session_hash', $this->sessionHash($request))->delete();
    }

    private function sessionHash(Request $request): string
    {
        return hash_hmac('sha256', $request->session()->getId(), Config::string('app.key'));
    }
}
