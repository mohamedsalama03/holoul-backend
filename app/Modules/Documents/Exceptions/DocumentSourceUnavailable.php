<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use RuntimeException;

final class DocumentSourceUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The approved document source is no longer available.');
    }
}
