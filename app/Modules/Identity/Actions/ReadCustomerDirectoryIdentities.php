<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ReadCustomerDirectoryIdentities
{
    /** Internal safe projection, composed only after directory authorization. */
    public function query(): Builder
    {
        return DB::table('users')->where('kind', 'customer')
            ->select('id', 'full_name', 'email', 'email_display', 'enabled', 'email_verified_at');
    }
}
