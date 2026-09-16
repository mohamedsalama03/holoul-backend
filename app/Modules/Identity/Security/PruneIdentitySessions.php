<?php

declare(strict_types=1);

namespace App\Modules\Identity\Security;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final class PruneIdentitySessions extends Command
{
    protected $signature = 'identity:sessions:prune';

    protected $description = 'Delete at most 500 expired or idle identity session records';

    public function handle(): int
    {
        // One bounded PostgreSQL statement; active writers are skipped and no
        // Redis lock or queue is required for this security housekeeping.
        $deleted = DB::delete(<<<'SQL'
            DELETE FROM identity_sessions
            WHERE id IN (
                SELECT session.id
                FROM identity_sessions AS session
                INNER JOIN users AS account ON account.id = session.user_id
                WHERE session.expires_at <= statement_timestamp()
                   OR session.last_activity_at <= statement_timestamp() - (
                       CASE account.kind WHEN 'staff' THEN ?::integer ELSE ?::integer END * interval '1 second'
                   )
                ORDER BY session.expires_at, session.id
                LIMIT 500
                FOR UPDATE OF session SKIP LOCKED
            )
            SQL, [Config::integer('identity.staff_idle_seconds'), Config::integer('identity.customer_idle_seconds')]);
        $this->line((string) $deleted);

        return self::SUCCESS;
    }
}
