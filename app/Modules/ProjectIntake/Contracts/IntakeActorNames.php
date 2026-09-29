<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Contracts;

interface IntakeActorNames
{
    /**
     * Only IDs taken from already authorized intake records; never a public directory lookup.
     * Disabled identities remain named in historical records. No contact or authorization data.
     *
     * @param  list<string>  $ids
     * @return array<string,array{id:string,kind:string,display_name:string}>
     */
    public function lookup(array $ids): array;
}
