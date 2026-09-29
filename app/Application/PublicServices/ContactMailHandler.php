<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Modules\Contact\Actions\SendContactMail;
use Closure;
use Illuminate\Contracts\Container\Container;

final readonly class ContactMailHandler implements OperationHandler
{
    public function __construct(private Container $container) {}

    public function execute(OperationClaim $claim): Closure
    {
        return $this->container->make(SendContactMail::class)->execute($claim);
    }
}
