#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
b3_workspace="$(pwd -P)"
mkdir -p artifacts
b3_dir="$(mktemp -d "$b3_workspace/artifacts/b3-smoke.XXXXXXXX")"
chmod 700 "$b3_dir"
b3_run="$(python3 -c 'import secrets; print(secrets.token_hex(12))')"

b3_cleanup() {
  b3_result=$?
  trap - EXIT
  if [[ -s "$b3_dir/fixtures.json" ]]; then
    if ! python3 - "$b3_dir" <<'PY'
import json, os, pathlib, sys
try:
    root = pathlib.Path(sys.argv[1])
    fixture = json.loads((root / "fixtures.json").read_text())
    accounts = {label: {key: value for key, value in account.items() if key != "password"}
                for label, account in fixture["accounts"].items()}
    path = root / "cleanup.json"
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, "w") as stream:
        json.dump({"run_id": fixture["run_id"], "accounts": accounts}, stream)
except Exception:
    print('{"event":"verification.intake_cleanup_manifest_failed"}', file=sys.stderr)
    raise SystemExit(1)
PY
    then
      b3_result=1
    elif ! docker compose exec -T -e HOLOUL_INTAKE_SMOKE=1 app php scripts/verify-intake-runtime.php cleanup \
      < "$b3_dir/cleanup.json" > artifacts/runtime-intake-cleanup.json 2> "$b3_dir/cleanup-error.json"; then
      cp "$b3_dir/cleanup.json" artifacts/runtime-intake-cleanup-needed.json
      chmod 600 artifacts/runtime-intake-cleanup-needed.json
      printf '%s\n' '{"event":"verification.intake_cleanup_failed","manifest_retained":true}' >&2
      b3_result=1
    fi
  fi
  # Delete only this freshly allocated, checked workspace directory. Immutable
  # database rows are retained; the manifest retained on failure has no secrets.
  case "$b3_dir" in
    "$b3_workspace"/artifacts/b3-smoke.*) rm -rf -- "$b3_dir" ;;
    *) exit 1 ;;
  esac
  exit "$b3_result"
}
trap b3_cleanup EXIT

docker compose cp nginx:/run/holoul-tls/certificate.pem "$b3_dir/ca.pem" > /dev/null
if ! (umask 077; docker compose exec -T -e HOLOUL_INTAKE_SMOKE=1 app php scripts/verify-intake-runtime.php fixtures "$b3_run" \
  > "$b3_dir/fixtures.json" 2> "$b3_dir/fixture-error.json"); then
  printf '%s\n' '{"event":"verification.intake_fixture_creation_failed"}' >&2
  exit 1
fi
b3_https_port="$(docker compose port nginx 8443 | sed 's/.*://')"
b3_mail_port="$(docker compose port nginx 8025 | sed 's/.*://')"
python3 scripts/verify-intake-http.py \
  --origin "https://localhost:$b3_https_port" --ca-cert "$b3_dir/ca.pem" \
  --mailpit-url "http://localhost:$b3_mail_port" --fixtures "$b3_dir/fixtures.json" \
  2>&1 | tee artifacts/runtime-intake-http.json
