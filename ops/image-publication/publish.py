#!/usr/bin/env python3
"""Build, gate, then copy identical OCI bytes. Never deploy or handle production secrets."""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import tarfile
import urllib.error
import urllib.request

SOURCE = "8b1ffea5c75dc4adb68f795a73d1c6c513cbbd8c"
REPOSITORY = "mohamedsalama03/holoul-backend"
CONTRACT = "3c93a09f08ce199cc1c2867ad846a1ca554fd03b6d934070178c5101646df410"
IMAGES = {
    "backend": ("Dockerfile", "runtime", "holoul", "1000:1000"),
    "postgres": ("docker/postgres/Dockerfile", None, None, "70:70"),
    "redis": ("docker/redis/Dockerfile", None, None, "999:999"),
    "storage": ("docker/documents/storage/Dockerfile", None, "1000:1000", "1000:1000"),
    "portfolio": ("docker/portfolio/Dockerfile", None, "1000:1000", "1000:1000"),
    "nginx": ("docker/nginx/Dockerfile", None, "101:101", "101:101"),
}
TRIVY = "aquasec/trivy:0.74.0@sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969"
SKOPEO = "quay.io/skopeo/stable@sha256:966b7d73acc4478906280e4967cafd93f4a85273e9a97b41b2ea8f9bd55292a5"
SBOM = "docker/buildkit-syft-scanner@sha256:ae4f3b554449e7e25548e7d8ccc029d17357348e30c6e3df01b92bc93654d6a9"
DATABASES = "public.ecr.aws/aquasecurity/trivy-db:2,mirror.gcr.io/aquasec/trivy-db:2,ghcr.io/aquasecurity/trivy-db:2"
DIGEST = re.compile(r"sha256:[a-f0-9]{64}\Z")
PACKAGING = Path(__file__).with_name("packaging.json")


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(path):
    with open(path, "rb") as stream:
        return "sha256:" + hashlib.file_digest(stream, "sha256").hexdigest()


def write_json(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, sort_keys=True) + "\n")


def run(argv, **kwargs):
    return subprocess.run(argv, check=True, **kwargs)


def output(argv):
    return subprocess.check_output(argv, text=True).strip()


def context(source):
    require(os.environ.get("SOURCE_SHA") == SOURCE, "source_sha must equal the frozen full SHA")
    workflow = os.environ.get("WORKFLOW_SHA", "")
    require(re.fullmatch(r"[a-f0-9]{40}", workflow), "full workflow commit SHA required")
    require(os.environ.get("GITHUB_REPOSITORY") == REPOSITORY, "wrong repository")
    require(os.environ.get("GITHUB_EVENT_NAME") == "workflow_dispatch", "manual dispatch only")
    require(os.environ.get("GITHUB_REF") == "refs/heads/ops/production-image-publication", "operations branch required")
    require(os.environ.get("RUNNER_ENVIRONMENT") == "github-hosted", "GitHub-hosted runner required")
    require(output(["git", "-C", str(source), "rev-parse", "HEAD"]) == SOURCE, "source checkout mismatch")
    require(not output(["git", "-C", str(source), "status", "--porcelain"]), "source tree is dirty")
    tooling = Path(__file__).resolve().parents[2]
    require(output(["git", "-C", str(tooling), "rev-parse", "HEAD"]) == workflow, "workflow checkout mismatch")
    require(digest(source / "docs/openapi.json") == "sha256:" + CONTRACT, "contract mismatch")
    for key in ("GITHUB_RUN_ID", "GITHUB_RUN_ATTEMPT"):
        require(re.fullmatch(r"[1-9][0-9]*", os.environ.get(key, "")), "missing run identity")
    work = (Path(os.environ["RUNNER_TEMP"]) / "publication").resolve()
    require(source not in work.parents and work != source, "evidence must be outside build context")
    work.mkdir(mode=0o700, parents=True, exist_ok=True)
    return work


def container(tool, work, *args, stdin=False):
    return ["docker", "run", "--rm", *( ["-i"] if stdin else []),
            "--user", f"{os.getuid()}:{os.getgid()}",
            "--volume", f"{work}:/work", "--workdir", "/work", tool, *args]


def inspect_layout(layout, image):
    """Verify every referenced blob; select one amd64 image plus bound attestations."""
    seen = set()

    def blob(desc):
        value = desc.get("digest", "")
        require(DIGEST.fullmatch(value), "invalid blob digest")
        path = layout / "blobs/sha256" / value.split(":")[1]
        require(path.stat().st_size == desc["size"], "blob size mismatch")
        if value not in seen:
            require(digest(path) == value, "blob digest mismatch")
            seen.add(value)
        return path

    index = json.loads((layout / "index.json").read_text())
    require(len(index["manifests"]) == 1, "one OCI output identity required")
    top = index["manifests"][0]
    manifests = []

    def visit(desc):
        body = json.loads(blob(desc).read_text())
        if "manifests" in body:
            for child in body["manifests"]:
                visit(child)
        else:
            config = json.loads(blob(body["config"]).read_text())
            for layer in body["layers"]:
                blob(layer)
            manifests.append((desc, body, config))

    visit(top)
    images = [entry for entry in manifests if entry[0].get("annotations", {}).get(
        "vnd.docker.reference.type") != "attestation-manifest"]
    require(len(images) == 1, "exactly one executable platform required")
    desc, manifest, config = images[0]
    require((config.get("os"), config.get("architecture")) == ("linux", "amd64"), "wrong platform")
    settings = config["config"]
    labels = settings.get("Labels", {})
    require(labels.get("org.opencontainers.image.revision") == SOURCE, "wrong OCI source revision")
    require(labels.get("org.opencontainers.image.source") == "https://github.com/" + REPOSITORY,
            "wrong OCI source repository")
    require(labels.get("org.opencontainers.image.title") == "holoul-" + image, "wrong OCI title")
    require(labels.get("org.opencontainers.image.created"), "missing build time")
    require(labels.get("ly.com.holoul.workflow.revision") == os.environ["WORKFLOW_SHA"], "wrong workflow label")
    require(labels.get("ly.com.holoul.packaging.revision") == os.environ["WORKFLOW_SHA"], "wrong packaging label")
    expected_user = IMAGES[image][2]
    require(expected_user is None or settings.get("User") == expected_user, "runtime user changed")
    predicates = set()
    for att_desc, body, att_config in manifests:
        if att_desc == desc:
            continue
        platform = att_desc.get("platform", {})
        require((platform.get("os"), platform.get("architecture")) == ("unknown", "unknown"),
                "unexpected attestation platform")
        annotations = att_desc.get("annotations", {})
        require(annotations.get("vnd.docker.reference.type") == "attestation-manifest" and
                annotations.get("vnd.docker.reference.digest") == desc["digest"], "unbound attestation")
        if body.get("artifactType"):
            require(body["artifactType"] == "application/vnd.docker.attestation.manifest.v1+json" and
                    body.get("subject", {}).get("digest") == desc["digest"], "wrong OCI artifact subject")
        for layer in body["layers"]:
            statement = json.loads(blob(layer).read_text())
            require(any(s.get("digest", {}).get("sha256") == desc["digest"][7:]
                        for s in statement.get("subject", [])), "wrong attestation subject")
            predicates.add(statement["predicateType"])
    require("https://spdx.dev/Document" in predicates, "missing SPDX SBOM attestation")
    require(any(p.startswith("https://slsa.dev/provenance/") for p in predicates), "missing provenance")
    size = sum((layout / "blobs/sha256" / value[7:]).stat().st_size for value in seen)
    return {
        "manifest_digest": top["digest"], "runtime_manifest_digest": desc["digest"],
        "config_digest": manifest["config"]["digest"], "platform": "linux/amd64",
        "image_user": settings.get("User", "") or "root (image default)",
        "production_compose_user": IMAGES[image][3],
        "exposed_ports": sorted(settings.get("ExposedPorts", {})),
        "entrypoint": settings.get("Entrypoint"), "cmd": settings.get("Cmd"),
        "labels": labels, "registry_blob_bytes_including_attestations": size,
        "runtime_compressed_layer_bytes": sum(layer["size"] for layer in manifest["layers"]),
        "attestation_predicates": sorted(predicates),
    }, manifest


def navigation_tag():
    return "sha-" + SOURCE[:12] + "-run-" + os.environ["GITHUB_RUN_ID"] + "-" + os.environ["GITHUB_RUN_ATTEMPT"]


def packaging_plan():
    plan = json.loads(PACKAGING.read_text())
    require(plan["application_source_sha"] == SOURCE, "packaging source mismatch")
    return plan


def effective_dockerfile(source, work, image):
    original = source / IMAGES[image][0]
    plan = packaging_plan().get(image)
    if not plan:
        return original
    require(digest(original) == plan["source_dockerfile_sha256"], "packaging base Dockerfile changed")
    allowed = {"redis": {"libcrypto3", "libssl3", "setpriv"},
               "postgres": {"libcrypto3", "libssl3", "libuuid", "gosu"}, "nginx": {"libexpat", "pcre2"}}
    content = original.read_text()
    for old, new in plan["replacements"].items():
        pattern = r"([a-z0-9+.-]+)=(\d+(?:\.\d+){1,2}-r\d+)"
        before, after = re.fullmatch(pattern, old), re.fullmatch(pattern, new)
        require(before and after and before[1] == after[1] and before[1] in allowed[image],
                "only reviewed exact package revision replacements are permitted")
        require(before[2].split(".")[:2] == after[2].split(".")[:2], "package series changed")
        require(content.count(old) == 1, "package replacement is not unique")
        content = content.replace(old, new)
    destination = work / "packaging.Dockerfile"
    destination.write_text(content)
    return destination


def build(source, work, image):
    require(not os.environ.get("GH_TOKEN"), "registry token must not be present during build")
    dockerfile, target, _, _ = IMAGES[image]
    created = dt.datetime.now(dt.timezone.utc).isoformat().replace("+00:00", "Z")
    labels = {
        "org.opencontainers.image.source": "https://github.com/" + REPOSITORY,
        "org.opencontainers.image.revision": SOURCE,
        "org.opencontainers.image.created": created,
        "org.opencontainers.image.title": "holoul-" + image,
        "ly.com.holoul.workflow.revision": os.environ["WORKFLOW_SHA"],
        "ly.com.holoul.packaging.revision": os.environ["WORKFLOW_SHA"],
    }
    argv = ["docker", "buildx", "build", "--platform", "linux/amd64", "--pull",
            "--tag", "ghcr.io/mohamedsalama03/holoul-" + image + ":" + navigation_tag(),
            "--file", str(effective_dockerfile(source, work, image)), "--provenance=mode=min", "--sbom=generator=" + SBOM,
            "--output", f"type=oci,dest={work / 'layout'},tar=false,compression=gzip,force-compression=true",
            "--metadata-file", str(work / "build-metadata.json")]
    if target:
        argv += ["--target", target]
    for name, value in labels.items():
        argv += ["--label", name + "=" + value]
    run(argv + [str(source)])
    inspected, _ = inspect_layout(work / "layout", image)
    require(json.loads((work / "build-metadata.json").read_text())["containerimage.digest"] ==
            inspected["manifest_digest"], "BuildKit output identity mismatch")


def extract_regular_files(archive, destination):
    """Scan layer contents including deleted/base files; never materialize links/devices."""
    size = 0
    with tarfile.open(archive, "r:*") as stream:
        for member in stream:
            if not member.isfile():
                continue
            path = PurePosixPath(member.name)
            require(not path.is_absolute() and ".." not in path.parts, "unsafe layer path")
            target = destination.joinpath(*path.parts)
            target.parent.mkdir(parents=True, exist_ok=True)
            with stream.extractfile(member) as src, target.open("wb") as dst:
                shutil.copyfileobj(src, dst)
            size += member.size
    return size


def scan_report(raw, secret):
    require(raw.get("SchemaVersion") == 2, "unknown scanner report schema")
    findings = []
    for result in raw.get("Results") or []:
        for entry in result.get("Secrets" if secret else "Vulnerabilities") or []:
            if secret:
                # No matched content, code snippets, or raw secret paths in artifacts/logs.
                findings.append({"rule": entry.get("RuleID"), "severity": entry.get("Severity"),
                                 "line": entry.get("StartLine"),
                                 "file_sha256": hashlib.sha256(result["Target"].encode()).hexdigest()})
            else:
                findings.append({key: entry.get(key) for key in
                                 ("VulnerabilityID", "PkgName", "InstalledVersion", "FixedVersion", "Severity")})
    return findings


def scan(source, work, image):
    require(not os.environ.get("GH_TOKEN"), "registry token must not be present during scan")
    inspected, manifest = inspect_layout(work / "layout", image)
    evidence = work.parent / "evidence" / (image + ".json")
    record = {"source_repository": REPOSITORY, "source_sha": SOURCE,
              "workflow_sha": os.environ["WORKFLOW_SHA"], "image": "ghcr.io/mohamedsalama03/holoul-" + image,
              "run_id": os.environ["GITHUB_RUN_ID"], "run_attempt": os.environ["GITHUB_RUN_ATTEMPT"],
              "dockerfile": IMAGES[image][0], "target": IMAGES[image][1],
              "dockerfile_sha256": digest(source / IMAGES[image][0]), "published_verified": False,
              "packaging_sha": os.environ["WORKFLOW_SHA"],
              "packaging_manifest_sha256": digest(PACKAGING),
              "effective_dockerfile_sha256": digest(effective_dockerfile(source, work, image)),
              "package_changes": packaging_plan().get(image, {}).get("replacements", {}),
              "scan": {"passed": False, "scanner": TRIVY}, "inspection": inspected}
    # Inspection is kept private until the secret checks pass.
    checks = [
        ("vulnerabilities", ["image", "--input", "/work/layout", "--platform", "linux/amd64",
                             "--scanners", "vuln", "--severity", "HIGH,CRITICAL", "--db-repository", DATABASES]),
        ("config-secrets", ["image", "--input", "/work/layout", "--platform", "linux/amd64",
                            "--scanners", "secret", "--image-config-scanners", "secret"]),
    ]
    layer_root = work / "all-layer-files"
    layer_root.mkdir()
    uncompressed = 0
    for i, layer in enumerate(manifest["layers"]):
        uncompressed += extract_regular_files(work / "layout/blobs/sha256" / layer["digest"][7:],
                                              layer_root / str(i))
    inspected["all_layer_regular_file_bytes"] = uncompressed
    # Include OCI config/history and provenance metadata in the full secret scan.
    for blob in (work / "layout/blobs/sha256").iterdir():
        with blob.open("rb") as f:
            prefix = f.read(1)
        if prefix == b"{":
            shutil.copyfile(blob, layer_root / (blob.name + ".json"))
    (work / "secret-config.yaml").write_text("skip-patterns: []\ndisable-allow-rules: [markdown, usr-dirs]\n")
    checks.append(("all-layer-secrets", ["fs", "--scanners", "secret", "--secret-config",
                                       "/work/secret-config.yaml", "/work/all-layer-files"]))
    results = {}
    passed = True
    for name, options in checks:
        args = ["--cache-dir", "/work/trivy-cache", "--timeout", "30m", *options,
                "--ignorefile", "/dev/null", "--exit-code", "1", "--no-progress", "--format", "json",
                "--output", "/work/" + name + ".json"]
        with (work / (name + ".log")).open("wb") as log:
            status = subprocess.run(container(TRIVY, work, *args), stdout=log, stderr=log).returncode
        report_path = work / (name + ".json")
        if not report_path.exists():
            results[name] = {"passed": False, "scanner_exit": status, "error": "scanner produced no report"}
            passed = False
            continue
        report = json.loads(report_path.read_text())
        findings = scan_report(report, name != "vulnerabilities")
        if name == "vulnerabilities":
            require(report.get("Metadata", {}).get("OS", {}).get("Family") == "alpine", "OS scan missing")
            require(any(r.get("Type") == "alpine" for r in report.get("Results", [])), "Alpine results missing")
        okay = status == 0 and not findings
        results[name] = {"passed": okay, "scanner_exit": status, "findings": findings}
        passed = passed and okay
    record["scan"].update({"passed": passed, "checks": results,
                           "scanned_at": dt.datetime.now(dt.timezone.utc).isoformat()})
    metadata = work / "trivy-cache/db/metadata.json"
    if metadata.exists():
        record["scan"]["database_metadata"] = json.loads(metadata.read_text())
    if not passed:
        record.pop("inspection")
    write_json(evidence, record)
    require(passed, "image security gate failed; see sanitized evidence (raw reports never uploaded)")
    # The frozen Dockerfile's RUN assertions executed in the selected runtime stage.
    record["backend_allowlist_build_assertions"] = "passed in runtime Dockerfile" if image == "backend" else "not applicable"
    write_json(evidence, record)
    # Retain the immutable OCI layout; discard only this check's expanded copies.
    shutil.rmtree(layer_root)


def package_visibility(image, allow_missing=False):
    url = "https://api.github.com/users/mohamedsalama03/packages/container/holoul-" + image
    request = urllib.request.Request(url, headers={"Authorization": "Bearer " + os.environ["GH_TOKEN"],
        "Accept": "application/vnd.github+json", "X-GitHub-Api-Version": "2022-11-28"})
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            package = json.load(response)
    except urllib.error.HTTPError as error:
        if error.code == 404 and allow_missing:
            return "not visible before first publication"
        raise RuntimeError(f"package metadata inaccessible (HTTP {error.code}); visibility not verified") from None
    require(package["visibility"] == "private", "package is not private; stop for review")
    require(package.get("repository", {}).get("full_name") == REPOSITORY, "package is not linked to this repository")
    return "private"


def publish(source, work, image):
    evidence = work.parent / "evidence" / (image + ".json")
    record = json.loads(evidence.read_text())
    require(record["scan"]["passed"], "security gate missing")
    inspected, _ = inspect_layout(work / "layout", image)
    require(inspected["manifest_digest"] == record["inspection"]["manifest_digest"], "output changed after scan")
    tag = navigation_tag()
    ref = record["image"] + "@" + inspected["manifest_digest"]
    auth = work / "registry-auth.json"
    try:
        token = os.environ["GH_TOKEN"]
        require(bool(token), "GITHUB_TOKEN required")
        run(container(SKOPEO, work, "login", "--authfile", "/work/registry-auth.json", "--username",
                      os.environ["GITHUB_ACTOR"], "--password-stdin", "ghcr.io", stdin=True),
            input=token + "\n", text=True, stdout=subprocess.DEVNULL)
        auth.chmod(0o600)
        run(container(SKOPEO, work, "copy", "--all", "--preserve-digests", "--authfile", "/work/registry-auth.json",
                      "--digestfile", "/work/pushed-digest.txt", "oci:/work/layout", "docker://" + record["image"] + ":" + tag))
        require((work / "pushed-digest.txt").read_text().strip() == inspected["manifest_digest"], "registry digest changed")
        # Pull by digest, including attestations; validate every blob again, not just a tag lookup.
        run(container(SKOPEO, work, "copy", "--all", "--preserve-digests", "--authfile", "/work/registry-auth.json",
                      "docker://" + ref, "oci:/work/registry-verification"))
        remote, _ = inspect_layout(work / "registry-verification", image)
        require(remote["manifest_digest"] == inspected["manifest_digest"], "remote identity mismatch")
        visibility = package_visibility(image)
        record.update({"published_verified": True, "immutable_reference": ref,
                       "tag": tag, "ghcr_visibility": visibility,
                       "published_at": dt.datetime.now(dt.timezone.utc).isoformat(),
                       "registry_pull_verified": True})
        write_json(evidence, record)
    finally:
        auth.unlink(missing_ok=True)


def release_gate(work):
    """No credential use or registry write until every local image gate passes."""
    records = []
    for image in IMAGES:
        record = json.loads((work / "evidence" / (image + ".json")).read_text())
        require(record["source_sha"] == SOURCE and record["workflow_sha"] == os.environ["WORKFLOW_SHA"] and
                record["packaging_sha"] == os.environ["WORKFLOW_SHA"] and
                record["packaging_manifest_sha256"] == digest(PACKAGING), "mixed release packaging")
        require(record["image"] == "ghcr.io/mohamedsalama03/holoul-" + image, "wrong release image name")
        require(record["run_id"] == os.environ["GITHUB_RUN_ID"] and
                record["run_attempt"] == os.environ["GITHUB_RUN_ATTEMPT"], "mixed release run")
        require(record["scan"]["passed"] is True and record.get("runtime_checks", {}).get("passed") is True,
                "all six security and runtime gates must pass before any publication")
        inspected, _ = inspect_layout(work / image / "layout", image)
        require(inspected["manifest_digest"] == record["inspection"]["manifest_digest"], "image changed after gates")
        records.append(record)
    return records


def prepare_all(source, work):
    import runtime_checks
    require(not os.environ.get("GH_TOKEN"), "registry token must not be present during preparation")
    failures = []
    for image in ("redis", "postgres", "nginx", "backend", "portfolio", "storage"):
        image_work = work / image
        image_work.mkdir(mode=0o700)
        stage = "build"
        print(f"::group::{image}: build, security and runtime gates", flush=True)
        try:
            build(source, image_work, image)
            stage = "security scan"
            scan(source, image_work, image)
            stage = "runtime checks"
            inspected, _ = inspect_layout(image_work / "layout", image)
            checks = runtime_checks.check(image_work, image, inspected,
                                          packaging_plan().get(image, {}).get("expected_packages", {}))
            evidence = work / "evidence" / (image + ".json")
            record = json.loads(evidence.read_text())
            record["runtime_checks"] = checks
            write_json(evidence, record)
        except (RuntimeError, OSError, subprocess.SubprocessError, ValueError) as error:
            failures.append(image)
            # Never upload arbitrary exception text or raw scanner logs.
            write_json(work / "evidence" / (image + "-failure.json"),
                       {"image": image, "stage": stage, "error_type": type(error).__name__,
                        "source_sha": SOURCE, "packaging_sha": os.environ["WORKFLOW_SHA"], "passed": False})
            print(f"::error::{image}: {stage} failed ({type(error).__name__}); publication is blocked", flush=True)
        finally:
            print("::endgroup::", flush=True)
    require(not failures, "release gates failed: " + ", ".join(failures))
    records = release_gate(work)
    write_json(work / "evidence/all-six-gates.json", {"passed": True, "source_sha": SOURCE,
               "packaging_sha": os.environ["WORKFLOW_SHA"], "images": [r["image"] for r in records]})


def publish_all(source, work):
    release_gate(work)
    # Validate access/visibility for every package before the first registry write.
    for image in IMAGES:
        package_visibility(image, allow_missing=True)
    for image in IMAGES:
        publish(source, work / image, image)


def inventory_records(folder):
    records = []
    for image in IMAGES:
        record = json.loads((folder / (image + ".json")).read_text())
        require(record["source_sha"] == SOURCE and record["workflow_sha"] == os.environ["WORKFLOW_SHA"], "mixed source/workflow")
        require(record["run_id"] == os.environ["GITHUB_RUN_ID"] and
                record["run_attempt"] == os.environ["GITHUB_RUN_ATTEMPT"], "mixed run evidence")
        name = "ghcr.io/mohamedsalama03/holoul-" + image
        require(record["image"] == name and record.get("published_verified") is True and
                record.get("registry_pull_verified") is True and record["scan"]["passed"] is True and
                record.get("ghcr_visibility") == "private", "unverified image")
        manifest = record["inspection"]["manifest_digest"]
        require(DIGEST.fullmatch(manifest) and record["immutable_reference"] == name + "@" + manifest, "invalid registry identity")
        require(record["inspection"]["platform"] == "linux/amd64", "wrong inventory platform")
        records.append(record)
    return records


def inventory(source, work):
    records = inventory_records(work / "evidence")
    lines = ["HOLOUL_" + image.upper() + "_IMAGE=" + record["immutable_reference"]
             for image, record in zip(IMAGES, records)]
    # These extra values are STATIC TEST FIXTURES, never emitted in the release inventory.
    fixture = "\n".join(lines) + "\n" + "\n".join([
        "HOLOUL_WEBSITE_IMAGE=validation.invalid/website@sha256:" + "0" * 64,
        "HOLOUL_DASHBOARD_IMAGE=validation.invalid/dashboard@sha256:" + "0" * 64,
        "HOLOUL_PRODUCTION_SECRETS=/nonexistent-validation-only/secrets",
        "HOLOUL_PRODUCTION_DATA=/nonexistent-validation-only/data",
        "HOLOUL_SMTP_SCHEME=smtps", "HOLOUL_SMTP_HOST=smtp.invalid",
        "HOLOUL_SMTP_PORT=465", "HOLOUL_SMTP_USERNAME=validation-only",
        "HOLOUL_CONTACT_DASHBOARD_ACCEPTED=false", "HOLOUL_CONTACT_PRODUCTION_MAIL_ENABLED=false", ""])
    env_file = work / "compose-validation-fixture.env"
    env_file.write_text(fixture)
    result = json.loads(output(["python3", str(source / "deploy/production/validate.py"), "--env-file", str(env_file)]))
    require(result["passed"] is True, "frozen topology validation failed")
    plan = json.loads(output(["docker", "compose", "--env-file", str(env_file), "-f",
                              str(source / "compose.production.yaml"), "config", "--format", "json"]))
    services = {"backend": ["app", "queue", "document-queue", "ai-queue", "notification-queue", "scheduler", "migrate", "storage-bootstrap"],
                "postgres": ["postgres"], "redis": ["redis"], "storage": ["storage"],
                "portfolio": ["portfolio-processor"], "nginx": ["ingress"]}
    for image, record in zip(IMAGES, records):
        for service in services[image]:
            require(plan["services"][service]["image"] == record["immutable_reference"], "Compose image substitution mismatch")
            require(plan["services"][service]["user"] == IMAGES[image][3], "Compose runtime user mismatch")
    destination = work / "inventory"
    destination.mkdir()
    (destination / "production-images.env").write_text("\n".join(lines) + "\n")
    write_json(destination / "production-images.json", {"images": records, "compose_validation": result,
        "frontend_and_smtp_values": "static validation fixtures only; not production acceptance", "deployment_authorized": False})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("prepare-all", "publish-all", "inventory"))
    parser.add_argument("--source", required=True, type=Path)
    args = parser.parse_args()
    os.umask(0o077)
    source = args.source.resolve()
    work = context(source)
    {"prepare-all": prepare_all, "publish-all": publish_all, "inventory": inventory}[args.command](source, work)


if __name__ == "__main__":
    main()
