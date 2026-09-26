<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Data;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class ClaimRejected extends HttpException
{
    public function __construct(public readonly bool $expired = false)
    {
        parent::__construct(404);
    }
}
