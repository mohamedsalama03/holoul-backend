#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
# Test drivers are not embedded in production images. This override is local-only.
export COMPOSE_FILE="compose.yaml:compose.verification.yaml"
b1_workspace="$(pwd -P)"
mkdir -p artifacts/b8-quality
# Synthetic local-provider smoke only; no external customer-data provider is enabled.
export HOLOUL_AI_ENABLED=true
b1_scanner='aquasec/trivy:0.74.0@sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969'

docker compose config --quiet
docker build -f docker/contracts/Dockerfile -t holoul-contracts:b8-p2 .
docker run --rm --network none --mount "type=bind,src=${b1_workspace},dst=/work,readonly" \
  holoul-contracts:b8-p2 scripts/contracts/build.py --check
docker compose build 2>&1 | tee artifacts/development-build.log
docker build --target runtime -t holoul-app:b8-runtime . 2>&1 | tee artifacts/runtime-build.log
docker run --rm --entrypoint php-fpm holoul-app:b8-runtime -t 2>&1 | tee artifacts/runtime-fpm-config.log
docker compose up -d --wait --wait-timeout 900
# Refresh the mounted edge configuration even when reusing an earlier stack.
docker compose up -d --no-deps --force-recreate --wait --wait-timeout 180 nginx
docker compose run --rm --no-deps --volume "${b1_workspace}/artifacts/b8-quality:/verification-artifacts" \
  --volume "${b1_workspace}/docs:/var/www/html/docs:ro" \
  --env HOLOUL_CONTRACT_CAPTURE=/verification-artifacts/contract-samples.jsonl \
  verify sh -c 'umask 077; exec sh scripts/verify-container.sh' 2>&1 | tee artifacts/quality.log
docker run --rm --network none --mount "type=bind,src=${b1_workspace},dst=/work,readonly" \
  --mount "type=bind,src=${b1_workspace}/artifacts/b8-quality,dst=/evidence" holoul-contracts:b8-p2 \
  scripts/contracts/validate.py --samples /evidence/contract-samples.jsonl --report /evidence/contract-report.json \
  2>&1 | tee artifacts/contract-validation.log
docker run --rm --network none --mount "type=bind,src=${b1_workspace},dst=/work,readonly" \
  --mount "type=bind,src=${b1_workspace}/artifacts/b8-quality,dst=/evidence,readonly" \
  --env HOLOUL_CONTRACT_SAMPLES=/evidence/contract-samples.jsonl holoul-contracts:b8-p2 \
  tests/Python/test_api_contract_validation.py 2>&1 | tee artifacts/contract-negative-tests.log
docker compose run --rm --no-deps --entrypoint python3 \
  --volume "${b1_workspace}/tests/Python:/tests:ro" inspector -I /tests/test_document_inspector.py \
  2>&1 | tee artifacts/document-inspector-tests.log

b1_port="$(docker compose port nginx 8080)"
b1_origin="http://${b1_port}"
curl --fail --silent --show-error "${b1_origin}/health/live" | tee artifacts/liveness.json
curl --fail --silent --show-error "${b1_origin}/health/ready" | tee artifacts/readiness.json
curl --fail --silent --show-error -D artifacts/api-headers.txt "${b1_origin}/api/v1" | tee artifacts/api.json
grep -qi '^X-Request-ID: ' artifacts/api-headers.txt
grep -qi '^X-Content-Type-Options: nosniff' artifacts/api-headers.txt

b1_probe="b1_private_query_probe_$(date +%s)"
head -c 12582913 /dev/zero | curl --silent --show-error -H 'Content-Type: application/json' --data-binary @- \
  -o artifacts/oversized.json -w '%{http_code}' "${b1_origin}/api/v1?token=${b1_probe}" > artifacts/oversized.status
test "$(cat artifacts/oversized.status)" = 413
grep -q '"request_id":' artifacts/oversized.json
docker compose logs --no-color nginx app queue document-queue ai-queue notification-queue scheduler > artifacts/service-smoke.log
if grep -Fq "$b1_probe" artifacts/service-smoke.log; then
  printf '%s\n' 'Private query marker leaked into logs.' >&2
  exit 1
fi

docker compose exec -T queue php docker/app/check-health.php queue
docker compose exec -T document-queue php docker/app/check-health.php queue
docker compose exec -T ai-queue php docker/app/check-health.php queue
docker compose exec -T notification-queue php docker/app/check-health.php queue
docker compose exec -T scheduler php docker/app/check-health.php scheduler
docker compose exec -T app php -v | tee artifacts/php-version.txt
docker compose exec -T postgres postgres --version | tee artifacts/postgresql-version.txt
docker compose exec -T redis redis-server --version | tee artifacts/redis-version.txt
docker compose exec -T nginx nginx -v 2>&1 | tee artifacts/nginx-version.txt

# Exercise the exact release image against the same private development services.
HOLOUL_APP_IMAGE=holoul-app:b8-runtime HOLOUL_APP_ENV=production \
  docker compose up -d --no-build --force-recreate --wait --wait-timeout 180 app queue document-queue ai-queue notification-queue scheduler nginx
curl --fail --silent --show-error "${b1_origin}/health/ready" | tee artifacts/runtime-readiness.json
docker compose exec -T queue php docker/app/check-health.php queue
docker compose exec -T scheduler php docker/app/check-health.php scheduler
b1_release_image="$(docker image inspect --format '{{.Id}}' holoul-app:b8-runtime)"
for b1_service in app queue document-queue ai-queue notification-queue scheduler; do
  b1_running_image="$(docker inspect --format '{{.Image}}' "$(docker compose ps -q "$b1_service")")"
  test "$b1_running_image" = "$b1_release_image"
  docker compose exec -T "$b1_service" php scripts/verify-runtime.php environment \
    | tee "artifacts/runtime-${b1_service}-environment.json"
done
docker compose exec -T app timeout 160 php scripts/verify-runtime.php async \
  | tee artifacts/runtime-async.json

bash scripts/verify-identity.sh
bash scripts/verify-intake.sh
bash scripts/verify-documents.sh
bash scripts/verify-proposals.sh
bash scripts/verify-projects.sh
bash scripts/verify-assistance.sh
docker compose exec -T app php artisan operations:validate-config --json | tee artifacts/runtime-configuration.json
docker compose exec -T app php artisan operations:observe --json | tee artifacts/runtime-operations.json
b8_performance_passed=true
if ! python3 scripts/verify-performance.py 2>&1 | tee artifacts/b8-performance.log; then
  # Preserve the failure while completing independent recovery/security evidence.
  # A failed latency gate must never become a successful release verification.
  b8_performance_passed=false
fi
bash scripts/verify-restore.sh 2>&1 | tee artifacts/b8-restore.log
python3 scripts/verify-intake-edge.py --origin "$b1_origin" | tee artifacts/runtime-intake-ingress.json

for b4_isolated in scanner inspector; do
  test "$(docker inspect --format '{{.HostConfig.NetworkMode}}' "$(docker compose ps -q "$b4_isolated")")" = none
done
docker compose exec -T storage weed version | tee artifacts/storage-version.txt
docker compose exec -T scanner clamd --version | tee artifacts/scanner-version.txt
docker compose exec -T inspector qpdf --version | tee artifacts/inspector-version.txt
docker compose exec -T inspector pdftotext -v 2>&1 | tee artifacts/extractor-version.txt

docker run --rm --mount "type=bind,src=${b1_workspace},dst=/work,readonly" \
  "$b1_scanner" fs --scanners secret --skip-dirs vendor,artifacts,.git --exit-code 1 /work \
  2>&1 | tee artifacts/secret-scan.log
b1_scan_cache="${COMPOSE_PROJECT_NAME:-holoul}_trivy_cache"
docker run --rm --mount "type=bind,src=${b1_workspace},dst=/work,readonly" \
  --mount "type=volume,src=${b1_scan_cache},dst=/root/.cache/trivy" \
  "$b1_scanner" fs --scanners vuln --include-dev-deps --skip-dirs vendor,artifacts,.git \
  --severity HIGH,CRITICAL --exit-code 1 --timeout 30m --no-progress \
  --db-repository public.ecr.aws/aquasecurity/trivy-db:2,mirror.gcr.io/aquasec/trivy-db:2,ghcr.io/aquasecurity/trivy-db:2 /work \
  2>&1 | tee artifacts/b8-source-security.log
printf '%s\n' holoul-app:b8-runtime > artifacts/scanned-images.txt
docker compose config --images | grep -v '^holoul-app:' | sort -u >> artifacts/scanned-images.txt
while IFS= read -r b1_image; do
  b1_scan_name="$(printf '%s' "$b1_image" | cut -d: -f1 | tr '/.' '--')"
  docker image save -o artifacts/image-scan.tar "$b1_image"
  docker run --rm --mount "type=bind,src=${b1_workspace}/artifacts,dst=/scan,readonly" \
    --mount "type=volume,src=${b1_scan_cache},dst=/root/.cache/trivy" \
    "$b1_scanner" image --input /scan/image-scan.tar --scanners vuln --timeout 30m --no-progress \
    --db-repository public.ecr.aws/aquasecurity/trivy-db:2,mirror.gcr.io/aquasec/trivy-db:2,ghcr.io/aquasecurity/trivy-db:2 \
    --severity HIGH,CRITICAL --exit-code 1 2>&1 | tee "artifacts/${b1_scan_name}-security.log"
done < artifacts/scanned-images.txt
# Leave the exact scanned artifact with optional AI disabled on every process.
HOLOUL_APP_IMAGE=holoul-app:b8-runtime HOLOUL_APP_ENV=production HOLOUL_AI_ENABLED=false \
  docker compose up -d --no-deps --no-build --force-recreate --wait --wait-timeout 180 app queue document-queue ai-queue notification-queue scheduler nginx
for b8_service in app queue document-queue ai-queue notification-queue scheduler; do
  test "$(docker inspect --format '{{.Image}}' "$(docker compose ps -q "$b8_service")")" = "$b1_release_image"
  docker compose exec -T "$b8_service" php scripts/verify-runtime.php ai-disabled \
    | tee "artifacts/b8-disabled-${b8_service}.json"
done
curl --fail --silent --show-error "${b1_origin}/health/ready" | tee artifacts/b8-disabled-readiness.json
if [ "$b8_performance_passed" != true ]; then
  printf '%s\n' 'B8 verification remains blocked: performance acceptance failed; independent recovery/security checks completed.' >&2
  exit 1
fi
printf '%s\n' 'All B1 through B8 applicable local verification gates passed; see production launch blockers.'
