<?php

declare(strict_types=1);

return [
    'default' => 'redis',
    'connections' => [
        'redis' => [
            'driver' => 'redis', 'connection' => 'default', 'queue' => 'default',
            'retry_after' => 90, 'block_for' => 5, 'after_commit' => true,
        ],
        'documents' => [
            'driver' => 'redis', 'connection' => 'default', 'queue' => 'documents',
            'retry_after' => 180, 'block_for' => 5, 'after_commit' => true,
        ],
    ],
    'failed' => ['driver' => 'database-uuids', 'database' => 'pgsql', 'table' => 'failed_jobs'],
];
