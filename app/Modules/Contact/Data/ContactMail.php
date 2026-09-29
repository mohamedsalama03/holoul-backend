<?php

declare(strict_types=1);

namespace App\Modules\Contact\Data;

final readonly class ContactMail
{
    public function __construct(public string $recipient, public string $reference, public ?string $link, public string $messageId) {}
}
