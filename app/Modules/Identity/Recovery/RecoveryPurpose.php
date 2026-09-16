<?php

declare(strict_types=1);

namespace App\Modules\Identity\Recovery;

enum RecoveryPurpose: string
{
    case EmailVerification = 'verify_email';
    case PasswordReset = 'password_reset';
}
