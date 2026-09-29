<?php

declare(strict_types=1);

namespace App\Modules\Contact\Data;

use Illuminate\Auth\Access\AuthorizationException;

/** Trusted context created inside the locked Identity application workflow. */
final readonly class ContactActor
{
    /** @param list<string> $permissions */
    public function __construct(public string $id, public array $permissions, public bool $recentPassword) {}

    public function require(string $permission): void
    {
        if (! in_array($permission, $this->permissions, true) || ($permission === 'contact.redact' && ! $this->recentPassword)) {
            throw new AuthorizationException;
        }
    }
}
