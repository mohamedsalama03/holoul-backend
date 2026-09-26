#!/bin/sh
set -eu
# Retain machine-readable evidence even if a later gate fails and Compose
# removes this disposable verification container.
b8_collect_reports() {
  if [ -d /verification-artifacts ]; then
    for b8_report in /tmp/holoul-unit-feature.xml /tmp/holoul-architecture.xml; do
      if [ -f "$b8_report" ]; then
        cp "$b8_report" /verification-artifacts/
      fi
    done
    if [ -f artifacts/b8-document-list-optimized.json ]; then
      cp artifacts/b8-document-list-optimized.json /verification-artifacts/
    fi
  fi
}
trap b8_collect_reports 0
composer validate --strict
composer install --prefer-dist --no-interaction --no-progress
composer check-platform-reqs
composer audit --locked --no-interaction
vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
php scripts/assert-test-database.php
if [ -n "${HOLOUL_CONTRACT_CAPTURE:-}" ]; then
  test "$HOLOUL_CONTRACT_CAPTURE" = /verification-artifacts/contract-samples.jsonl
  test -d /verification-artifacts
  # Each run must prove its own coverage. Raw synthetic MFA/recovery responses
  # are private test artifacts, never CI uploads or public contract examples.
  (umask 077; : > "$HOLOUL_CONTRACT_CAPTURE")
fi
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
