<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Adapters;

use App\Modules\Notifications\Contracts\EmailProvider;
use App\Modules\Notifications\Data\EmailMessage;
use App\Modules\Notifications\EmailNotAccepted;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

final readonly class SmtpEmailProvider implements EmailProvider
{
    public function __construct(private Factory $mail) {}

    public function send(EmailMessage $message): ?string
    {
        $host = Config::get('mail.mailers.smtp.host');
        $port = Config::get('mail.mailers.smtp.port');
        $scheme = Config::get('mail.mailers.smtp.scheme');
        $from = Config::get('mail.from.address');
        $sandbox = Config::get('identity.mail_sandbox') === true;
        $valid = $sandbox ? $host === 'mailpit' && $port === 1025 && $scheme === 'smtp' && $from === 'noreply@holoul.test'
            : is_string($host) && $host !== '' && $scheme === 'smtps' && is_int($port) && $port > 0 && $port <= 65535
                && is_string($from) && filter_var($from, FILTER_VALIDATE_EMAIL) !== false && $from !== 'noreply@holoul.test'
                && is_string(Config::get('mail.mailers.smtp.username')) && Config::get('mail.mailers.smtp.username') !== ''
                && is_string(Config::get('mail.mailers.smtp.password')) && Config::get('mail.mailers.smtp.password') !== '';
        if (! $valid) {
            throw new EmailNotAccepted(false);
        }
        try {
            $mailer = $this->mail->mailer('smtp');
            if (! $mailer instanceof Mailer || ! $mailer->getSymfonyTransport() instanceof SmtpTransport) {
                throw new EmailNotAccepted(false);
            }
            // Establish/authenticate the connection before DATA. Only this phase
            // is positively safe to retry without risking an accepted message.
            $mailer->getSymfonyTransport()->start();
        } catch (EmailNotAccepted $error) {
            throw $error;
        } catch (Throwable) {
            throw new EmailNotAccepted;
        }
        $sent = $mailer->raw($message->body, function (Message $mail) use ($message): void {
            $mail->to($message->recipient)->subject($message->title);
            $mail->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $message->messageId);
        });
        if ($sent === null) {
            throw new RuntimeException('Notification acknowledgement uncertain.');
        }

        return null;
    }
}
