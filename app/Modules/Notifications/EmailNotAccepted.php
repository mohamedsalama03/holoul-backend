<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use RuntimeException;

/** Adapter positively knows no message was accepted; other exceptions are uncertain. */
final class EmailNotAccepted extends RuntimeException
{
    public function __construct(public readonly bool $retryable = true)
    {
        parent::__construct('Notification was not accepted.');
    }
}
