<?php

declare(strict_types=1);

return [
    'driver' => 'database',
    // Absolute maximum storage/cookie lifetime; database middleware applies persona idle/absolute limits.
    'lifetime' => 10080,
    'expire_on_close' => false,
    'encrypt' => true,
    'connection' => 'pgsql',
    'table' => 'sessions',
    'store' => null,
    'lottery' => [2, 100],
    'cookie' => '__Host-holoul_session',
    'path' => '/',
    'domain' => null,
    'secure' => true,
    'http_only' => true,
    'same_site' => 'lax',
    'partitioned' => false,
];
