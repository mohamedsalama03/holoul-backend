<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use RuntimeException;

final class ScannerUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Document scanning is unavailable.');
    }
}
