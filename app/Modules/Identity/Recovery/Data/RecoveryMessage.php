<?php

declare(strict_types=1);

namespace App\Modules\Identity\Recovery\Data;

final readonly class RecoveryMessage
{
    public function __construct(
        public string $id,
        public string $recipient,
        public string $subject,
        public string $body,
        public string $messageId,
    ) {}
}
