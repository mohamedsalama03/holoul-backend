<?php

declare(strict_types=1);

namespace App\Infrastructure\Configuration;

use Illuminate\Support\Env;
use RuntimeException;

final class Environment
{
    public static function string(string $name, string $default = ''): string
    {
        // Called only while config/*.php are evaluated. Runtime consumers use Config.
        $value = Env::get($name, $default);

        if (! is_string($value)) {
            throw new RuntimeException('Invalid environment configuration.');
        }

        return $value;
    }

    public static function secret(string $name): string
    {
        $value = self::string($name);

        if ($value !== '') {
            return $value;
        }

        $path = self::string($name.'_FILE');

        if ($path === '') {
            return '';
        }

        if (! is_readable($path)) {
            throw new RuntimeException('A required secret is unavailable.');
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('A required secret is unavailable.');
        }

        return trim($content);
    }

    /** @return list<string> */
    public static function list(string $name, string $default = ''): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', self::string($name, $default))), fn (string $value): bool => $value !== ''));
    }
}
