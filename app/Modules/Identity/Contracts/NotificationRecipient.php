<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

final readonly class NotificationRecipient
{
    public function __construct(public string $id, public string $email, public bool $enabled, public bool $verified) {}
}
