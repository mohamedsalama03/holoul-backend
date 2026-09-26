#!/usr/bin/env bash
# Independent contract/quality gate. Deliberately contains no performance acceptance,
# runtime stack replacement, provider enablement, deployment, commit or tagging.
set -euo pipefail
umask 077
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
p2_workspace="$(pwd -P)"
p2_run="${1:-$(date -u +%Y%m%dT%H%M%SZ)}"
contract_batch="${HOLOUL_CONTRACT_BATCH:-p3}"
[[ "$contract_batch" =~ ^p[23]$ && "$p2_run" =~ ^[a-zA-Z0-9_-]+$ ]] || exit 2
p2_artifacts="${p2_workspace}/artifacts/${contract_batch}-${p2_run}"
mkdir -p "$p2_artifacts"
# Refuse to overwrite a previous gate's evidence.
test ! -e "$p2_artifacts/quality.log"
export HOLOUL_AI_ENABLED=false
docker compose config --quiet
docker build -f docker/contracts/Dockerfile -t "holoul-contracts:b8-${contract_batch}" . > "$p2_artifacts/validator-build.log" 2>&1
docker run --rm --network none --mount "type=bind,src=${p2_workspace},dst=/work,readonly" \
  "holoul-contracts:b8-${contract_batch}" scripts/contracts/build.py --check
docker build --target development -t "holoul-app:b8-${contract_batch}-development" . > "$p2_artifacts/development-build.log" 2>&1
docker build --target runtime -t "holoul-app:b8-${contract_batch}-candidate" . > "$p2_artifacts/runtime-build.log" 2>&1
docker run --rm --network none --entrypoint php-fpm "holoul-app:b8-${contract_batch}-candidate" -t > "$p2_artifacts/fpm-check.log" 2>&1
docker compose exec -T nginx nginx -t > "$p2_artifacts/nginx-check.log" 2>&1
# docs are intentionally absent from release images; mount the reviewed spec only in verification.
HOLOUL_APP_IMAGE="holoul-app:b8-${contract_batch}-development" docker compose run --rm --no-deps \
  --volume "${p2_artifacts}:/verification-artifacts" --volume "${p2_workspace}/docs:/var/www/html/docs:ro" \
  --env HOLOUL_CONTRACT_CAPTURE=/verification-artifacts/contract-samples.jsonl \
  verify sh -c 'umask 077; exec sh scripts/verify-container.sh' > "$p2_artifacts/quality.log" 2>&1
docker run --rm --network none --mount "type=bind,src=${p2_workspace},dst=/work,readonly" \
  --mount "type=bind,src=${p2_artifacts},dst=/evidence" "holoul-contracts:b8-${contract_batch}" \
  scripts/contracts/validate.py --samples /evidence/contract-samples.jsonl --report /evidence/contract-report.json \
  > "$p2_artifacts/contract-validation.log" 2>&1
docker run --rm --network none --mount "type=bind,src=${p2_workspace},dst=/work,readonly" \
  --mount "type=bind,src=${p2_artifacts},dst=/evidence,readonly" \
  --env HOLOUL_CONTRACT_SAMPLES=/evidence/contract-samples.jsonl "holoul-contracts:b8-${contract_batch}" \
  tests/Python/test_api_contract_validation.py > "$p2_artifacts/contract-negative-tests.log" 2>&1
docker compose run --rm --no-deps --entrypoint python3 \
  --volume "${p2_workspace}/tests/Python:/tests:ro" inspector -I /tests/test_document_inspector.py \
  > "$p2_artifacts/inspector-tests.log" 2>&1
docker compose ps --format json > "$p2_artifacts/services.jsonl"
docker image inspect --format '{{.Id}}' "holoul-app:b8-${contract_batch}-development" "holoul-app:b8-${contract_batch}-candidate" "holoul-contracts:b8-${contract_batch}" \
  > "$p2_artifacts/image-identities.txt"
printf 'Contract/quality gate passed. Evidence: %s\nProduction performance certification remains pending VPS.\n' "$p2_artifacts"
