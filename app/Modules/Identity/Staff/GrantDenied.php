<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Infrastructure\Http\PublicFailureReason;
use Illuminate\Auth\Access\AuthorizationException;

final class GrantDenied extends AuthorizationException implements PublicFailureReason
{
    public function __construct(private readonly string $safeReason)
    {
        parent::__construct();
    }

    public function resourceId(): ?string
    {
        return null;
    }

    public function reason(): string
    {
        return $this->safeReason;
    }
}
