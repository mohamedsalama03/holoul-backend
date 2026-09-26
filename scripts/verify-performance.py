#!/usr/bin/env python3
"""Reproducible local B8 workload, CPU constraint, queue isolation and profiling."""
from __future__ import annotations

import argparse
import json
from datetime import datetime
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import urllib.parse

ROOT = Path(__file__).resolve().parent.parent
ARTIFACTS = ROOT / "artifacts"
SERVICES = ["app", "postgres", "redis", "storage", "scanner", "inspector", "nginx", "mailpit",
            "queue", "document-queue", "ai-queue", "notification-queue", "scheduler", "signature-updater"]
COMPOSE = ["docker", "compose"]
STOP = threading.Event()


def command(arguments, *, data=None, timeout=900):
    result = subprocess.run(arguments, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE, cwd=ROOT, timeout=timeout)
    if result.returncode:
        # Private fixture credentials and exception bindings never enter public logs.
        try:
            failure = json.loads(result.stderr)
            if failure.get("event") == "performance.fixture_failed":
                print(json.dumps(failure), file=sys.stderr)
        except (ValueError, AttributeError):
            pass
        raise RuntimeError("bounded_command_failed_" + str(result.returncode))
    return result.stdout


def fixture(mode, data=None, run=None):
    args = COMPOSE + ["exec", "-T", "-e", "HOLOUL_B8_PERFORMANCE=1", "app", "timeout", "-s", "TERM", "-k", "10", "780", "php", "scripts/performance-fixture.php", mode]
    if run:
        args.append(run)
    return command(args, data=data)


def write(path, value, private=False):
    path.write_bytes(value if isinstance(value, bytes) else (json.dumps(value, indent=2) + "\n").encode())
    if private:
        path.chmod(0o600)


def complete(snapshot):
    return bool(snapshot["queues"]) and all(row["state"] == "succeeded" for row in snapshot["queues"])


def live_drain_evidence(light, heavy):
    expected = {"identity.recovery_mail", "notifications.email"}
    rows = light["queues"]
    if len(rows) != 2 or {row["kind"] for row in rows} != expected:
        raise RuntimeError("live_light_batch_not_proven")
    documents = next((row for row in heavy["queues"] if row["kind"] == "documents.scan" and row["state"] == "succeeded"), None)
    ai = next((row for row in heavy["queues"] if row["kind"] == "ai.generate" and row["state"] == "succeeded"), None)
    if documents is None or ai is None:
        raise RuntimeError("live_heavy_drain_not_proven")
    document_end = datetime.fromisoformat(documents["last_completed_at"])
    ai_end = datetime.fromisoformat(ai["last_completed_at"])
    evidence = []
    for row in rows:
        if row["state"] != "succeeded" or row["count"] != 1 or row["min_attempts"] != 1 or row["max_attempts"] != 1:
            raise RuntimeError("live_light_first_attempt_not_proven")
        created = datetime.fromisoformat(row["first_created_at"])
        completed = datetime.fromisoformat(row["last_completed_at"])
        duration = (completed - created).total_seconds()
        if not 0 <= duration <= 15:
            raise RuntimeError("live_light_completion_bound_exceeded")
        if created >= document_end or completed >= document_end:
            raise RuntimeError("live_document_drain_overlap_not_proven")
        evidence.append({"kind": row["kind"], "count": 1, "attempts": 1,
                         "created_at": row["first_created_at"], "completed_at": row["last_completed_at"],
                         "completion_seconds": duration, "completed_before_ai_drain": completed < ai_end})
    return {"passed": True, "completion_bound_seconds": 15, "operations": evidence,
            "document_first_completed_at": documents["first_completed_at"],
            "document_last_completed_at": documents["last_completed_at"],
            "ai_first_completed_at": ai["first_completed_at"], "ai_last_completed_at": ai["last_completed_at"],
            "limits": "A fresh default operation and notification are created after heavy workers resume and complete before the final document scan. AI timing is reported separately; this does not prove CPU saturation or external AI throughput."}


def sample():
    return {"time": time.time(), "operations": json.loads(command(COMPOSE + ["exec", "-T", "app", "php", "artisan", "operations:observe", "--json"], timeout=45)),
            "containers": [json.loads(line) for line in command(["docker", "stats", "--no-stream", "--format", "{{json .}}"] +
                [command(COMPOSE + ["ps", "-q", service]).decode().strip() for service in SERVICES], timeout=45).splitlines()]}


def monitor(output):
    samples = []
    while not STOP.is_set():
        try:
            samples.append(sample())
        except (RuntimeError, subprocess.TimeoutExpired, ValueError):
            samples.append({"time": time.time(), "unavailable": True})
        write(output, samples)
        STOP.wait(5)


def run(arrival_model="burst"):
    os.chdir(ROOT)
    ARTIFACTS.mkdir(exist_ok=True)
    private = Path(tempfile.mkdtemp(prefix="b8-performance.", dir=ARTIFACTS))
    private.chmod(0o700)
    run_id = secrets.token_hex(12)
    affinity = {}
    paused = []
    mail_stopped = False
    created = False
    telemetry = None
    stage = "guard"
    try:
        info = json.loads(command(["docker", "info", "--format", "{{json .}}"], timeout=30))
        if info["NCPU"] < 2:
            raise RuntimeError("two_cpu_host_required")
        release = command(["docker", "image", "inspect", "--format", "{{.Id}}", "holoul-app:b8-runtime"]).decode().strip()
        app_container = command(COMPOSE + ["ps", "-q", "app"]).decode().strip()
        app_details = json.loads(command(["docker", "inspect", app_container]))[0]
        environment = dict(item.split("=", 1) for item in app_details["Config"]["Env"] if "=" in item)
        origin = urllib.parse.urlsplit(environment.get("APP_URL", ""))
        if app_details["Config"]["Labels"].get("com.docker.compose.project") != "holoul" \
                or environment.get("APP_ENV") != "production" or environment.get("HOLOUL_DEPLOYMENT_PROFILE") != "local-verification" \
                or origin.scheme != "https" or origin.hostname != "localhost" \
                or environment.get("IDENTITY_MAIL_SANDBOX", "").lower() != "true" or environment.get("MAIL_HOST") != "mailpit":
            raise RuntimeError("synthetic_local_runtime_required_before_mutation")
        configuration = json.loads(command(COMPOSE + ["exec", "-T", "app", "php", "artisan", "operations:validate-config", "--json"]))
        if configuration.get("valid") is not True:
            raise RuntimeError("local_configuration_guard_failed")
        for service in SERVICES:
            container = command(COMPOSE + ["ps", "-q", service]).decode().strip()
            details = json.loads(command(["docker", "inspect", container]))[0]
            if details["Config"]["Labels"].get("com.docker.compose.project") != "holoul":
                raise RuntimeError("only_explicit_local_holoul_project_allowed")
            if service in ("app", "queue", "document-queue", "ai-queue", "notification-queue", "scheduler") and details["Image"] != release:
                raise RuntimeError("release_image_mismatch")
            affinity[container] = details["HostConfig"]["CpusetCpus"]
            command(["docker", "update", "--cpuset-cpus", "0,1", container])
        write(ARTIFACTS / "b8-performance-environment.json", {"release_image": release, "host_cpus": info["NCPU"],
            "shared_test_cpu_affinity": "0,1", "docker_memory_bytes": info["MemTotal"], "docker_version": info["ServerVersion"],
            "production_target": {"cpus": 2, "ram_gb": 8, "disk_tb": 1, "customers": 150, "projects": 100, "concurrent_users": 20},
            "limits": "All application/dependency containers share two logical CPUs; Docker daemon/load generator run on the host. Host storage/network differ from VPS. No disk IOPS or peak-capacity claim."})
        stage = "fixture_create"
        created = True  # cleanup-run covers any create commit→output interruption.
        manifest = fixture("create", run=run_id)
        write(private / "accounts.json", manifest, True)
        stage = "fixture_seed"
        manifest = fixture("seed", manifest)
        write(private / "fixtures.json", manifest, True)
        print(json.dumps({"event": "b8.performance_seeded", "customers": 150, "projects": 100, "requests": 1000}), flush=True)
        # Drain the seeding mail before measuring isolation of a fresh small batch.
        deadline = time.monotonic() + 300
        while True:
            state = json.loads(command(COMPOSE + ["exec", "-T", "app", "php", "artisan", "operations:observe", "--json"]))
            write(ARTIFACTS / "b8-performance-before.json", state)
            if time.monotonic() > deadline:
                raise RuntimeError("seed_queue_drain_timeout")
            queues = state.get("redis", {}).get("queues", {})
            postgres = state.get("postgres", {})
            backlog = postgres.get("durable_backlog", {})
            backlog_rows = backlog.values() if isinstance(backlog, dict) else backlog
            if queues and all(sum(queue.values()) == 0 for queue in queues.values()) and postgres.get("available") is True \
                    and all(row.get("pending", 0) == 0 and row.get("running", 0) == 0 for row in backlog_rows):
                break
            time.sleep(5)
        stage = "queue_isolation"
        for service in ("document-queue", "ai-queue"):
            container = command(COMPOSE + ["ps", "-q", service]).decode().strip()
            paused.append(container)
            command(["docker", "pause", container])
        queue_started = time.monotonic()
        manifest = fixture("queue-seed", manifest)
        write(private / "fixtures.json", manifest, True)
        deadline = time.monotonic() + 90
        snapshots = []
        while True:
            state = json.loads(fixture("queue-evidence", manifest))
            snapshots.append({"elapsed_seconds": time.monotonic() - queue_started, **state})
            light = [row for row in state["queues"] if row["kind"] in ("identity.recovery_mail", "notifications.email")]
            heavy = [row for row in state["queues"] if row["kind"] in ("documents.scan", "ai.generate")]
            if len(light) >= 2 and all(row["state"] == "succeeded" for row in light):
                if not all(row["state"] == "pending" for row in heavy) or sum(row["count"] for row in heavy) != 33:
                    raise RuntimeError("heavy_queue_isolation_not_proven")
                break
            if time.monotonic() > deadline:
                raise RuntimeError("important_work_starved")
            time.sleep(2)
        paused_evidence = sample()
        resumed = time.monotonic()
        for container in paused:
            command(["docker", "unpause", container])
        paused.clear()
        # The same safe owner-action recipe also creates a fresh live batch
        # without any outage. Keep its correlation separate from the heavy batch.
        live_started = time.monotonic()
        live_manifest = fixture("queue-retry-seed", manifest)
        write(private / "live-queue-fixtures.json", live_manifest, True)
        live_samples = []
        live_state = None
        deadline = time.monotonic() + 300
        while True:
            state = json.loads(fixture("queue-evidence", manifest))
            snapshots.append({"elapsed_seconds": time.monotonic() - queue_started, **state})
            if live_state is None or not complete(live_state):
                live_state = json.loads(fixture("queue-evidence", live_manifest))
                live_samples.append({"elapsed_seconds": time.monotonic() - live_started, **live_state})
                if any(row["state"] == "failed" for row in live_state["queues"]):
                    raise RuntimeError("live_light_batch_failed")
                if not complete(live_state) and time.monotonic() - live_started > 15:
                    raise RuntimeError("live_light_completion_timeout")
            if complete(state) and complete(live_state):
                if state["documents"] != [{"state": "available", "count": 25}] \
                        or state["ai_runs"] != [{"state": "succeeded", "provider": "sandbox", "model": "sandbox-v1", "count": 8}] \
                        or state["ai_attempts"] != [{"outcome": "succeeded", "count": 8}]:
                    raise RuntimeError("queue_business_success_not_proven")
                break
            if time.monotonic() > deadline or any(row["state"] == "failed" for row in state["queues"]):
                raise RuntimeError("queue_drain_failed")
            time.sleep(2)
        drain = time.monotonic() - resumed
        live_evidence = live_drain_evidence(live_state, state)
        write(ARTIFACTS / "b8-queue-capacity.json", {"isolation_passed": True, "paused_heavy_seconds": resumed - queue_started,
            "drain_seconds": drain, "heavy_jobs": 33, "combined_heavy_drain_jobs_per_second": 33 / drain,
            "snapshots": snapshots, "live_drain": live_evidence, "live_drain_snapshots": live_samples,
            "paused_observation": paused_evidence, "drained_observation": sample(), "workers_per_queue": 1,
            "limits": "Pausing proves service isolation during unavailability; a separate fresh light batch proves completion during actual document drain within 15 seconds. Drain throughput measures 25 real 500-page PDFs and 8 local sandbox AI jobs; it does not estimate external-provider capacity or prove CPU saturation. Heavy completion latency includes intentional pause; no retries or failures are induced in this scenario."})
        stage = "query_profile"
        write(ARTIFACTS / "b8-query-profile.json", fixture("profile", manifest))
        stage = "smtp_retry_recovery"
        mail_stopped = True
        command(COMPOSE + ["stop", "mailpit"])
        # A fresh worker proves known pre-DATA failure; a stale SMTP connection
        # can instead produce an uncertain acknowledgement requiring review.
        command(COMPOSE + ["restart", "notification-queue"])
        retry_manifest = fixture("queue-retry-seed", manifest)
        retry_start = time.monotonic()
        retry_samples = []
        deadline = time.monotonic() + 90
        while True:
            state = json.loads(fixture("queue-evidence", retry_manifest))
            retry_samples.append({"elapsed_seconds": time.monotonic() - retry_start, **state})
            write(ARTIFACTS / "b8-queue-retry-progress.json", retry_samples)
            if len(state["queues"]) == 2 and all(row["min_attempts"] >= 1 and row["state"] != "running" for row in state["queues"]):
                default = next(row for row in state["queues"] if row["kind"] == "identity.recovery_mail")
                notification = next(row for row in state["queues"] if row["kind"] == "notifications.email")
                if default["state"] != "failed" or default["failure_code"] != "mail_delivery_uncertain" or notification["state"] != "pending":
                    raise RuntimeError("smtp_outage_wrong_disposition")
                break
            if time.monotonic() > deadline:
                raise RuntimeError("smtp_failure_not_observed")
            time.sleep(2)
        command(COMPOSE + ["start", "--wait", "mailpit"])
        mail_stopped = False
        command(COMPOSE + ["restart", "queue"])
        retry_manifest = fixture("queue-recover-seed", retry_manifest)
        recovered = time.monotonic()
        deadline = recovered + 180
        while True:
            state = json.loads(fixture("queue-evidence", retry_manifest))
            retry_samples.append({"elapsed_seconds": time.monotonic() - retry_start, **state})
            terminal = len(state["queues"]) == 3 and all(row["state"] in ("succeeded", "failed") for row in state["queues"])
            if terminal:
                successes = [row for row in state["queues"] if row["state"] == "succeeded"]
                if len(successes) != 2 or not any(row["kind"] == "notifications.email" and row["min_attempts"] >= 2 for row in successes):
                    raise RuntimeError("retry_attempt_not_proven")
                break
            if time.monotonic() > deadline:
                raise RuntimeError("smtp_recovery_timeout")
            time.sleep(2)
        write(ARTIFACTS / "b8-queue-retry.json", {"passed": True, "recovery_seconds": time.monotonic() - recovered,
            "failure": "Local SMTP outage: notification retries known pre-DATA failure on its original ledger after real backoff. B2 recovery mail retains its uncertain terminal attempt and requires a fresh recovery request; no blind resend.",
            "snapshots": retry_samples})
        command(COMPOSE + ["cp", "nginx:/run/holoul-tls/certificate.pem", str(private / "ca.pem")])
        port = command(COMPOSE + ["port", "nginx", "8443"]).decode().strip().rsplit(":", 1)[1]
        stage = "http_load"
        telemetry = threading.Thread(target=monitor, args=(ARTIFACTS / "b8-load-telemetry.json",), daemon=True)
        telemetry.start()
        result = subprocess.run([sys.executable, str(ROOT / "scripts/performance-http.py"), "--origin", "https://localhost:" + port,
            "--ca-cert", str(private / "ca.pem"), "--fixtures", str(private / "fixtures.json"),
            "--output", str(ARTIFACTS / "b8-http-load.json"), "--arrival-model", arrival_model], cwd=ROOT, timeout=600)
        if result.returncode:
            raise RuntimeError("http_load_acceptance_failed")
        print(json.dumps({"event": "b8.performance_passed", "queue_isolation": True, "load_passed": True}), flush=True)
    except (RuntimeError, subprocess.TimeoutExpired) as error:
        print(json.dumps({"event": "b8.performance_failed", "stage": stage, "code": str(error)[:160]}), file=sys.stderr)
        return 1
    finally:
        STOP.set()
        if telemetry:
            telemetry.join(timeout=55)
        restoration_errors = []
        for container in paused:
            try:
                command(["docker", "unpause", container], timeout=30)
            except (RuntimeError, subprocess.TimeoutExpired):
                restoration_errors.append("heavy_worker_resume")
        if mail_stopped:
            try:
                command(COMPOSE + ["start", "--wait", "mailpit"], timeout=120)
            except (RuntimeError, subprocess.TimeoutExpired):
                restoration_errors.append("smtp_restore")
        cleanup_ok = not created
        if created:
            try:
                result = fixture("cleanup-run", run=run_id)
                write(ARTIFACTS / "b8-performance-cleanup.json", result)
                cleanup_ok = True
            except (RuntimeError, subprocess.TimeoutExpired):
                print(json.dumps({"event": "b8.performance_cleanup_failed", "private_manifest_retained": True}), file=sys.stderr)
        for container, cpus in affinity.items():
            try:
                command(["docker", "update", "--cpuset-cpus", cpus, container], timeout=30)
            except (RuntimeError, subprocess.TimeoutExpired):
                restoration_errors.append("cpu_affinity_restore")
        write(ARTIFACTS / "b8-performance-restoration.json", {"passed": not restoration_errors, "failures": restoration_errors})
        if cleanup_ok and not restoration_errors and private.resolve().parent == ARTIFACTS.resolve() and private.name.startswith("b8-performance."):
            shutil.rmtree(private)
        if not cleanup_ok or restoration_errors:
            return 1
    return 0


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--arrival-model", choices=("burst", "staggered"), default="burst",
                        help="Burst remains the strict default; staggered is a separately labeled workload, not a burst acceptance override.")
    raise SystemExit(run(parser.parse_args().arrival_model))
