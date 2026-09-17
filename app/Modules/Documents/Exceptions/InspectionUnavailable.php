<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use RuntimeException;

final class InspectionUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Document inspection is unavailable.');
    }
}
