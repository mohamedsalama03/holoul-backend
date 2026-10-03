<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Contracts\IdentityContact;
use App\Modules\Identity\Contracts\IdentityReader;
use App\Modules\Identity\Contracts\IdentityRecord;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\DatabaseIdentityReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

/** Historical upgrade fixtures read real B6/B7 rows using their original column set. */
final class PreUsernameIdentityReader implements IdentityReader
{
    public function find(string $id): ?IdentityRecord
    {
        return (new DatabaseIdentityReader)->find($id);
    }

    public function contact(string $id, bool $lock = false): ?IdentityContact
    {
        if (DB::connection()->getDatabaseName() !== 'holoul_test' || Schema::hasColumn('users', 'username')) {
            throw new LogicException('This reader is only for the historical pre-username test schema.');
        }
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
