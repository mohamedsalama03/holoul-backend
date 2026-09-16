<?php

declare(strict_types=1);

return [
    'default' => 'redis',
    'limiter' => 'redis',
    'stores' => [
        'redis' => ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default'],
        'array' => ['driver' => 'array', 'serialize' => false],
    ],
    'prefix' => 'cache_',
];
