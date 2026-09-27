<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ExpireInvitations extends Command
{
    protected $signature = 'identity:expire-staff-invitations';

    protected $description = 'Record expiry of unused staff invitations in bounded batches.';

    public function handle(InvitationActions $actions): int
    {
        $actions->expire((string) Str::uuid7());

        return self::SUCCESS;
    }
}
