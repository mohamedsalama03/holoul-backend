#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
b6_workspace="$(pwd -P)"
mkdir -p artifacts
b6_dir="$(mktemp -d "$b6_workspace/artifacts/b6-smoke.XXXXXXXX")"
chmod 700 "$b6_dir"
b6_run="$(python3 -c 'import secrets; print(secrets.token_hex(12))')"

b6_manifest() {
  python3 - "$b6_dir" <<'PY'
import json, os, pathlib, sys
root = pathlib.Path(sys.argv[1])
fixture = json.loads((root / "fixtures.json").read_text())
accounts = {label: {key: value for key, value in account.items() if key != "password"}
            for label, account in fixture["accounts"].items()}
descriptor = os.open(root / "cleanup.json", os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
with os.fdopen(descriptor, "w") as stream:
    json.dump({"run_id": fixture["run_id"], "accounts": accounts}, stream)
PY
}

b6_cleanup() {
  b6_result=$?
  trap - EXIT
  if [[ -s "$b6_dir/fixtures.json" ]]; then
    if [[ ! -s "$b6_dir/cleanup.json" ]] && ! b6_manifest; then b6_result=1; fi
    if [[ -s "$b6_dir/cleanup.json" ]]; then
      b6_failed=0
      if ! docker compose exec -T -e HOLOUL_PROJECTS_SMOKE=1 app php scripts/verify-projects-runtime.php cleanup \
        < "$b6_dir/cleanup.json" > artifacts/runtime-projects-cleanup.json 2> "$b6_dir/project-cleanup-error.json"; then b6_failed=1; fi
      # A failure before conversion may still leave an active commercial offer.
      if ! docker compose exec -T -e HOLOUL_PROPOSALS_SMOKE=1 app php scripts/verify-proposals-runtime.php cleanup \
        < "$b6_dir/cleanup.json" > artifacts/runtime-projects-commercial-cleanup.json 2> "$b6_dir/commercial-cleanup-error.json"; then b6_failed=1; fi
      # Always quarantine identities, even if either business cleanup failed.
      if ! docker compose exec -T -e HOLOUL_INTAKE_SMOKE=1 app php scripts/verify-intake-runtime.php cleanup \
        < "$b6_dir/cleanup.json" > artifacts/runtime-projects-account-cleanup.json 2> "$b6_dir/account-cleanup-error.json"; then b6_failed=1; fi
      if [[ "$b6_failed" == 1 ]]; then
        cp "$b6_dir/cleanup.json" artifacts/runtime-projects-cleanup-needed.json
        chmod 600 artifacts/runtime-projects-cleanup-needed.json
        printf '%s\n' '{"event":"verification.projects_cleanup_failed","manifest_retained":true}' >&2
        b6_result=1
      fi
    fi
  fi
  case "$b6_dir" in
    "$b6_workspace"/artifacts/b6-smoke.*) rm -rf -- "$b6_dir" ;;
    *) exit 1 ;;
  esac
  exit "$b6_result"
}
trap b6_cleanup EXIT

docker compose cp nginx:/run/holoul-tls/certificate.pem "$b6_dir/ca.pem" > /dev/null
if ! (umask 077; docker compose exec -T -e HOLOUL_INTAKE_SMOKE=1 app php scripts/verify-intake-runtime.php fixtures "$b6_run" \
  > "$b6_dir/fixtures.json" 2> "$b6_dir/fixture-error.json"); then
  printf '%s\n' '{"event":"verification.projects_fixture_creation_failed"}' >&2
  exit 1
fi
b6_manifest
b6_https_port="$(docker compose port nginx 8443 | sed 's/.*://')"
b6_mail_port="$(docker compose port nginx 8025 | sed 's/.*://')"
python3 scripts/verify-projects.py \
  --origin "https://localhost:$b6_https_port" --ca-cert "$b6_dir/ca.pem" \
  --mailpit-url "http://localhost:$b6_mail_port" --fixtures "$b6_dir/fixtures.json" \
  2>&1 | tee artifacts/runtime-projects-http.json
docker compose exec -T -e HOLOUL_PROJECTS_SMOKE=1 app php scripts/verify-projects-runtime.php evidence \
  < "$b6_dir/cleanup.json" > artifacts/runtime-projects-evidence.json 2> "$b6_dir/evidence-error.json"
