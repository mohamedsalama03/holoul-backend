#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
b5_workspace="$(pwd -P)"
mkdir -p artifacts
b5_dir="$(mktemp -d "$b5_workspace/artifacts/b5-smoke.XXXXXXXX")"
chmod 700 "$b5_dir"
b5_run="$(python3 -c 'import secrets; print(secrets.token_hex(12))')"

b5_manifest() {
  python3 - "$b5_dir" <<'PY'
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

b5_cleanup() {
  b5_result=$?
  trap - EXIT
  if [[ -s "$b5_dir/fixtures.json" ]]; then
    if [[ ! -s "$b5_dir/cleanup.json" ]] && ! b5_manifest; then b5_result=1; fi
    if [[ -s "$b5_dir/cleanup.json" ]]; then
      b5_failed=0
      if ! docker compose exec -T -e HOLOUL_PROPOSALS_SMOKE=1 app php scripts/verify-proposals-runtime.php cleanup \
        < "$b5_dir/cleanup.json" > artifacts/runtime-proposals-cleanup.json 2> "$b5_dir/commercial-cleanup-error.json"; then b5_failed=1; fi
      # Quarantine identities even if a commercial cleanup failed; keep all history.
      if ! docker compose exec -T -e HOLOUL_INTAKE_SMOKE=1 app php scripts/verify-intake-runtime.php cleanup \
        < "$b5_dir/cleanup.json" > artifacts/runtime-proposals-account-cleanup.json 2> "$b5_dir/account-cleanup-error.json"; then b5_failed=1; fi
      if [[ "$b5_failed" == 1 ]]; then
        cp "$b5_dir/cleanup.json" artifacts/runtime-proposals-cleanup-needed.json
        chmod 600 artifacts/runtime-proposals-cleanup-needed.json
        printf '%s\n' '{"event":"verification.proposals_cleanup_failed","manifest_retained":true}' >&2
        b5_result=1
      fi
    fi
  fi
  case "$b5_dir" in
    "$b5_workspace"/artifacts/b5-smoke.*) rm -rf -- "$b5_dir" ;;
    *) exit 1 ;;
  esac
  exit "$b5_result"
}
trap b5_cleanup EXIT

docker compose cp nginx:/run/holoul-tls/certificate.pem "$b5_dir/ca.pem" > /dev/null
if ! (umask 077; docker compose exec -T -e HOLOUL_INTAKE_SMOKE=1 app php scripts/verify-intake-runtime.php fixtures "$b5_run" \
  > "$b5_dir/fixtures.json" 2> "$b5_dir/fixture-error.json"); then
  printf '%s\n' '{"event":"verification.proposals_fixture_creation_failed"}' >&2
  exit 1
fi
b5_manifest
b5_https_port="$(docker compose port nginx 8443 | sed 's/.*://')"
b5_mail_port="$(docker compose port nginx 8025 | sed 's/.*://')"
python3 scripts/verify-proposals.py \
  --origin "https://localhost:$b5_https_port" --ca-cert "$b5_dir/ca.pem" \
  --mailpit-url "http://localhost:$b5_mail_port" --fixtures "$b5_dir/fixtures.json" \
  2>&1 | tee artifacts/runtime-proposals-http.json
docker compose exec -T -e HOLOUL_PROPOSALS_SMOKE=1 app php scripts/verify-proposals-runtime.php evidence \
  < "$b5_dir/cleanup.json" > artifacts/runtime-proposals-evidence.json 2> "$b5_dir/evidence-error.json"
