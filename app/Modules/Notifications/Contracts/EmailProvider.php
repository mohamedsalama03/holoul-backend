<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Data\EmailMessage;

interface EmailProvider
{
    /** Return a safe provider reference if available. SMTP is not exactly-once. */
    public function send(EmailMessage $message): ?string;
}
