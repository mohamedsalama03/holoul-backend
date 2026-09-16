<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use RuntimeException;

final class LostOperationLease extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The operation lease is no longer current.');
    }
}
