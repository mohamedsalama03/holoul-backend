<?php

declare(strict_types=1);

use HoloulLocalE2E\Fixture;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

// No secret, exception message or SQL binding is written to stdout/stderr.
$reject = static function (Throwable $error): never {
    fwrite(STDERR, 'Local E2E fixture refused or failed ('.$error::class.").\n");
    exit(1);
};
set_exception_handler($reject);
umask(0077);
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Application unavailable.');
}
$app->make(Kernel::class)->bootstrap();
set_exception_handler($reject);
require __DIR__.'/Fixture.php';
Fixture::assertLocalEnvironment();
$database = DB::selectOne('SELECT current_database() AS database, session_user AS identity');
if (! $database instanceof stdClass || $database->database !== 'holoul' || $database->identity !== 'holoul_app') {
    throw new RuntimeException('Local runtime database required.');
}
$input = '/local-e2e-private/manifest.json';
$output = '/local-e2e-private/frontend.env';
if (is_link($input) || is_link($output) || ! is_file($input) || (fileperms($input) & 0077) !== 0) {
    throw new RuntimeException('Private fixture file required.');
}
$raw = file_get_contents($input);
if ($raw === false) {
    throw new RuntimeException('Fixture configuration unavailable.');
}
$values = (new Fixture)->reset(Fixture::manifest($raw));
$contents = "# Generated synthetic local E2E identities. Never commit or log.\n";
foreach ($values as $key => $value) {
    $contents .= $key.'='.$value."\n";
}
// Explicit loopback-only switch is consumed by test tooling, never the app.
$contents .= "HOLOUL_E2E_ACCEPT_SELF_SIGNED_TLS=1\n";
if (file_put_contents($output, $contents, LOCK_EX) !== strlen($contents) || ! chmod($output, 0600)) {
    throw new RuntimeException('Cannot save private E2E configuration.');
}
fwrite(STDOUT, "Five synthetic identities reset; three real MFA enrollments; private environment saved.\n");
