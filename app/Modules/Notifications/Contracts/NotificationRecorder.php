<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

interface NotificationRecorder
{
    /** Fixed safe templates and identifiers only; caller owns the business transaction. */
    public function record(string $recipientId, string $type, string $resourceType, string $resourceId, string $logicalKey, string $correlationId): string;
}
