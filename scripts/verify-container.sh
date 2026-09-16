#!/bin/sh
set -eu
composer validate --strict
composer install --prefer-dist --no-interaction --no-progress
composer check-platform-reqs
composer audit --locked --no-interaction
vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
php scripts/assert-test-database.php
php artisan migrate:fresh --force --no-interaction
php artisan migrate --force --no-interaction
php artisan migrate --force --no-interaction
vendor/bin/phpunit --testsuite Unit,Feature --log-junit /tmp/holoul-unit-feature.xml
php -r '
$report = new DOMDocument();
if (! $report->load("/tmp/holoul-unit-feature.xml", LIBXML_NONET)) { exit(1); }
foreach ($report->getElementsByTagName("testsuite") as $suite) {
    if ($suite->hasAttribute("file")) {
        printf("%s: %s tests, %s assertions\n", $suite->getAttribute("name"), $suite->getAttribute("tests"), $suite->getAttribute("assertions"));
    }
}
'
vendor/bin/phpunit --testsuite Architecture --log-junit /tmp/holoul-architecture.xml
php artisan migrate:fresh --force --no-interaction
php artisan config:cache --no-interaction
php artisan route:list --json
php artisan config:clear --no-interaction
