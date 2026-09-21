<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Notifications\Contracts\EmailProvider;
use App\Modules\Notifications\Data\EmailMessage;
use App\Modules\Notifications\EmailNotAccepted;
use RuntimeException;

final class NotificationProviderDouble implements EmailProvider
{
    public string $mode = 'accepted';

    public int $calls = 0;

    public ?EmailMessage $last = null;

    public function send(EmailMessage $message): ?string
    {
        $this->calls++;
        $this->last = $message;
        if ($this->mode === 'unavailable') {
            throw new EmailNotAccepted;
        }
        if ($this->mode === 'rejected') {
            throw new EmailNotAccepted(false);
        }
        if ($this->mode === 'uncertain') {
            throw new RuntimeException('Private provider details must never persist.');
        }

        return 'sandbox-ack-'.$this->calls;
    }
}
