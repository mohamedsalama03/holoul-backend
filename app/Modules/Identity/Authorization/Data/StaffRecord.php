<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization\Data;

use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;

final readonly class StaffRecord
{
    /** @param list<string> $roles */
    public function __construct(public string $id, public string $name, public string $email, public bool $enabled, public array $roles) {}

    /** @param list<Role> $roles */
    public static function fromUser(User $user, array $roles): self
    {
        return new self($user->id, $user->full_name, $user->email, $user->enabled, array_map(fn (Role $role): string => $role->value, $roles));
    }

    /** @return array{id:string,full_name:string,email:string,enabled:bool,roles:list<string>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'full_name' => $this->name, 'email' => $this->email, 'enabled' => $this->enabled, 'roles' => $this->roles];
    }
}
