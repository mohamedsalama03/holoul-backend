<?php

declare(strict_types=1);

namespace App\Modules\Discovery;

use App\Modules\Discovery\Contracts\DiscoveryReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DatabaseDiscoveryReader implements DiscoveryReader
{
    public function requireCurrentCompleted(string $requestId, string $revisionId): void
    {
        if (! Str::isUuid($revisionId, 7) || ! DB::table('discovery_records')->where('request_id', $requestId)->where('current_revision_id', $revisionId)->exists()
            || ! DB::table('discovery_revisions')->where('id', $revisionId)->where('request_id', $requestId)->where('state', 'completed')->sharedLock()->first()
            || ! DB::table('discovery_signoffs')->where('revision_id', $revisionId)->exists()) {
            throw new HttpException(409);
        }
    }
}
