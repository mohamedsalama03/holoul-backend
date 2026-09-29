<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Models\User;

final class ReadIdentityDisplayNames
{
    /**
     * Trusted IDs selected by an authorized application read, including disabled historical actors.
     *
     * @param  list<string>  $ids
     * @return array<string,array{id:string,kind:string,display_name:string}>
     */
    public function lookup(array $ids): array
    {
        $names = [];
        foreach (User::query()->whereIn('id', array_values(array_unique($ids)))->get(['id', 'kind', 'full_name']) as $user) {
            $names[$user->id] = ['id' => $user->id, 'kind' => $user->kind, 'display_name' => $user->full_name];
        }

        return $names;
    }
}
