<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use RuntimeException;

final class StorageConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The immutable document object conflicts.');
    }
}
