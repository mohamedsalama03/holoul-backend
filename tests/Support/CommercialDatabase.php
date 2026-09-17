<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/** Commercial history is intentionally not downgradable; reset only the dedicated test database. */
trait CommercialDatabase
{
    protected function setUpCommercialDatabase(): void
    {
        self::assertSame('holoul_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }
}
