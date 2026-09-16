<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class VersionPrecondition
{
    public static function require(?string $header, string $id, int $version): void
    {
        if ($header === null || $header === '') {
            throw new HttpException(428);
        }
        if (! hash_equals(self::etag($id, $version), $header)) {
            throw new HttpException(412);
        }
    }

    public static function etag(string $id, int $version): string
    {
        return '"'.$id.':'.$version.'"';
    }
}
