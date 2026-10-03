<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Current account authority for previously consented durable work; no session credentials are retained. */
final readonly class ReadActiveIdentity
{
    public function __construct(private RoleAuthority $authority) {}

    public function locked(string $id): AuthorizedIdentity
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Durable authorization requires a transaction.');
        }
        // Still excludes account/role mutations, while allowing recipient FK
        // checks by a transaction holding the source resource this worker needs.
        $user = User::query()->whereKey($id)->lock('for no key update')->first();
        if ($user === null || ! $user->enabled) {
            throw new AuthorizationException;
        }

        return new AuthorizedIdentity($id, $user->kind, $user->email_verified_at !== null,
            $this->authority->permissionsFor($this->authority->roles($id)), $user->username !== null);
    }
}
