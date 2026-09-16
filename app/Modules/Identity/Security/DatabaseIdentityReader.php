<?php

declare(strict_types=1);

namespace App\Modules\Identity\Security;

use App\Modules\Identity\Contracts\IdentityReader;
use App\Modules\Identity\Contracts\IdentityRecord;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Str;

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
}
