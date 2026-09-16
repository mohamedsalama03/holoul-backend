<?php

declare(strict_types=1);

namespace App\Modules\Identity\Recovery\Adapters;

use App\Modules\Identity\Recovery\Contracts\MailTransport;
use App\Modules\Identity\Recovery\Data\RecoveryMessage;
use App\Modules\Identity\Recovery\MailDeliveryUncertain;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Config;
use Throwable;

final readonly class SmtpMailTransport implements MailTransport
{
    public function __construct(private Factory $mail) {}

    public function send(RecoveryMessage $message): void
    {
        try {
            $this->assertConfiguration();
            $sent = $this->mail->mailer('smtp')->raw($message->body, function (Message $mail) use ($message): void {
                $mail->to($message->recipient)->subject($message->subject);
                $mail->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $message->messageId);
            });

            if ($sent === null) {
                throw new MailDeliveryUncertain;
            }
        } catch (Throwable) {
            // SMTP cannot prove whether the server accepted DATA before a lost
            // acknowledgement. Do not leak its exception or blindly resend.
            throw new MailDeliveryUncertain;
        }
    }

    private function assertConfiguration(): void
    {
        $host = Config::get('mail.mailers.smtp.host');
        $port = Config::get('mail.mailers.smtp.port');
        $scheme = Config::get('mail.mailers.smtp.scheme');
        $from = Config::get('mail.from.address');
        $username = Config::get('mail.mailers.smtp.username');
        $password = Config::get('mail.mailers.smtp.password');

        if (Config::get('identity.mail_sandbox') === true) {
            if ($host === 'mailpit' && $port === 1025 && $scheme === 'smtp' && $from === 'noreply@holoul.test') {
                return;
            }
        } elseif (is_string($host) && trim($host) !== '' && $scheme === 'smtps'
            && is_int($port) && $port > 0 && $port <= 65535
            && is_string($username) && trim($username) !== ''
            && is_string($password) && $password !== ''
            && is_string($from) && filter_var($from, FILTER_VALIDATE_EMAIL) !== false
            && $from !== 'noreply@holoul.test') {
            return;
        }

        throw new MailDeliveryUncertain;
    }
}
