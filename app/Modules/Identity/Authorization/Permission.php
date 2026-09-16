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
    case ManageTaxonomy = 'taxonomy.manage';
    case ReadIntake = 'intake.read';
    case ReadAllIntake = 'intake.read_all';
    case AssignIntake = 'intake.assign';
    case ReviewIntake = 'intake.review';
    case RequestIntakeInformation = 'intake.information';
    case StartIntakeDiscovery = 'intake.discovery';
    case RejectIntake = 'intake.reject';
}
