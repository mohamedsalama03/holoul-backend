<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

interface NotificationRecipientReader
{
    public function current(string $id, bool $lock = false): ?NotificationRecipient;
}
