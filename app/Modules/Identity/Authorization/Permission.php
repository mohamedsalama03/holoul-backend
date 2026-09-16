<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization;

enum Permission: string
{
    case ReadOwnIdentity = 'identity.self.read';
    case UpdateOwnIdentity = 'identity.self.update';
    case ReadOwnCustomer = 'customers.self.read';
    case UpdateOwnCustomer = 'customers.self.update';
    case ReadStaff = 'identity.staff.read';
    case ManageStaff = 'identity.staff.manage';
    case ManageSecurity = 'identity.security.manage';
}
