<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use Closure;
use Illuminate\Contracts\Container\Container;

final readonly class InvitationMailHandler implements OperationHandler
{
    public function __construct(private Container $container) {}

    /** @return Closure():void */
    public function execute(OperationClaim $operation): Closure
    {
        return $this->container->make(SendInvitationMail::class)->execute($operation);
    }
}
