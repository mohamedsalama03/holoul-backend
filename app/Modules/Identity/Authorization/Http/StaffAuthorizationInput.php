<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization\Http;

use App\Modules\Identity\Authorization\Role;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StaffAuthorizationInput
{
    /** @return list<Role> */
    public static function roles(Request $request): array
    {
        if (array_diff(array_keys($request->all()), ['roles', 'enabled']) !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported input fields.']);
        }

        $validated = $request->validate([
            'roles' => ['required', 'array', 'list', 'min:1', 'max:7'],
            'roles.*' => ['required', 'string', 'distinct:strict', Rule::enum(Role::class)],
            'enabled' => ['required', 'boolean'],
        ]);
        $values = is_array($validated) ? ($validated['roles'] ?? null) : null;

        if (! is_array($values) || ! array_is_list($values)) {
            throw ValidationException::withMessages(['roles' => 'Staff roles are required.']);
        }

        return array_map(function (mixed $value): Role {
            if (! is_string($value) || ($role = Role::tryFrom($value)) === null) {
                throw ValidationException::withMessages(['roles' => 'The staff role is invalid.']);
            }

            return $role;
        }, $values);
    }
}
