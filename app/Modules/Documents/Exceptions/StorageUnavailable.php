<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use RuntimeException;

final class StorageUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Document storage is unavailable.');
    }
}
