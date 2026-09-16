<?php

declare(strict_types=1);

namespace App\Modules\Identity\Security;

use App\Modules\Identity\Contracts\IdentityContact;
use App\Modules\Identity\Contracts\IdentityReader;
use App\Modules\Identity\Contracts\IdentityRecord;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class DatabaseIdentityReader implements IdentityReader
{
    public function find(string $id): ?IdentityRecord
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $user = User::query()->find($id);

        return $user === null ? null : new IdentityRecord($user->id, $user->kind, $user->enabled);
    }

    public function contact(string $id, bool $lock = false): ?IdentityContact
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new LogicException('A locked identity contact read requires a transaction.');
        }
        if (! Str::isUuid($id)) {
            return null;
        }
        $query = User::query()->whereKey($id)->select(['id', 'kind', 'enabled', 'full_name', 'email', 'email_verified_at']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $user = $query->first();

        return $user === null ? null : new IdentityContact($user->id, $user->kind, $user->enabled,
            $user->full_name, $user->email, $user->email_verified_at !== null);
    }
}
