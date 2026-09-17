<?php

declare(strict_types=1);

namespace App\Modules\Documents\Processing;

use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Infrastructure\Async\PermanentOperationFailure;
use Closure;
use Illuminate\Contracts\Container\Container;

/** Avoid resolving credential-bearing adapters during image build/bootstrap. */
final readonly class DocumentOperationHandler implements OperationHandler
{
    public function __construct(private Container $container, private string $kind) {}

    public function execute(OperationClaim $operation): Closure
    {
        $handler = match ($this->kind) {
            'documents.scan' => $this->container->make(ScanDocument::class),
            'documents.delete' => $this->container->make(DeleteDocument::class),
            'documents.delete_orphan' => $this->container->make(DeleteOrphanObject::class),
            default => throw new PermanentOperationFailure('handler_missing'),
        };

        return $handler->execute($operation);
    }
}
