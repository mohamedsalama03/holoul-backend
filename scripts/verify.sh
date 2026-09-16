#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
b1_workspace="$(pwd -P)"
mkdir -p artifacts
b1_scanner='aquasec/trivy:0.74.0@sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969'

docker compose config --quiet
docker compose build 2>&1 | tee artifacts/development-build.log
docker build --target runtime -t holoul-app:b3-runtime . 2>&1 | tee artifacts/runtime-build.log
docker compose up -d --wait --wait-timeout 180
# Refresh the mounted edge configuration even when reusing an earlier stack.
docker compose up -d --no-deps --force-recreate --wait --wait-timeout 180 nginx
docker compose run --rm --no-deps verify sh scripts/verify-container.sh 2>&1 | tee artifacts/quality.log

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
docker compose logs --no-color nginx app queue scheduler > artifacts/service-smoke.log
if grep -Fq "$b1_probe" artifacts/service-smoke.log; then
  printf '%s\n' 'Private query marker leaked into logs.' >&2
  exit 1
fi

docker compose exec -T queue php docker/app/check-health.php queue
docker compose exec -T scheduler php docker/app/check-health.php scheduler
docker compose exec -T app php -v | tee artifacts/php-version.txt
docker compose exec -T postgres postgres --version | tee artifacts/postgresql-version.txt
docker compose exec -T redis redis-server --version | tee artifacts/redis-version.txt
docker compose exec -T nginx nginx -v 2>&1 | tee artifacts/nginx-version.txt

# Exercise the exact release image against the same private development services.
HOLOUL_APP_IMAGE=holoul-app:b3-runtime HOLOUL_APP_ENV=production \
  docker compose up -d --no-build --force-recreate --wait --wait-timeout 180 app queue scheduler nginx
curl --fail --silent --show-error "${b1_origin}/health/ready" | tee artifacts/runtime-readiness.json
docker compose exec -T queue php docker/app/check-health.php queue
docker compose exec -T scheduler php docker/app/check-health.php scheduler
b1_release_image="$(docker image inspect --format '{{.Id}}' holoul-app:b3-runtime)"
for b1_service in app queue scheduler; do
  b1_running_image="$(docker inspect --format '{{.Image}}' "$(docker compose ps -q "$b1_service")")"
  test "$b1_running_image" = "$b1_release_image"
  docker compose exec -T "$b1_service" php scripts/verify-runtime.php environment \
    | tee "artifacts/runtime-${b1_service}-environment.json"
done
docker compose exec -T app timeout 160 php scripts/verify-runtime.php async \
  | tee artifacts/runtime-async.json

bash scripts/verify-identity.sh
bash scripts/verify-intake.sh
python3 scripts/verify-intake-edge.py --origin "$b1_origin" | tee artifacts/runtime-intake-ingress.json

docker run --rm --mount "type=bind,src=${b1_workspace},dst=/work,readonly" \
  "$b1_scanner" fs --scanners secret --skip-dirs vendor,artifacts,.git --exit-code 1 /work \
  2>&1 | tee artifacts/secret-scan.log
b1_scan_cache="${COMPOSE_PROJECT_NAME:-holoul}_trivy_cache"
printf '%s\n' holoul-app:b3-runtime > artifacts/scanned-images.txt
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
printf '%s\n' 'All B1, B2 and B3 verification gates passed.'
