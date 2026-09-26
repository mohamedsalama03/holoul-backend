<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

use App\Infrastructure\Configuration\ProductionConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class Readiness
{
    public function __construct(private readonly ProductionConfiguration $configuration) {}

    public function ready(): bool
    {
        try {
            if ($this->configuration->violations() !== []) {
                return false;
            }

            return DB::scalar('SHOW transaction_read_only') === 'off'
                && Schema::hasTable('sessions')
                && Schema::hasTable('audit_events')
                && Schema::hasTable('async_operations')
                && DB::scalar("SELECT has_table_privilege(current_user, 'public.sessions', 'SELECT')
                    AND has_table_privilege(current_user, 'public.sessions', 'INSERT')
                    AND has_table_privilege(current_user, 'public.sessions', 'UPDATE')
                    AND has_table_privilege(current_user, 'public.sessions', 'DELETE')
                    AND has_table_privilege(current_user, 'public.audit_events', 'INSERT')
                    AND has_table_privilege(current_user, 'public.async_operations', 'SELECT')
                    AND has_table_privilege(current_user, 'public.async_operations', 'INSERT')
                    AND has_table_privilege(current_user, 'public.async_operations', 'UPDATE')") === true;
        } catch (Throwable) {
            return false;
        }
    }
}
