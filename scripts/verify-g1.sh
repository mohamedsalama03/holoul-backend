#!/usr/bin/env bash
# G1 backend gate only; never mutates the live application's database or certifies performance.
set -euo pipefail
umask 077
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
g1_root="$(pwd -P)"
g1_run="${1:-final}"
[[ "$g1_run" =~ ^[a-zA-Z0-9_-]+$ ]] || exit 2
g1_evidence="$g1_root/artifacts/g1/$g1_run"
mkdir -p "$g1_evidence"
test ! -e "$g1_evidence/quality.log"
docker compose config --quiet
python3 scripts/contracts/build.py --check
docker build --target development -t holoul-app:g1-development . > "$g1_evidence/development-build.log" 2>&1
docker build --target runtime -t holoul-app:g1-runtime . > "$g1_evidence/runtime-build.log" 2>&1
docker run --rm --network none --entrypoint php-fpm holoul-app:g1-runtime -t > "$g1_evidence/fpm.log" 2>&1
docker run --rm --network none --entrypoint sh holoul-app:g1-runtime -c 'test ! -e tools/local-e2e/Fixture.php && test ! -e vendor/bin/phpunit' > "$g1_evidence/runtime-isolation.log" 2>&1
HOLOUL_APP_IMAGE=holoul-app:g1-runtime HOLOUL_APP_ENV=production HOLOUL_AI_ENABLED=false \
  docker compose run --rm --no-deps -T app php scripts/verify-runtime.php ai-disabled > "$g1_evidence/production-mode.log" 2>&1
docker compose exec -T nginx nginx -t > "$g1_evidence/nginx.log" 2>&1
HOLOUL_APP_IMAGE=holoul-app:g1-development docker compose run --rm --no-deps -T \
  --volume "$g1_evidence:/verification-artifacts" --volume "$g1_root/docs:/var/www/html/docs:ro" \
  --env HOLOUL_CONTRACT_CAPTURE=/verification-artifacts/contract-samples.jsonl \
  verify sh -c 'umask 077; exec sh scripts/verify-container.sh' > "$g1_evidence/quality.log" 2>&1
docker run --rm --network none --mount "type=bind,src=$g1_root,dst=/work,readonly" \
  --mount "type=bind,src=$g1_evidence,dst=/evidence" holoul-contracts:b8-p3 \
  scripts/contracts/validate.py --samples /evidence/contract-samples.jsonl --report /evidence/contract-report.json > "$g1_evidence/contracts.log" 2>&1
docker run --rm --network none --mount "type=bind,src=$g1_root,dst=/work,readonly" \
  --mount "type=bind,src=$g1_evidence,dst=/evidence,readonly" --env HOLOUL_CONTRACT_SAMPLES=/evidence/contract-samples.jsonl \
  holoul-contracts:b8-p3 tests/Python/test_api_contract_validation.py > "$g1_evidence/contract-negative-tests.log" 2>&1
docker compose run --rm --no-deps --entrypoint python3 --volume "$g1_root/tests/Python:/tests:ro" \
  inspector -I /tests/test_document_inspector.py > "$g1_evidence/inspector-tests.log" 2>&1
docker compose ps --format json > "$g1_evidence/services.jsonl"
docker image inspect --format '{{.Id}}' holoul-app:g1-development holoul-app:g1-runtime holoul-contracts:b8-p3 > "$g1_evidence/image-identities.txt"
printf 'G1 backend gate passed; production performance certification remains pending VPS.\n'
