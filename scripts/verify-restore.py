#!/usr/bin/env python3
"""Restore a synthetic full workflow in two disposable, private Compose projects."""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
import time

sys.dont_write_bytecode = True
from backup_restore import ARCHIVE_VOLUMES, Drill, ROOT, RecoveryError, now, sha, write_json


def image_id(reference):
    result = subprocess.run(["docker", "image", "inspect", "--format", "{{.Id}}", reference],
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=True)
    return result.stdout.decode().strip()


def available_ports():
    sockets = []
    try:
        for _ in range(6):
            item = socket.socket()
            item.bind(("127.0.0.1", 0))
            sockets.append(item)
        return [item.getsockname()[1] for item in sockets]
    finally:
        for item in sockets:
            item.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--app-image", default="holoul-app:b8-runtime")
    parser.add_argument("--inspector-image", default="holoul-inspector:b8-runtime")
    parser.add_argument("--keep-failed", action="store_true", help="Retain isolated failed resources for explicit diagnosis; never shared resources.")
    args = parser.parse_args()
    os.umask(0o077)
    nonce = secrets.token_hex(12)
    private = ROOT / "artifacts" / ("b8-restore-" + nonce)
    private.mkdir(mode=0o700)
    (private / "recovery.key").write_bytes(secrets.token_bytes(32))
    ports = available_ports()
    context = {"nonce": nonce, "app_image_reference": args.app_image, "app_image_id": image_id(args.app_image),
               "inspector_image_id": image_id(args.inspector_image),
               "ports": {"source": ports[:3], "target": ports[3:]}}
    write_json(private / "context.json", context)
    drill = Drill(private / "context.json")
    started = time.monotonic()
    phase = "isolated_source_startup"
    evidence = {"schema": "holoul-b8-restore-drill-v1", "ok": False, "synthetic_only": True,
                "recorded_at": now(), "application_image_reference": args.app_image,
                "application_image_id": context["app_image_id"], "inspector_image_id": context["inspector_image_id"],
                "production_provider_restore_certified": False, "pitr_certified": False,
                "offsite_key_escrow_certified": False, "private_artifact_directory": str(private.relative_to(ROOT))}
    try:
        phase = "authenticated_encryption_checks"
        drill.pin_image(context["app_image_id"])
        drill.pin_image(context["inspector_image_id"])
        evidence["encryption_checks"] = drill.verify_encryption()
        phase = "isolated_source_startup"
        # Ensure both generated namespaces are unused; never attach to an older run.
        for role in ("source", "target"):
            for kind in ("container", "volume", "network"):
                if drill.run(["docker", kind, "ls", "-aq" if kind == "container" else "-q", "--filter",
                              "label=com.docker.compose.project=" + drill.project(role)]).strip():
                    raise RecoveryError("drill_namespace_already_exists")
        drill.validate_plan("source")
        drill.validate_plan("target")
        # Only this invocation's validated, previously empty namespaces may be
        # removed on failure. Record ownership before the first resource create.
        write_json(private / "owned-projects.json", {role: drill.project(role) for role in ("source", "target")})
        print('{"event":"restore_drill.synthetic_source_starting"}', flush=True)
        drill.run(drill.compose("source", "up", "-d", "--no-build", "--wait", "--wait-timeout", "900"), timeout=930)
        phase = "synthetic_full_workflow"
        fixture = drill.run(drill.compose("source", "exec", "-T", "-e", "HOLOUL_INTAKE_SMOKE=1", "app", "php",
            "scripts/verify-intake-runtime.php", "fixtures", nonce), timeout=90)
        (private / "fixtures.json").write_bytes(fixture)
        drill.run(drill.compose("source", "cp", "nginx:/run/holoul-tls/certificate.pem", str(private / "ca.pem")))
        print('{"event":"restore_drill.synthetic_lineage_creating"}', flush=True)
        smoke = drill.run([sys.executable, str(ROOT / "scripts" / "verify-projects.py"),
            "--origin", "https://localhost:" + str(ports[1]), "--ca-cert", str(private / "ca.pem"),
            "--mailpit-url", "http://localhost:" + str(ports[2]), "--fixtures", str(private / "fixtures.json")], timeout=600)
        workflow = json.loads(smoke)
        if workflow.get("ok") is not True:
            raise RecoveryError("synthetic_lineage_smoke_failed")
        write_json(private / "source-workflow.json", workflow)
        # Snapshot after every writer is stopped. Restarts below are DB/storage
        # only; no queue, scheduler, ingress or outbound email runs during restore.
        phase = "quiesce_and_encrypted_backup"
        drill.run(drill.compose("source", "stop", "--timeout", "150"), timeout=210)
        drill.run(drill.compose("source", "up", "-d", "--no-build", "--no-deps", "--wait", "--wait-timeout", "180", "postgres", "storage"), timeout=210)
        print('{"event":"restore_drill.backup_started"}', flush=True)
        source = drill.backup()
        phase = "authenticated_archive_negative_check"
        # A corrupted ciphertext must fail before any archive enters a target.
        sealed = drill.bundle / "app_secrets.sealed"
        corrupt = bytearray(sealed.read_bytes())
        corrupt[-1] ^= 1
        with (private / "commands.log").open("ab") as error:
            rejected = subprocess.run(drill.seal("open", "app_secrets"), cwd=ROOT, env=drill.env,
                input=bytes(corrupt), stdout=subprocess.DEVNULL, stderr=error, timeout=30, check=False).returncode != 0
        if not rejected:
            raise RecoveryError("corrupt_backup_was_accepted")
        phase = "independent_restore"
        print('{"event":"restore_drill.restore_started"}', flush=True)
        restored = drill.restore()
        phase = "result_certification"
        backup_status = json.loads((private / "backup-status.json").read_text())
        restore_status = json.loads((private / "restore-status.json").read_text())
        manifest = json.loads((drill.bundle / "manifest.json").read_text())
        evidence.update({"ok": True, "database_tables": len(restored["tables"]),
            "database_rows": sum(table["rows"] for table in restored["tables"].values()),
            "sequences": len(restored["sequences"]), "constraints": len(restored["constraints"]),
            "triggers": len(restored["triggers"]), "runtime_grants_preserved": source["grants"] == restored["grants"],
            "all_rows_and_sequences_exact": source["tables"] == restored["tables"] and source["sequences"] == restored["sequences"],
            "lineage_projects": restored["lineage_projects"], "history_counts": restored["history_counts"],
            "available_documents": len(restored["documents"]), "private_object_bytes": sum(row["bytes"] for row in restored["documents"]),
            "all_object_versions_and_checksums_exact": source["documents"] == restored["documents"],
            "mfa_credentials_decrypted": restored["mfa_credentials_decrypted"], "application_and_storage_keys_recovered": True,
            "authenticated_encryption": "libsodium XChaCha20-Poly1305 secretstream", "corrupted_ciphertext_rejected": rejected,
            "backup_duration_ms": backup_status["duration_ms"], "restore_duration_ms": restore_status["duration_ms"],
            "encrypted_archives": len(ARCHIVE_VOLUMES) + 1,
            "encrypted_bytes": sum(item["bytes"] for item in manifest["archives"].values()),
            "backup_manifest_sha256": sha(drill.bundle / "manifest.json"),
            "transaction_guard": json.loads((private / "transaction-guard.json").read_text()),
            "scope": "quiesced PostgreSQL logical backup and full cold SeaweedFS data/metadata plus separate encrypted key material; synthetic localhost only"})
    except (Exception, KeyboardInterrupt):
        evidence["failed_phase"] = phase
    finally:
        evidence["resources_removed"] = False
        if evidence["ok"] or not args.keep_failed:
            try:
                drill.cleanup()
                evidence["resources_removed"] = True
            except Exception:
                evidence["ok"] = False
                evidence["cleanup_failed"] = True
        # Password-bearing HTTP fixtures have no recovery value; encrypted DB and
        # secret archives remain 0600 in the private 0700 directory for evidence.
        (private / "fixtures.json").unlink(missing_ok=True)
        evidence["total_duration_ms"] = round((time.monotonic() - started) * 1000)
        write_json(ROOT / "artifacts" / "runtime-restore.json", evidence)
        print(json.dumps(evidence, separators=(",", ":")), flush=True)
    return 0 if evidence["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
