<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mfa;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Data\SafeAuditMetadata;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class MfaActions
{
    public function __construct(private Totp $totp, private RecordAuditEvent $audit, private SessionSecurity $sessions) {}

    /** @return array{secret: string, otpauth_uri: string, expires_in: int} */
    public function beginEnrollment(Request $request): array
    {
        return DB::transaction(function () use ($request): array {
            $user = $this->pendingUser($request);
            $credential = $this->credential($user, true);

            if ($credential->confirmed_at !== null) {
                throw new HttpException(409);
            }

            $secret = $this->totp->generateSecret();
            $credential->pending_secret = $secret;
            $credential->pending_expires_at = now()->toImmutable()->addMinutes(10);
            $credential->save();
            $this->record($request, $user, 'identity.mfa.enrollment_started');

            return ['secret' => $secret, 'otpauth_uri' => $this->totp->enrollmentUri($secret, $user->email), 'expires_in' => 600];
        });
    }

    /** @return list<string> */
    public function confirmEnrollment(Request $request, #[SensitiveParameter] string $code): array
    {
        $result = DB::transaction(function () use ($request, $code): ?array {
            $user = $this->pendingUser($request);
            $credential = $this->credential($user);

            if ($credential->confirmed_at !== null) {
                throw new HttpException(409);
            }

            $step = $credential->pending_secret !== null && $credential->pending_expires_at?->isFuture()
                ? $this->totp->acceptedStep($credential->pending_secret, $code, null) : null;

            if ($step === null) {
                $this->record($request, $user, 'identity.mfa.enrollment_confirmed', 'failed');

                return null;
            }

            $credential->secret = $credential->pending_secret;
            $credential->pending_secret = null;
            $credential->pending_expires_at = null;
            $credential->confirmed_at = now()->toImmutable();
            $credential->last_accepted_step = $step;
            $credential->save();
            $codes = $this->replaceRecoveryCodes($credential);
            $user->auth_version = $this->sessions->revokeAll($user->id, $this->requestId($request), $user->id);
            $this->record($request, $user, 'identity.mfa.enrollment_confirmed');

            return ['user' => $user, 'codes' => $codes];
        });

        if ($result === null) {
            throw ValidationException::withMessages(['code' => ['The code is invalid.']]);
        }

        $this->sessions->completeLogin($request, $result['user'], true);

        return $result['codes'];
    }

    public function challenge(Request $request, #[SensitiveParameter] string $code): void
    {
        $user = DB::transaction(function () use ($request, $code): ?User {
            $user = $this->pendingUser($request);
            $credential = $this->confirmedCredential($user);
            $step = $this->totp->acceptedStep($credential->secret ?? '', $code, $credential->last_accepted_step);

            if ($step === null) {
                $this->record($request, $user, 'identity.mfa.challenged', 'failed');

                return null;
            }

            $credential->last_accepted_step = $step;
            $credential->save();
            $this->record($request, $user, 'identity.mfa.challenged');

            return $user;
        });

        if ($user === null) {
            throw ValidationException::withMessages(['code' => ['The code is invalid.']]);
        }

        $this->sessions->completeLogin($request, $user, true);
    }

    public function recover(Request $request, #[SensitiveParameter] string $code): void
    {
        $user = DB::transaction(function () use ($request, $code): ?User {
            $user = $this->pendingUser($request);
            $credential = $this->confirmedCredential($user);
            $normalized = strtolower(str_replace('-', '', $code));
            $hash = preg_match('/\A[0-9a-f]{32}\z/', $normalized) === 1 ? hash('sha256', $normalized) : null;
            $consumed = $hash === null ? 0 : DB::table('identity_mfa_recovery_codes')
                ->where('mfa_id', $credential->id)->where('code_hash', $hash)->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);
            $this->record($request, $user, 'identity.mfa.recovery_used', $consumed === 1 ? 'succeeded' : 'failed');

            return $consumed === 1 ? $user : null;
        });

        if ($user === null) {
            throw ValidationException::withMessages(['code' => ['The code is invalid.']]);
        }

        $this->sessions->completeLogin($request, $user, true);
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(Request $request): array
    {
        $result = DB::transaction(function () use ($request): array {
            $user = $this->fullUser($request);
            $this->sessions->requireRecentPassword($request);
            $credential = $this->confirmedCredential($user);
            $codes = $this->replaceRecoveryCodes($credential);
            $user->auth_version = $this->sessions->revokeAll($user->id, $this->requestId($request), $user->id);
            $this->record($request, $user, 'identity.mfa.recovery_regenerated');

            return ['user' => $user, 'codes' => $codes];
        });
        $this->sessions->completeLogin($request, $result['user'], true);

        return $result['codes'];
    }

    public function disable(Request $request): void
    {
        DB::transaction(function () use ($request): void {
            $user = $this->fullUser($request);
            $this->sessions->requireRecentPassword($request);
            $credential = $this->confirmedCredential($user);
            DB::table('identity_mfa_recovery_codes')->where('mfa_id', $credential->id)->delete();
            $credential->secret = null;
            $credential->pending_secret = null;
            $credential->pending_expires_at = null;
            $credential->confirmed_at = null;
            $credential->last_accepted_step = null;
            $credential->save();
            $this->sessions->revokeAll($user->id, $this->requestId($request), $user->id);
            $this->record($request, $user, 'identity.mfa.disabled');
        });
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function pendingUser(Request $request): User
    {
        $id = $request->session()->get('identity.pending_user_id');
        $version = $request->session()->get('identity.pending_auth_version');
        $startedAt = $request->session()->get('identity.pending_started_at');

        if (! is_string($id) || ! Str::isUuid($id) || ! is_int($version) || ! is_int($startedAt)
            || $startedAt > now()->getTimestamp() || $startedAt <= now()->getTimestamp() - 600) {
            throw new AuthenticationException;
        }

        return $this->lockedStaff($id, $version);
    }

    private function fullUser(Request $request): User
    {
        $user = $this->sessions->authenticated($request);
        $version = $request->session()->get('identity.auth_version');

        if (! is_int($version) || $request->session()->get('identity.mfa_verified') !== true) {
            throw new AuthenticationException;
        }

        return $this->lockedStaff($user->id, $version);
    }

    private function lockedStaff(string $id, int $version): User
    {
        // Every MFA mutation locks user before credential, including first enrollment.
        $user = User::query()->whereKey($id)->lockForUpdate()->first();

        if ($user === null || ! $user->enabled || $user->auth_version !== $version) {
            throw new AuthenticationException;
        }

        if ($user->kind !== 'staff') {
            throw new AuthorizationException;
        }

        return $user;
    }

    private function credential(User $user, bool $create = false): MfaCredential
    {
        $credential = MfaCredential::query()->where('user_id', $user->id)->lockForUpdate()->first();

        if ($credential === null && $create) {
            $credential = new MfaCredential;
            $credential->user_id = $user->id;
            $credential->save();
        }

        if ($credential === null) {
            throw new HttpException(409);
        }

        return $credential;
    }

    private function confirmedCredential(User $user): MfaCredential
    {
        $credential = $this->credential($user);

        if ($credential->confirmed_at === null || $credential->secret === null || $credential->last_accepted_step === null) {
            throw new HttpException(409);
        }

        return $credential;
    }

    /** @return list<string> */
    private function replaceRecoveryCodes(MfaCredential $credential): array
    {
        DB::table('identity_mfa_recovery_codes')->where('mfa_id', $credential->id)->delete();
        $codes = [];

        for ($index = 0; $index < 10; $index++) {
            $code = bin2hex(random_bytes(16));
            DB::table('identity_mfa_recovery_codes')->insert([
                'id' => (string) Str::uuid7(), 'mfa_id' => $credential->id,
                'code_hash' => hash('sha256', $code), 'consumed_at' => null, 'created_at' => now(),
            ]);
            $codes[] = implode('-', str_split($code, 8));
        }

        return $codes;
    }

    private function record(Request $request, User $user, string $event, string $outcome = 'succeeded'): void
    {
        $this->audit->handle($event, 'identity.user', $user->id, $this->requestId($request), $user->id,
            new SafeAuditMetadata(['outcome' => $outcome]));
    }

    private function requestId(Request $request): string
    {
        $id = $request->attributes->get('request_id');

        return is_string($id) && Str::isUuid($id) ? $id : (string) Str::uuid7();
    }
}
