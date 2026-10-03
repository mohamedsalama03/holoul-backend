<?php

declare(strict_types=1);

namespace App\Modules\Identity\Recovery;

use App\Modules\Identity\Recovery\Data\RecoveryMailPayload;
use App\Modules\Identity\Recovery\Data\RecoveryMessage;
use Illuminate\Support\Facades\Config;
use RuntimeException;

final class RecoveryMailTemplates
{
    public function render(string $mailId, RecoveryMailPayload $payload, bool $staffAccount = false): RecoveryMessage
    {
        $origin = rtrim(Config::string('app.url'), '/');
        $host = parse_url($origin, PHP_URL_HOST);

        if (! is_string($host) || parse_url($origin, PHP_URL_SCHEME) !== 'https'
            || parse_url($origin, PHP_URL_USER) !== null || parse_url($origin, PHP_URL_PASS) !== null
            || parse_url($origin, PHP_URL_PATH) !== null
            || parse_url($origin, PHP_URL_QUERY) !== null || parse_url($origin, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('The application origin is invalid.');
        }

        $verification = $payload->purpose === RecoveryPurpose::EmailVerification;
        $subject = $verification ? 'Verify your HOLOUL email address' : 'Reset your HOLOUL password';
        $path = $verification ? '/verify-email' : '/reset-password';
        if ($staffAccount) {
            $path = '/admin'.$path;
        }
        // The SPA reads the fragment and POSTs the token. It never reaches the
        // HTTP request target, ingress access log, or an ordinary referrer.
        $link = $origin.$path.'#token='.$payload->token;
        $instruction = $verification ? 'Confirm your email address using this link:' : 'Choose a new password using this link:';
        $body = $instruction."\n\n".$link."\n\nThis link expires in 60 minutes.\nIf you did not request this, you can ignore this message.\n";
        $messageHost = preg_match('/\A[a-z0-9.-]+\z/Di', $host) === 1 ? $host : 'holoul.invalid';

        return new RecoveryMessage($mailId, $payload->recipient, $subject, $body, $mailId.'@'.$messageHost);
    }
}
