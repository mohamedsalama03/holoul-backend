#!/usr/bin/env python3
"""Export a new offline reference directory without modifying older packages or frontends."""
import argparse, hashlib, json, shutil, subprocess, zipfile
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(); parser.add_argument("--output", type=Path, required=True); args = parser.parse_args()
output = args.output.resolve()
assert not output.exists(), "Use a new output directory; preserve previous handoffs"
raw = (ROOT / "docs/openapi.json").read_bytes(); spec = json.loads(raw)
report = json.loads((ROOT / "docs/intake-display/evidence/contract-compatibility.json").read_text())
assert report["passed"] and hashlib.sha256(raw).hexdigest() == report["new_sha256"]
assert spec["info"]["version"] == "1.5.0-intake-display-candidate"
assert len(spec["components"]["schemas"]) == 260
output.mkdir(parents=True)
shutil.copytree(ROOT / "docs/swagger/assets", output / "assets")
html = (ROOT / "docs/swagger/index.html").read_text().replace(
    "Includes G1 intake, staff onboarding and customer registration sorting.",
    "Includes G1 intake, staff onboarding, customer sorting, PublicPortfolio, Contact and opt-in request display fields.")
html = html.replace("Candidate baseline: <code>e3957df723ea01a6005feaec90d8130a26c93b62</code>",
    "Parent baseline: <code>3bc85401e96a1f8b843d1db0f13a4ae9b86572ee</code><br>Implementation: <code>1f3cf6eb6a2b532cc882769f59ee80096b49c926</code>")
html = html.replace('<span id="footer-count">182</span>', '<span id="footer-count">202</span>')
(output / "index.html").write_text(html)
metadata = {"version": spec["info"]["version"], "openapi": spec["openapi"], "sha256": report["new_sha256"],
            "operations": 202, "schemas": 260, "baseline": report["baseline_commit"],
            "implementation": "1f3cf6eb6a2b532cc882769f59ee80096b49c926", "swagger_ui": "5.32.11", "mode": "read-only-reference"}
(output / "openapi.json").write_bytes(raw)
yaml = subprocess.check_output(["docker", "run", "--rm", "--network", "none", "-v", str(ROOT) + ":/work:ro", "holoul-contracts:b8-p3", "-c",
    'import json,yaml; print(yaml.safe_dump(json.load(open("/work/docs/openapi.json")),sort_keys=False,allow_unicode=True,width=100),end="")'])
(output / "openapi.yaml").write_bytes(yaml)
(output / "assets/contract.js").write_text("// Generated offline reference; no credentials.\nwindow.HOLOUL_REFERENCE = " + json.dumps(metadata)
    + ";\nwindow.HOLOUL_CONTRACT = " + json.dumps(spec, ensure_ascii=True, separators=(",", ":")) + ";\n")
(output / "contract-metadata.json").write_text(json.dumps(metadata, indent=2) + "\n")
shutil.copyfile(ROOT / "docs/swagger/THIRD-PARTY-NOTICES.md", output / "THIRD-PARTY-NOTICES.md")
for name in ("HANDOFF.md", "CHANGELOG.md", "VERIFICATION.md", "response-examples.json"):
    shutil.copyfile(ROOT / "docs/intake-display" / name, output / name)
(output / "README.md").write_text("# HOLOUL API — F4 handoff\n\nOpen index.html as a local file. Assets and the contract are embedded locally; no server on port 8876 is required.\n\nRead HANDOFF.md for the per-item reply, the five view=dashboard operations and the compatibility policy. Verification evidence is in VERIFICATION.md. Adopt the new contract in a separate frontend commit; the default API remains unchanged.\n\nThis package is a local integration candidate, not production certification.\n")
proof = output / "evidence"; proof.mkdir()
for name in ("contract-compatibility.json", "contract-final.json", "contract-runtime.json", "fresh-migrations.txt", "upgrade-noop.txt", "local-runtime-config.json", "phpunit-summary.json", "full-phpunit.xml", "runtime-image-verification.json",
             "local-runtime-final.json", "runtime-https.json", "frontend-final.json", "frontend-preserved.json",
             "contract-negative-inherited.txt", "contract-negative-display.txt", "phpstan-final.txt", "pint-final.txt",
             "composer-validate.txt", "composer-audit.txt", "inherited-security-tests-preserved.json", "preserved-boundaries.json"):
    shutil.copyfile(ROOT / "docs/intake-display/evidence" / name, proof / name)
files = sorted(f for f in output.rglob("*") if f.is_file())
assert all(f.stat().st_size for f in files)
(output / "SHA256SUMS").write_text("".join(hashlib.sha256(f.read_bytes()).hexdigest() + "  " + f.relative_to(output).as_posix() + "\n" for f in files))
archive = output.with_name(output.name + ".zip")
assert not archive.exists(), "Preserve previous archives"
with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as package:
    for f in sorted(output.rglob("*")):
        if f.is_file(): package.write(f, output.name + "/" + f.relative_to(output).as_posix())
with zipfile.ZipFile(archive) as package: assert package.testzip() is None
print(json.dumps({**metadata, "directory": str(output), "archive": str(archive), "archive_sha256": hashlib.sha256(archive.read_bytes()).hexdigest()}))
