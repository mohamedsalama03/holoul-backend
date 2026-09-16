<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Modules\Identity\Recovery\SendRecoveryMail;
use Closure;
use Illuminate\Contracts\Container\Container;

/** Package discovery/builds register handlers without resolving runtime secrets. */
final readonly class RecoveryMailHandler implements OperationHandler
{
    public function __construct(private Container $container) {}

    /** @return Closure(): void */
    public function execute(OperationClaim $operation): Closure
    {
        return $this->container->make(SendRecoveryMail::class)->execute($operation);
    }
}
