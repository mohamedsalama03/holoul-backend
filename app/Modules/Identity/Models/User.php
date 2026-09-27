<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $full_name
 * @property string $email
 * @property string $email_display
 * @property string $password
 * @property string $kind
 * @property bool $enabled
 * @property int $authorization_revision
 * @property int $auth_version
 * @property Carbon|null $email_verified_at
 */
final class User extends Authenticatable
{
    use HasUuids;

    protected $table = 'users';

    protected $fillable = ['full_name', 'email', 'email_display', 'password', 'kind', 'enabled', 'email_verified_at', 'auth_version'];

    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'hashed', 'enabled' => 'boolean', 'auth_version' => 'integer', 'authorization_revision' => 'integer', 'email_verified_at' => 'immutable_datetime'];
    }
}
