<?php

declare(strict_types=1);

namespace App\Modules\Identity\Recovery\Data;

use App\Modules\Identity\Recovery\RecoveryPurpose;
use Illuminate\Contracts\Encryption\Encrypter;
use InvalidArgumentException;

final readonly class RecoveryMailPayload
{
    public function __construct(
        public string $recipient,
        public string $token,
        public RecoveryPurpose $purpose,
    ) {
        if (strlen($recipient) > 254 || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid recovery mail payload.');
        }
    }

    public function encrypt(Encrypter $encrypter): string
    {
        return $encrypter->encrypt(json_encode([
            'recipient' => $this->recipient,
            'token' => $this->token,
            'purpose' => $this->purpose->value,
        ], JSON_THROW_ON_ERROR), false);
    }

    public static function decrypt(string $ciphertext, Encrypter $encrypter): self
    {
        $plaintext = $encrypter->decrypt($ciphertext, false);
        if (! is_string($plaintext)) {
            throw new InvalidArgumentException('Invalid recovery mail payload.');
        }
        $payload = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);

        if (! is_array($payload) || count($payload) !== 3 || ! is_string($payload['recipient'] ?? null)
            || ! is_string($payload['token'] ?? null) || ! is_string($payload['purpose'] ?? null)) {
            throw new InvalidArgumentException('Invalid recovery mail payload.');
        }

        $purpose = RecoveryPurpose::tryFrom($payload['purpose']);

        if ($purpose === null) {
            throw new InvalidArgumentException('Invalid recovery mail purpose.');
        }

        return new self($payload['recipient'], $payload['token'], $purpose);
    }
}
