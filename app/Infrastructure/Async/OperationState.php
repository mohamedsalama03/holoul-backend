<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

enum OperationState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
