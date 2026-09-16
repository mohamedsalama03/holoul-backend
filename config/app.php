<?php

declare(strict_types=1);

use App\Infrastructure\Configuration\Environment;

return [
    'name' => 'HOLOUL',
    'env' => Environment::string('APP_ENV', 'production'),
    'debug' => false,
    'url' => Environment::string('APP_URL', 'http://localhost:8080'),
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'cipher' => 'AES-256-CBC',
    'key' => Environment::secret('APP_KEY'),
    'previous_keys' => [],
    'maintenance' => ['driver' => 'file'],
    'trusted_hosts' => Environment::list('APP_TRUSTED_HOSTS', 'localhost,127.0.0.1'),
    'trusted_proxies' => Environment::list('APP_TRUSTED_PROXIES'),
];
