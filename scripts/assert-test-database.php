<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'holoul_test'
    || config('database.connections.pgsql.username') !== 'holoul_migrator') {
    fwrite(STDERR, "Refusing destructive checks outside the isolated test database.\n");
    exit(1);
}
fwrite(STDOUT, "Isolated PostgreSQL test database confirmed.\n");
