#!/usr/bin/env bash
# Local enablement gate: no initialization/certificate creation, deployment or performance benchmark.
set -euo pipefail
umask 077
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
f1_root="$(pwd -P)"
f1_run="${1:-f1-e1}"
[[ "$f1_run" =~ ^f1-e1[a-zA-Z0-9_-]*$ ]] || exit 2
f1_evidence="$f1_root/artifacts/$f1_run"
mkdir -p "$f1_evidence"
test ! -e "$f1_evidence/security-quality.log"
docker compose config --quiet
python3 scripts/contracts/build.py --check
docker build --target development -t holoul-app:f1-e1-development . > "$f1_evidence/development-final-build.log" 2>&1
docker build --target runtime -t holoul-app:f1-e1-runtime . > "$f1_evidence/runtime-build.log" 2>&1
docker run --rm --network none --entrypoint sh holoul-app:f1-e1-runtime -c \
  'test ! -e tools/local-e2e/Fixture.php && test ! -e tools/local-e2e/run.php && test ! -e vendor/bin/phpunit' \
  > "$f1_evidence/runtime-fixture-absence.log" 2>&1
docker run --rm --network none --entrypoint php-fpm holoul-app:f1-e1-runtime -t > "$f1_evidence/fpm.log" 2>&1
docker compose exec -T nginx nginx -t > "$f1_evidence/nginx-final.log" 2>&1
HOLOUL_APP_IMAGE=holoul-app:f1-e1-development docker compose run --rm --no-deps -T \
  --volume "$f1_evidence:/verification-artifacts" --volume "$f1_root/docs:/var/www/html/docs:ro" \
  verify sh -c '
    set -eu
    composer validate --strict
    composer check-platform-reqs
    composer audit --locked --no-interaction
    vendor/bin/pint --test
    vendor/bin/phpstan analyse --no-progress --memory-limit=1G
    vendor/bin/phpstan analyse --no-progress --memory-limit=1G tools/local-e2e
    php scripts/assert-test-database.php
    php artisan migrate:fresh --force --no-interaction
    vendor/bin/phpunit --testsuite Unit,Architecture --log-junit /verification-artifacts/unit-architecture.xml
    vendor/bin/phpunit --testsuite Feature --filter "Identity|Authorization|CustomerIsolation|HttpFoundation|LocalE2EFixture|ApiContract" --log-junit /verification-artifacts/security-feature.xml
  ' > "$f1_evidence/security-quality.log" 2>&1
printf 'F1-E1 quality/security gate passed. No performance certification run.\n'
