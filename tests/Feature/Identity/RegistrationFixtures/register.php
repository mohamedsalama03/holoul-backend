<?php

declare(strict_types=1);

use App\Application\Identity\RegisterCustomer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$stage = 'bootstrap';

try {
    $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    Config::set('async.queue', $argv[3]);
    DB::select('SELECT set_config(?, ?, false)', ['application_name', $argv[2]]);
    DB::statement("SET statement_timeout = '4000ms'");
    $stage = 'barrier';
    DB::select('SELECT pg_advisory_lock_shared(?::bigint)', [(int) $argv[1]]);
    DB::select('SELECT pg_advisory_unlock_shared(?::bigint)', [(int) $argv[1]]);
    $stage = 'registration';
    $app->make(RegisterCustomer::class)->handle(
        'Concurrent Registration', $argv[4], 'Concurrent-Registration@example.test',
        'Registration fixture password 2026!', '+218912345678', (string) Str::uuid7(),
    );
    echo 'accepted';
} catch (Throwable) {
    // Never print database bindings, a password, a token or an SMTP payload.
    fwrite(STDERR, 'registration_fixture_failed_'.$stage);
    exit(1);
}
