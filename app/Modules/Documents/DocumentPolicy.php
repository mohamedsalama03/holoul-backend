<?php

declare(strict_types=1);

namespace App\Modules\Documents;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/** Initial approved policy is deliberately centralized and closed. */
final class DocumentPolicy
{
    public const int MAX_BYTES = 10 * 1024 * 1024;

    public const int MAX_UPLOADING = 2;

    public const int MAX_RESERVED_BYTES = 1024 * 1024 * 1024;

    public const int MAX_DOCUMENTS = 1000;

    public const int UPLOAD_MINUTES = 10;

    public const int ORPHAN_GRACE_HOURS = 24;

    public const int MANUAL_SCAN_RETRIES = 2;

    public static function graceExpired(DateTimeInterface $modifiedAt): bool
    {
        return DB::scalar('SELECT ?::timestamptz <= clock_timestamp() - make_interval(hours => ?)',
            [$modifiedAt->format('Y-m-d H:i:s.uP'), self::ORPHAN_GRACE_HOURS]) === true;
    }
}
