<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Administrator = 'administrator';
    case ProjectManager = 'project_manager';
    case BusinessAnalyst = 'business_analyst';
    case Sales = 'sales';
    case Reviewer = 'reviewer';
    case Support = 'support';
    case Customer = 'customer';

    public function isSecurityRole(): bool
    {
        return $this === self::SuperAdmin || $this === self::Administrator;
    }
}
