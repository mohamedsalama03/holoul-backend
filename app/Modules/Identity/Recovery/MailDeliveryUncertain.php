<?php

declare(strict_types=1);

namespace App\Modules\Identity\Recovery;

use RuntimeException;

final class MailDeliveryUncertain extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The mail delivery outcome is uncertain.');
    }
}
