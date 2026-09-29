<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Modules\Contact\ContactMailNotAccepted;
use App\Modules\Contact\Contracts\ContactMailSender;
use App\Modules\Contact\Data\ContactMail;
use App\Modules\Notifications\Contracts\EmailProvider;
use App\Modules\Notifications\Data\EmailMessage;
use App\Modules\Notifications\EmailNotAccepted;

final readonly class ContactMailAdapter implements ContactMailSender
{
    public function __construct(private EmailProvider $provider) {}

    public function send(ContactMail $mail): void
    {
        $body = 'HOLOUL received contact message '.$mail->reference.".\n";
        if ($mail->link !== null) {
            $body .= "Read it using your authorised staff account:\n".$mail->link."\n";
        } else {
            $body .= "Dashboard integration is pending. This local test notification contains a receipt reference only.\n";
        }
        try {
            $this->provider->send(new EmailMessage($mail->recipient, 'HOLOUL contact '.$mail->reference, $body, $mail->messageId));
        } catch (EmailNotAccepted $error) {
            throw new ContactMailNotAccepted($error->retryable);
        }
    }
}
