#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
b4_workspace="$(pwd -P)"
mkdir -p artifacts
b4_dir="$(mktemp -d "$b4_workspace/artifacts/b4-smoke.XXXXXXXX")"
chmod 700 "$b4_dir"
b4_run="$(python3 -c 'import secrets; print(secrets.token_hex(12))')"

b4_manifest() {
  python3 - "$b4_dir" <<'PY'
import json, os, pathlib, sys
try:
    root = pathlib.Path(sys.argv[1])
    fixture = json.loads((root / "fixtures.json").read_text())
    accounts = {label: {key: value for key, value in account.items() if key != "password"}
                for label, account in fixture["accounts"].items()}
    descriptor = os.open(root / "cleanup.json", os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, "w") as stream:
        json.dump({"run_id": fixture["run_id"], "accounts": accounts}, stream)
except Exception:
    print('{"event":"verification.documents_cleanup_manifest_failed"}', file=sys.stderr)
    raise SystemExit(1)
PY
}

b4_cleanup() {
  b4_result=$?
  trap - EXIT
  if [[ -s "$b4_dir/fixtures.json" ]]; then
    if [[ ! -s "$b4_dir/cleanup.json" ]] && ! b4_manifest; then
      b4_result=1
    fi
    if [[ -s "$b4_dir/cleanup.json" ]]; then
      b4_cleanup_failed=0
      if ! docker compose exec -T -e HOLOUL_DOCUMENTS_SMOKE=1 -e HOLOUL_INTAKE_SMOKE=1 app \
        php scripts/verify-documents-runtime.php cleanup < "$b4_dir/cleanup.json" \
        > artifacts/runtime-documents-cleanup.json 2> "$b4_dir/document-cleanup-error.json"; then
        b4_cleanup_failed=1
      fi
      # Always quarantine accounts/remove every grant, even if byte cleanup failed.
      if ! docker compose exec -T -e HOLOUL_INTAKE_SMOKE=1 app php scripts/verify-intake-runtime.php cleanup \
        < "$b4_dir/cleanup.json" > artifacts/runtime-documents-account-cleanup.json 2> "$b4_dir/account-cleanup-error.json"; then
        b4_cleanup_failed=1
      fi
      if [[ "$b4_cleanup_failed" == 1 ]]; then
        cp "$b4_dir/cleanup.json" artifacts/runtime-documents-cleanup-needed.json
        chmod 600 artifacts/runtime-documents-cleanup-needed.json
        printf '%s\n' '{"event":"verification.documents_cleanup_failed","manifest_retained":true}' >&2
        b4_result=1
      fi
    fi
  fi
  case "$b4_dir" in
    "$b4_workspace"/artifacts/b4-smoke.*) rm -rf -- "$b4_dir" ;;
    *) exit 1 ;;
  esac
  exit "$b4_result"
}
trap b4_cleanup EXIT

docker compose cp nginx:/run/holoul-tls/certificate.pem "$b4_dir/ca.pem" > /dev/null
if ! (umask 077; docker compose exec -T -e HOLOUL_INTAKE_SMOKE=1 app \
  php scripts/verify-intake-runtime.php fixtures "$b4_run" > "$b4_dir/fixtures.json" 2> "$b4_dir/fixture-error.json"); then
  printf '%s\n' '{"event":"verification.documents_fixture_creation_failed"}' >&2
  exit 1
fi
b4_manifest
b4_https_port="$(docker compose port nginx 8443 | sed 's/.*://')"
b4_mail_port="$(docker compose port nginx 8025 | sed 's/.*://')"
python3 scripts/verify-documents.py \
  --origin "https://localhost:$b4_https_port" --ca-cert "$b4_dir/ca.pem" \
  --mailpit-url "http://localhost:$b4_mail_port" --fixtures "$b4_dir/fixtures.json" \
  2>&1 | tee artifacts/runtime-documents-http.json
if ! docker compose exec -T -e HOLOUL_DOCUMENTS_SMOKE=1 -e HOLOUL_INTAKE_SMOKE=1 app \
  php scripts/verify-documents-runtime.php evidence < "$b4_dir/cleanup.json" \
  > artifacts/runtime-documents-evidence.json 2> "$b4_dir/evidence-error.json"; then
  printf '%s\n' '{"event":"verification.documents_evidence_failed"}' >&2
  exit 1
fi
