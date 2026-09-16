<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

final class CreateCustomerIdentity
{
    public function handle(string $name, string $email, string $displayEmail, #[SensitiveParameter] string $password): ?User
    {
        $hash = Hash::make($password);
        try {
            return DB::transaction(function () use ($name, $email, $displayEmail, $hash): ?User {
                if (User::query()->where('email', $email)->exists()) {
                    return null;
                }

                return User::query()->create([
                    'full_name' => $name, 'email' => $email, 'email_display' => $displayEmail,
                    'password' => $hash, 'kind' => 'customer', 'enabled' => true, 'auth_version' => 1,
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! User::query()->where('email', $email)->exists()) {
                throw $exception;
            }

            return null;
        }
    }
}
