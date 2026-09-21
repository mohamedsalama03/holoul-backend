<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

final readonly class EmailMessage
{
    public function __construct(public string $recipient, public string $title, public string $body, public string $messageId) {}
}
