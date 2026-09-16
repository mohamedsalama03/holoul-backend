#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
b2_workspace="$(pwd -P)"
mkdir -p artifacts
b2_dir="$(mktemp -d "$b2_workspace/artifacts/b2-smoke.XXXXXXXX")"
chmod 700 "$b2_dir"

b2_cleanup() {
  b2_result=$?
  trap - EXIT
  python3 - "$b2_dir" <<'PY'
import json, os, pathlib, sys
root = pathlib.Path(sys.argv[1])
users = []
manifest = root / "manifest.json"
staff = root / "staff.json"
if manifest.exists():
    users = json.loads(manifest.read_text())["users"]
if staff.exists() and staff.stat().st_size:
    record = json.loads(staff.read_text())
    if not any(user.get("email") == record["email"] for user in users):
        users.append({"email": record["email"], "user_id": record["id"]})
path = root / "cleanup.json"
path.write_text(json.dumps({"users": users}))
os.chmod(path, 0o600)
PY
  if ! docker compose exec -T app php scripts/verify-identity-runtime.php cleanup < "$b2_dir/cleanup.json" > artifacts/runtime-identity-cleanup.json; then
    cp "$b2_dir/cleanup.json" artifacts/runtime-identity-cleanup-needed.json
    b2_result=1
  fi
  # Only delete the freshly allocated directory inside this workspace.
  case "$b2_dir" in
    "$b2_workspace"/artifacts/b2-smoke.*) rm -rf -- "$b2_dir" ;;
    *) exit 1 ;;
  esac
  exit "$b2_result"
}
trap b2_cleanup EXIT

docker compose cp nginx:/run/holoul-tls/certificate.pem "$b2_dir/ca.pem"
(umask 077; docker compose exec -T app php scripts/verify-identity-runtime.php staff-fixture > "$b2_dir/staff.json")
b2_https_port="$(docker compose port nginx 8443 | sed 's/.*://')"
b2_mail_port="$(docker compose port nginx 8025 | sed 's/.*://')"
python3 scripts/verify-identity-http.py \
  --origin "https://localhost:$b2_https_port" --ca-cert "$b2_dir/ca.pem" \
  --mailpit-url "http://localhost:$b2_mail_port" \
  --cleanup-manifest "$b2_dir/manifest.json" --staff-fixture "$b2_dir/staff.json" \
  | tee artifacts/runtime-identity-http.json
