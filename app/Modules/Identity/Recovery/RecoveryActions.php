<?php

declare(strict_types=1);

namespace App\Modules\Identity\Recovery;

use App\Infrastructure\Async\OperationRecorder;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Data\SafeAuditMetadata;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Recovery\Data\RecoveryMailPayload;
use App\Modules\Identity\Recovery\Models\RecoveryMail;
use App\Modules\Identity\Recovery\Models\RecoveryToken;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class RecoveryActions
{
    public function __construct(
        private OperationRecorder $operations,
        private Encrypter $encrypter,
        private RecordAuditEvent $audit,
        private SessionSecurity $sessions,
    ) {}

    /** May be nested in registration; all token, mail, operation and audit writes roll back together. */
    public function issueVerification(User $user, string $requestId): void
    {
        DB::transaction(function () use ($user, $requestId): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($lockedUser->enabled && $lockedUser->email_verified_at === null) {
                $this->issue($lockedUser, RecoveryPurpose::EmailVerification, $requestId);
            }
        });
    }

    /** The HTTP boundary applies the same limiter and generic response to every normalized email. */
    public function resendVerification(string $normalizedEmail, string $requestId): void
    {
        $this->requestForAccount($normalizedEmail, RecoveryPurpose::EmailVerification, $requestId);
    }

    public function forgotPassword(string $normalizedEmail, string $requestId): void
    {
        $this->requestForAccount($normalizedEmail, RecoveryPurpose::PasswordReset, $requestId);
    }

    public function verifyEmail(string $token, string $requestId): void
    {
        $candidate = $this->findToken($token, RecoveryPurpose::EmailVerification);

        DB::transaction(function () use ($candidate, $requestId): void {
            // All recovery mutations lock the user before their token rows.
            $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $lockedToken = $this->lockUsableToken($candidate, $user);

            if ($lockedToken->consumed_at !== null) {
                if ($user->email_verified_at !== null) {
                    return;
                }

                throw $this->invalidToken();
            }

            RecoveryToken::query()->whereKey($lockedToken->id)->update(['consumed_at' => DB::raw('clock_timestamp()')]);

            if ($user->email_verified_at === null) {
                User::query()->whereKey($user->id)->update(['email_verified_at' => DB::raw('clock_timestamp()')]);
                $this->audit->handle('identity.email.verified', 'identity.user', $user->id, $requestId, $user->id,
                    new SafeAuditMetadata(['outcome' => 'succeeded']));
            }

            $this->revokePending($user->id, RecoveryPurpose::EmailVerification);
        });
    }

    /** The HTTP boundary validates the password policy and never authenticates after this action. */
    public function resetPassword(string $token, string $newPassword, string $requestId): void
    {
        $candidate = $this->findToken($token, RecoveryPurpose::PasswordReset);
        $passwordHash = Hash::make($newPassword);

        DB::transaction(function () use ($candidate, $passwordHash, $requestId): void {
            $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $lockedToken = $this->lockUsableToken($candidate, $user);

            if ($lockedToken->consumed_at !== null) {
                throw $this->invalidToken();
            }

            User::query()->whereKey($user->id)->update(['password' => $passwordHash]);
            RecoveryToken::query()->whereKey($lockedToken->id)->update(['consumed_at' => DB::raw('clock_timestamp()')]);
            $this->revokePending($user->id, RecoveryPurpose::PasswordReset);
            $this->revokePending($user->id, RecoveryPurpose::EmailVerification);
            $this->sessions->revokeAll($user->id, $requestId, $user->id);
            $this->audit->handle('identity.password.reset', 'identity.user', $user->id, $requestId, $user->id,
                new SafeAuditMetadata(['outcome' => 'succeeded']));
        });
    }

    private function requestForAccount(string $normalizedEmail, RecoveryPurpose $purpose, string $requestId): void
    {
        DB::transaction(function () use ($normalizedEmail, $purpose, $requestId): void {
            // The request event never discloses whether the email belongs to an account.
            $event = $purpose === RecoveryPurpose::EmailVerification
                ? 'identity.verification.requested' : 'identity.password_reset.requested';
            $this->audit->handle($event, 'identity.recovery_request', $requestId, $requestId,
                metadata: new SafeAuditMetadata(['outcome' => 'succeeded']));
            $user = User::query()->where('email', $normalizedEmail)->lockForUpdate()->first();

            if ($user === null || ! $user->enabled
                || ($purpose === RecoveryPurpose::EmailVerification && $user->email_verified_at !== null)) {
                return;
            }

            $this->issue($user, $purpose, $requestId);
        });
    }

    private function issue(User $user, RecoveryPurpose $purpose, string $requestId): void
    {
        $this->revokePending($user->id, $purpose);
        $tokenId = (string) Str::uuid7();
        $mailId = (string) Str::uuid7();
        $token = bin2hex(random_bytes(32));
        $payload = new RecoveryMailPayload($user->email, $token, $purpose);
        DB::table('identity_recovery_tokens')->insert([
            'id' => $tokenId,
            'user_id' => $user->id,
            'purpose' => $purpose->value,
            'token_hash' => hash('sha256', $token),
            'email_hash' => hash('sha256', $user->email),
            'auth_version' => $user->auth_version,
            'expires_at' => DB::raw("clock_timestamp() + interval '60 minutes'"),
            'created_at' => DB::raw('clock_timestamp()'),
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
        $operation = $this->operations->record('identity.recovery_mail', $mailId, ['mail_id' => $mailId], $requestId);
        DB::table('identity_recovery_mail')->insert([
            'id' => $mailId,
            'user_id' => $user->id,
            'recovery_token_id' => $tokenId,
            'operation_id' => $operation->id,
            'state' => 'pending',
            'encrypted_payload' => $payload->encrypt($this->encrypter),
            'created_at' => DB::raw('clock_timestamp()'),
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
        $event = $purpose === RecoveryPurpose::EmailVerification
            ? 'identity.verification.issued' : 'identity.password_reset.issued';
        $this->audit->handle($event, 'identity.user', $user->id, $requestId,
            metadata: new SafeAuditMetadata(['operation_id' => $operation->id]));
    }

    private function revokePending(string $userId, RecoveryPurpose $purpose): void
    {
        RecoveryToken::query()->where('user_id', $userId)->where('purpose', $purpose->value)
            ->whereNull('consumed_at')->whereNull('revoked_at')
            ->update(['revoked_at' => DB::raw('clock_timestamp()')]);
        RecoveryMail::query()->where('user_id', $userId)->where('state', 'pending')
            ->whereIn('recovery_token_id', RecoveryToken::query()->select('id')
                ->where('user_id', $userId)->where('purpose', $purpose->value))
            ->update(['state' => 'discarded', 'encrypted_payload' => null, 'failure_code' => 'token_superseded']);
    }

    private function findToken(string $token, RecoveryPurpose $purpose): RecoveryToken
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw $this->invalidToken();
        }

        return RecoveryToken::query()->where('token_hash', hash('sha256', $token))
            ->where('purpose', $purpose->value)->first() ?? throw $this->invalidToken();
    }

    private function lockUsableToken(RecoveryToken $candidate, User $user): RecoveryToken
    {
        $token = RecoveryToken::query()->whereKey($candidate->id)->where('user_id', $user->id)
            ->where('expires_at', '>', DB::raw('clock_timestamp()'))->whereNull('revoked_at')
            ->lockForUpdate()->first();

        if ($token === null || ! $user->enabled || $token->auth_version !== $user->auth_version
            || ! hash_equals($token->email_hash, hash('sha256', $user->email))) {
            throw $this->invalidToken();
        }

        return $token;
    }

    private function invalidToken(): ValidationException
    {
        return ValidationException::withMessages(['token' => ['This link is invalid or expired.']]);
    }
}
