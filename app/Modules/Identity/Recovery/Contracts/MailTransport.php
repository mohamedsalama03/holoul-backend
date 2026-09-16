<?php

declare(strict_types=1);

namespace App\Modules\Identity\Recovery\Contracts;

use App\Modules\Identity\Recovery\Data\RecoveryMessage;

interface MailTransport
{
    /** Send once; exceptions must not include recipient, credentials, or message content. */
    public function send(RecoveryMessage $message): void;
}
