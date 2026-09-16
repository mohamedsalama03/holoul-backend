<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mfa;

use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

final readonly class Totp
{
    public function __construct(private Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function acceptedStep(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code, ?int $previousStep): ?int
    {
        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            return null;
        }

        $accepted = $this->engine->verifyKeyNewer($secret, $code, $previousStep ?? -1, 1, intdiv(now()->getTimestamp(), 30));

        return is_int($accepted) ? $accepted : null;
    }

    public function enrollmentUri(#[SensitiveParameter] string $secret, string $email): string
    {
        return 'otpauth://totp/'.rawurlencode('HOLOUL:'.$email).'?'.http_build_query([
            'secret' => $secret, 'issuer' => 'HOLOUL', 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
