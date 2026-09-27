<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Infrastructure\Http\PublicFailureReason;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class StaffFailure extends HttpException implements PublicFailureReason
{
    public function __construct(int $status, private readonly string $safeReason, private readonly ?string $relatedId = null)
    {
        parent::__construct($status);
    }

    public function resourceId(): ?string
    {
        return $this->relatedId;
    }

    public function reason(): string
    {
        return $this->safeReason;
    }
}
