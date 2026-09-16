<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization\Commands;

use App\Modules\Identity\Authorization\Actions\BootstrapSuperAdmin;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Recovery\RecoveryActions;
use App\Modules\Identity\Security\IdentityInput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BootstrapSuperAdminCommand extends Command
{
    protected $signature = 'identity:bootstrap-super-admin {--name=} {--email=}';

    protected $description = 'Provision the first staff security administrator with interactive secret entry.';

    public function handle(BootstrapSuperAdmin $bootstrap, RecoveryActions $recovery): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Interactive secret entry is required.');

            return self::FAILURE;
        }

        $name = $this->option('name');
        $email = $this->option('email');

        if (! is_string($name) || ! is_string($email)) {
            throw ValidationException::withMessages(['identity' => 'Explicit name and email options are required.']);
        }

        $name = IdentityInput::name($name);
        $normalizedEmail = IdentityInput::email($email);
        Validator::make(['name' => $name, 'email' => $normalizedEmail], [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
        ])->validate();
        $password = $this->secret('Password', fallback: false);
        $confirmation = $this->secret('Confirm password', fallback: false);
        Validator::make(['password' => $password, 'password_confirmation' => $confirmation], ['password' => [...IdentityInput::passwordRules(), 'confirmed']])->validate();

        if (! is_string($password)) {
            throw ValidationException::withMessages(['password' => 'A password is required.']);
        }

        $passwordHash = Hash::make($password);
        DB::transaction(function () use ($bootstrap, $recovery, $name, $email, $normalizedEmail, $passwordHash): void {
            $user = User::query()->create([
                'full_name' => $name, 'email' => $normalizedEmail, 'email_display' => trim($email),
                'password' => $passwordHash, 'kind' => 'staff', 'enabled' => true, 'email_verified_at' => null, 'auth_version' => 1,
            ]);
            $requestId = Str::uuid7()->toString();
            $bootstrap->handle($user, $requestId);
            $recovery->issueVerification($user, $requestId);
        });
        $this->info('Initial staff administrator provisioned. Email verification and MFA enrollment are required.');

        return self::SUCCESS;
    }
}
