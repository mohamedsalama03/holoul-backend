<?php

declare(strict_types=1);

use App\Infrastructure\Logging\SafeLogProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

return [
    'default' => 'stdout',
    'deprecations' => ['channel' => 'stdout', 'trace' => false],
    'channels' => [
        'stdout' => [
            'driver' => 'monolog',
            'level' => 'info',
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stdout'],
            'formatter' => JsonFormatter::class,
            'processors' => [SafeLogProcessor::class],
        ],
    ],
];
