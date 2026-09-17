<?php

declare(strict_types=1);

namespace App\Modules\Documents\Data;

enum DocumentState: string
{
    case Uploading = 'uploading';
    case Quarantined = 'quarantined';
    case Available = 'available';
    case Rejected = 'rejected';
    case Deleting = 'deleting';
    case Deleted = 'deleted';
}
