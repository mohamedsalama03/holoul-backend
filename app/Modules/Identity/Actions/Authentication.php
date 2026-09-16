<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Data\SafeAuditMetadata;
use App\Modules\Identity\Mfa\MfaCredential;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\IdentityInput;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;

final readonly class Authentication
{
    public function __construct(private SessionSecurity $sessions, private RecordAuditEvent $audit) {}

    /** @return array{next_step: string} */
    public function login(Request $request, string $email, #[SensitiveParameter] string $password): array
    {
        $user = DB::transaction(function () use ($email, $password, $request): ?User {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($user === null) {
                // Same expensive password operation for a missing account.
                Hash::make($password);
            }
            $valid = $user !== null && Hash::check($password, $user->password) && $user->enabled;
            if (! $valid) {
                $this->audit->handle('identity.login_failed', 'authentication_attempt', (string) Str::uuid7(), IdentityInput::requestId($request), metadata: new SafeAuditMetadata(['outcome' => 'failed']));

                return null;
            }
            if (Hash::needsRehash($user->password)) {
                $user->password = Hash::make($password);
                $user->save();
            }

            return $user;
        });
        if ($user === null) {
            throw new AuthenticationException;
        }
        if ($user->kind === 'staff') {
            $this->sessions->beginStaffLogin($request, $user);
            $enrolled = MfaCredential::query()->where('user_id', $user->id)->whereNotNull('confirmed_at')->exists();

            return ['next_step' => $enrolled ? 'mfa_challenge' : 'mfa_enrollment'];
        }
        $request->session()->put('identity.password_confirmed_at', now()->getTimestamp());
        $this->sessions->completeLogin($request, $user, false);

        return ['next_step' => 'authenticated'];
    }

    public function logout(Request $request): void
    {
        $user = $this->sessions->authenticated($request);
        DB::transaction(function () use ($request, $user): void {
            $this->audit->handle('identity.logged_out', 'user', $user->id, IdentityInput::requestId($request), $user->id);
            $this->sessions->invalidate($request);
        });
    }

    public function confirmPassword(Request $request, #[SensitiveParameter] string $password): void
    {
        $user = $this->sessions->authenticated($request);
        if (! Hash::check($password, $user->password)) {
            throw new AuthenticationException;
        }
        $request->session()->put('identity.password_confirmed_at', now()->getTimestamp());
    }

    public function changePassword(Request $request, #[SensitiveParameter] string $current, #[SensitiveParameter] string $password): void
    {
        $principal = $this->sessions->authenticated($request);
        $user = DB::transaction(function () use ($request, $principal, $current, $password): User {
            $user = User::query()->lockForUpdate()->findOrFail($principal->id);
            if ($user->auth_version !== $principal->auth_version || ! Hash::check($current, $user->password)) {
                throw new AuthenticationException;
            }
            $user->password = Hash::make($password);
            $user->save();
            $user->auth_version = $this->sessions->revokeAll($user->id, IdentityInput::requestId($request), $user->id);
            $this->audit->handle('identity.password_changed', 'user', $user->id, IdentityInput::requestId($request), $user->id);

            return $user;
        });
        $mfa = $request->session()->get('identity.mfa_verified') === true;
        $request->session()->put('identity.password_confirmed_at', now()->getTimestamp());
        $this->sessions->completeLogin($request, $user, $mfa);
    }
}
