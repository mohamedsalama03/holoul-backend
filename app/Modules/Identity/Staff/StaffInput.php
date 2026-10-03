<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StaffInput
{
    /** @param list<string> $keys */
    public static function only(Request $request, array $keys): void
    {
        if (array_diff(array_keys($request->all()), $keys) !== []) {
            throw ValidationException::withMessages(['input' => 'Unexpected input.']);
        }
    }

    /** @return list<Role> */
    public static function roles(Request $request): array
    {
        Validator::make($request->all(), ['roles' => ['required', 'array', 'list', 'min:1', 'max:8'],
            'roles.*' => ['required', 'string', 'distinct', Rule::enum(Role::class)]])->validate();
        $value = $request->input('roles');
        if (! is_array($value)) {
            throw ValidationException::withMessages(['roles' => 'Invalid roles.']);
        }
        $roles = [];
        foreach ($value as $role) {
            if (! is_string($role)) {
                throw ValidationException::withMessages(['roles' => 'Invalid roles.']);
            }
            $roles[] = Role::from($role);
        }
        usort($roles, static fn (Role $a, Role $b): int => strcmp($a->value, $b->value));

        return $roles;
    }

    public static function version(Request $request): int
    {
        $tag = $request->header('If-Match');
        if ($tag === null) {
            throw new StaffFailure(428, 'PRECONDITION_REQUIRED');
        }
        if (! preg_match('/\A"([1-9][0-9]{0,14})"\z/D', $tag, $matches)) {
            throw ValidationException::withMessages(['If-Match' => 'Invalid version.']);
        }

        return (int) $matches[1];
    }

    public static function recent(Request $request): void
    {
        if (! app(SessionSecurity::class)->hasRecentPassword($request)) {
            throw new StaffFailure(403, 'PASSWORD_CONFIRMATION_REQUIRED');
        }
    }

    public static function token(Request $request): string
    {
        $token = $request->input('token');
        if (! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new StaffFailure(422, 'INVITATION_UNAVAILABLE');
        }

        return $token;
    }
}
