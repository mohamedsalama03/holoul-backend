<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Contracts\NotificationRecipient;
use App\Modules\Identity\Contracts\NotificationRecipientReader;
use App\Modules\Identity\Models\User;

final class ReadNotificationRecipient implements NotificationRecipientReader
{
    public function current(string $id, bool $lock = false): ?NotificationRecipient
    {
        $query = User::query()->whereKey($id);
        if ($lock) {
            $query->sharedLock();
        }
        $user = $query->first();

        return $user === null ? null : new NotificationRecipient($user->id, $user->email, $user->enabled, $user->email_verified_at !== null);
    }
}
