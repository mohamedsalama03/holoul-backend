<?php

declare(strict_types=1);

use App\Infrastructure\Configuration\Environment;

return [
    'default' => 'pgsql',
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => Environment::string('DB_HOST', 'postgres'),
            'port' => Environment::string('DB_PORT', '5432'),
            'database' => Environment::string('DB_DATABASE', 'holoul'),
            'username' => Environment::string('DB_USERNAME', 'holoul_app'),
            'password' => Environment::secret('DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => Environment::string('DB_SSLMODE', Environment::string('APP_ENV', 'production') === 'production' ? 'verify-full' : 'prefer'),
            'timezone' => 'UTC',
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
    'redis' => [
        'client' => 'phpredis',
        'options' => [
            'cluster' => 'redis',
            'prefix' => Environment::string('REDIS_PREFIX', 'holoul_'.Environment::string('APP_ENV', 'production').'_'),
        ],
        'default' => [
            'host' => Environment::string('REDIS_HOST', 'redis'),
            'password' => Environment::secret('REDIS_PASSWORD'),
            'port' => Environment::string('REDIS_PORT', '6379'),
            'database' => '0',
            'timeout' => 2.0,
            'read_timeout' => 10.0,
        ],
        'cache' => [
            'host' => Environment::string('REDIS_HOST', 'redis'),
            'password' => Environment::secret('REDIS_PASSWORD'),
            'port' => Environment::string('REDIS_PORT', '6379'),
            'database' => '1',
            'timeout' => 2.0,
            'read_timeout' => 2.0,
        ],
    ],
];
