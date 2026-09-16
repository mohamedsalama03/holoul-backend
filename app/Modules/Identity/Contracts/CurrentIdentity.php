<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

final class CurrentIdentity
{
    public function fromRequest(Request $request): IdentityRecord
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->enabled) {
            throw new AuthenticationException;
        }

        return new IdentityRecord($user->id, $user->kind, $user->enabled);
    }
}
