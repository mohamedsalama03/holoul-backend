#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
b7_workspace="$(pwd -P)"
mkdir -p artifacts
b7_dir="$(mktemp -d "$b7_workspace/artifacts/b7-smoke.XXXXXXXX")"
chmod 700 "$b7_dir"
b7_run="$(python3 -c 'import secrets; print(secrets.token_hex(12))')"

b7_manifest() {
  python3 - "$b7_dir" <<'PY'
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

b7_cleanup() {
  b7_result=$?
  trap - EXIT
  if [[ -s "$b7_dir/fixtures.json" ]]; then
    if [[ ! -s "$b7_dir/cleanup.json" ]] && ! b7_manifest; then b7_result=1; fi
    if [[ -s "$b7_dir/cleanup.json" ]]; then
      # The approved intake cleanup closes the synthetic request and quarantines
      # all fixture identities. Immutable AI/document/notification history stays.
      if ! docker compose exec -T -e HOLOUL_INTAKE_SMOKE=1 app php scripts/verify-intake-runtime.php cleanup \
        < "$b7_dir/cleanup.json" > artifacts/runtime-assistance-cleanup.json 2> "$b7_dir/cleanup-error.json"; then
        cp "$b7_dir/cleanup.json" artifacts/runtime-assistance-cleanup-needed.json
        chmod 600 artifacts/runtime-assistance-cleanup-needed.json
        printf '%s\n' '{"event":"verification.assistance_cleanup_failed","manifest_retained":true}' >&2
        b7_result=1
      fi
    fi
  fi
  case "$b7_dir" in
    "$b7_workspace"/artifacts/b7-smoke.*) rm -rf -- "$b7_dir" ;;
    *) exit 1 ;;
  esac
  exit "$b7_result"
}
trap b7_cleanup EXIT

docker compose cp nginx:/run/holoul-tls/certificate.pem "$b7_dir/ca.pem" > /dev/null
if ! (umask 077; docker compose exec -T -e HOLOUL_INTAKE_SMOKE=1 app php scripts/verify-intake-runtime.php fixtures "$b7_run" \
  > "$b7_dir/fixtures.json" 2> "$b7_dir/fixture-error.json"); then
  printf '%s\n' '{"event":"verification.assistance_fixture_creation_failed"}' >&2
  exit 1
fi
b7_manifest
b7_https_port="$(docker compose port nginx 8443 | sed 's/.*://')"
b7_mail_port="$(docker compose port nginx 8025 | sed 's/.*://')"
python3 scripts/verify-assistance.py \
  --origin "https://localhost:$b7_https_port" --ca-cert "$b7_dir/ca.pem" \
  --mailpit-url "http://localhost:$b7_mail_port" --fixtures "$b7_dir/fixtures.json" \
  2>&1 | tee artifacts/runtime-assistance-http.json
docker compose exec -T -e HOLOUL_ASSISTANCE_SMOKE=1 app php scripts/verify-assistance-runtime.php evidence \
  < "$b7_dir/cleanup.json" > artifacts/runtime-assistance-evidence.json 2> "$b7_dir/evidence-error.json"
