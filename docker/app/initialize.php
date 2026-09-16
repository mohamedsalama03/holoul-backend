<?php

declare(strict_types=1);

// Development-only bootstrap. Production supplies secrets externally.
if (getenv('APP_ENV') !== 'local') {
    throw new RuntimeException('Automatic secret generation is development-only.');
}

/** @param callable(): string $generate */
function secret(string $path, callable $generate): string
{
    if (! is_file($path)) {
        $value = $generate();
        if (file_put_contents($path, $value."\n", LOCK_EX) === false) {
            throw new RuntimeException('Secret initialization failed.');
        }
        chmod($path, 0444);
    }

    $value = file_get_contents($path);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException('Secret initialization failed.');
    }

    return trim($value);
}

secret('/run/holoul-app/app_key', fn (): string => 'base64:'.base64_encode(random_bytes(32)));
secret('/run/holoul-app/database_password', fn (): string => bin2hex(random_bytes(32)));
secret('/run/holoul-migrator/password', fn (): string => bin2hex(random_bytes(32)));
secret('/run/holoul-bootstrap/postgres_password', fn (): string => bin2hex(random_bytes(32)));
$redis = secret('/run/holoul-redis/password', fn (): string => bin2hex(random_bytes(32)));
$applicationRedis = secret('/run/holoul-app/redis_password', fn (): string => $redis);
if (! hash_equals($redis, $applicationRedis)) {
    throw new RuntimeException('Development secret volumes are inconsistent.');
}
fwrite(STDOUT, "Development secrets are ready.\n");
