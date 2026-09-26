#!/usr/bin/env python3
"""Twenty real authenticated users; bounded HTTPS mixed workload and races."""
from __future__ import annotations

import argparse
import concurrent.futures
import copy
import http.cookiejar
import importlib.util
import json
import math
from pathlib import Path
import secrets
import ssl
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("documents_smoke", Path(__file__).with_name("verify-documents.py"))
documents = importlib.util.module_from_spec(spec)
spec.loader.exec_module(documents)
identity, intake = documents.identity, documents.intake
API, require = identity.API, identity.require


def percentiles(values):
    values = sorted(values)
    return {"p" + str(p): round(values[max(0, math.ceil(len(values) * p / 100) - 1)], 3) for p in (50, 95, 99)} if values else {}


def clone_cookie_jar(source):
    # CookieJar owns a thread lock; clone cookies rather than that lock.
    target = http.cookiejar.CookieJar()
    for cookie in source:
        target.set_cookie(copy.copy(cookie))
    return target


def execute_schedule(operation, waves, interval, arrival_model, users=20):
    """Keep each authenticated client sequential under either explicit schedule."""
    lock = threading.Lock()
    inflight = 0
    observed_max = 0
    start_lags = []
    start = time.monotonic()

    def measured(index, wave, scheduled):
        nonlocal inflight, observed_max
        with lock:
            start_lags.append(max(0, time.monotonic() - scheduled) * 1000)
            inflight += 1
            observed_max = max(observed_max, inflight)
        try:
            return operation(index, wave)
        finally:
            with lock:
                inflight -= 1

    def phased_user(index):
        results = []
        phase = index * interval / users
        for wave in range(waves):
            scheduled = start + phase + wave * interval
            time.sleep(max(0, scheduled - time.monotonic()))
            # A late request delays only this user's next request. Absolute
            # targets expose lateness; there is never overlapping client state.
            results.append(measured(index, wave, scheduled))
        return results

    results = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=users) as pool:
        if arrival_model == "burst":
            for wave in range(waves):
                scheduled = start + wave * interval
                time.sleep(max(0, scheduled - time.monotonic()))
                futures = [pool.submit(measured, index, wave, scheduled) for index in range(users)]
                results.extend(future.result() for future in futures)
        elif arrival_model == "staggered":
            futures = [pool.submit(phased_user, index) for index in range(users)]
            for future in futures:
                results.extend(future.result())
        else:
            raise ValueError("unsupported_arrival_model")
    duration = time.monotonic() - start
    missed_slots = sum(lag >= interval * 1000 / users for lag in start_lags) if arrival_model == "staggered" else 0
    return results, duration, {
        "observed_max_inflight": observed_max,
        "scheduled_start_lag_ms": {"samples": len(start_lags), **percentiles(start_lags), "max": round(max(start_lags), 3)},
        "missed_arrival_slots": missed_slots,
        "missed_slot_threshold_ms": interval * 1000 / users if arrival_model == "staggered" else None,
        "arrival_schedule_valid": missed_slots == 0,
    }


def request(client, method, path, body=None, etag=None, key=None):
    headers = {"Accept": "application/json", "Origin": client.origin, "Referer": client.origin + "/",
               "Sec-Fetch-Site": "same-origin"}
    if method != "GET":
        headers["X-XSRF-TOKEN"] = urllib.parse.unquote(client.cookie("XSRF-TOKEN").value)
        headers["Content-Type"] = "application/json"
    if etag:
        headers["If-Match"] = etag
    if key:
        headers["Idempotency-Key"] = key
    message = urllib.request.Request(client.origin + path, data=None if body is None else json.dumps(body).encode(), headers=headers, method=method)
    start = time.perf_counter()
    try:
        try:
            response = client.opener.open(message, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            code, payload, response_headers = identity.decode_response(response)
        require("no-store" in response_headers.get("Cache-Control", "").lower(), "cache_policy_missing")
        return code, payload, (time.perf_counter() - start) * 1000
    except (OSError, urllib.error.URLError, TimeoutError):
        return 0, {}, (time.perf_counter() - start) * 1000


def run(args):
    fixture = json.loads(args.fixtures.read_text())
    require(len(fixture["accounts"]) == 152 and (args.fixtures.stat().st_mode & 0o777) == 0o600, "fixture_invalid")
    origin = identity.local_origin(args.origin, https=True)
    context = ssl.create_default_context(cafile=str(args.ca_cert))
    labels = [f"customer_{i:03d}" for i in range(18)] + ["admin", "approver"]
    clients = []
    for label in labels:
        account = fixture["accounts"][label]
        client = documents.Browser(origin, context)
        client.csrf()
        staff = label in ("admin", "approver")
        result = identity.data(client.call("POST", API + "/auth/login", 202 if staff else 200,
                                          {"email": account["email"], "password": account["password"]}))
        if staff:
            require(result["next_step"] == "mfa_enrollment", "staff_mfa_required")
            enrollment = identity.data(client.call("POST", API + "/auth/mfa/enrollment", 200, {}))
            client.call("POST", API + "/auth/mfa/enrollment/confirm", 200, {"code": identity.totp(enrollment["secret"])})
        me = identity.data(client.call("GET", API + "/identity/me", 200))
        require(me["id"] == account["id"] and me["email_verified"] is True, "real_session_invalid")
        identity.validate_cookie_flags(client)
        clients.append(client)
    # Real production-image authorization and range validation, before timing.
    require(request(clients[0], "GET", API + "/admin/reports/dashboard")[0] == 403, "customer_report_access")
    require(request(clients[0], "GET", API + "/admin/audit-events")[0] == 403, "customer_audit_access")
    for report in ("dashboard", "requests", "projects", "customers"):
        code, payload, _ = request(clients[18], "GET", API + "/admin/reports/" + report)
        require(code == 200 and "revenue" not in json.dumps(payload.get("data", {})).lower(), "report_smoke_failed")
    require(request(clients[18], "GET", API + "/admin/reports/dashboard?from=2020-01-01")[0] == 422, "unbounded_report")
    require(request(clients[18], "GET", API + "/admin/audit-events?limit=101")[0] == 422, "unbounded_audit")

    def operation(index, wave):
        label, client = labels[index], clients[index]
        if index >= 18:
            choices = [("admin_requests", API + "/admin/project-requests?limit=25"),
                       ("dashboard", API + "/admin/reports/dashboard"),
                       ("audit_timeline", API + "/admin/audit-events?limit=25"),
                       ("admin_projects", API + "/admin/projects?limit=25")]
            name, path = choices[(wave + index) % len(choices)]
            return name, request(client, "GET", path), 200
        record = fixture["records"][label]
        if wave in (3, 9) and index < 8:
            draft = record["drafts"][0 if wave == 3 else 1]
            return "request_submission", request(client, "POST", API + "/project-requests/" + draft["id"] + "/submissions",
                                                  {}, draft["etag"], secrets.token_hex(24)), 201
        choices = [("customer_requests", API + "/project-requests?limit=25"),
                   ("notifications", API + "/notifications?limit=25"),
                   ("project_detail", API + "/projects/" + record["projects"][0]),
                   ("request_search", API + "/project-requests?q=portal&limit=25"),
                   ("request_detail", API + "/project-requests/" + record["requests"][0]),
                   ("customer_projects", API + "/projects?limit=25")]
        name, path = choices[(wave + index) % len(choices)]
        return name, request(client, "GET", path), 200

    results, duration, scheduling = execute_schedule(operation, args.waves, args.interval, args.arrival_model)
    timings = [item[1][2] for item in results]
    errors = sum(code != expected for _, (code, _, _), expected in results)
    endpoints = {}
    for name, (code, _, ms), expected in results:
        entry = endpoints.setdefault(name, {"latencies": [], "statuses": {}, "errors": 0})
        entry["latencies"].append(ms)
        entry["statuses"][str(code)] = entry["statuses"].get(str(code), 0) + 1
        entry["errors"] += code != expected
    for entry in endpoints.values():
        entry.update(percentiles(entry["latencies"]))
        entry["requests"] = len(entry.pop("latencies"))
        entry["latency_target_passed"] = entry["p95"] < 300 and entry["p99"] < 1000
    quantiles = percentiles(timings)
    latency_passed = quantiles["p95"] < 300 and quantiles["p99"] < 1000 and all(
        entry["latency_target_passed"] for entry in endpoints.values())
    report = {"concurrent_users": 20, "configured_users": 20, "max_inflight": 20, **scheduling,
              "max_inflight_semantics": "configured limit; observed_max_inflight is measured",
              "arrival_mode": args.arrival_model, "diagnostic_only": args.arrival_model == "staggered",
              "arrival_model": "20 simultaneous requests per wave" if args.arrival_model == "burst" else
                  "20 users with fixed staggered phases; one in-flight request per user",
              "user_phase_step_seconds": 0 if args.arrival_model == "burst" else args.interval / 20,
              "offered_rps": 20 / args.interval, "configured_window_seconds": args.waves * args.interval,
              "last_scheduled_start_seconds": (args.waves - 1) * args.interval +
                  (0 if args.arrival_model == "burst" else 19 * args.interval / 20),
              "wave_interval_seconds": args.interval, "waves": args.waves, "duration_seconds": round(duration, 3),
              "requests": len(results), "throughput_rps": round(len(results) / duration, 3),
              "observed_throughput_rps": round(len(results) / duration, 3), **quantiles,
              "errors": errors, "error_rate": errors / len(results), "endpoints": endpoints, "state_safe_races": [],
              "passed": False, "race_probes_complete": False, "latency_target_passed": latency_passed,
              "limits_unchanged": True, "dataset": fixture["dataset"],
              "limitations": "Closed bounded arrival schedule; no maximum-capacity claim. Login/MFA, setup, race probes, uploads and external-provider work excluded from latency percentiles. Per-endpoint tail percentiles have small samples. Staggered mode is an additional diagnostic and does not replace burst acceptance; cold TLS and latency/error targets are identical."}
    # Keep completed measurements even when a later race assertion fails.
    args.output.write_text(json.dumps(report, indent=2) + "\n")
    # Two identical submissions race, then distinct keys race against one ETag.
    # Clone cookie jars so concurrent transport never shares mutable client state.
    race_results = []
    for index, same_key in ((16, True), (17, False)):
        draft = fixture["records"][labels[index]]["drafts"][0]
        first_key = secrets.token_hex(24)
        pair = [documents.Browser(origin, context, clone_cookie_jar(clients[index].jar)) for _ in range(2)]
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            responses = list(pool.map(lambda item: request(item[1], "POST", API + "/project-requests/" + draft["id"] + "/submissions",
                {}, draft["etag"], first_key if same_key or item[0] == 0 else secrets.token_hex(24)), enumerate(pair)))
        codes = sorted(item[0] for item in responses)
        require(codes == [201, 201] if same_key else codes in ([201, 409], [201, 412]), "concurrency_outcome_invalid")
        if same_key:
            require(responses[0][1] == responses[1][1], "idempotent_replay_changed")
        race_results.append({"operation": "same_key_replay" if same_key else "stale_etag_competition", "statuses": codes})
        report["state_safe_races"] = race_results
        args.output.write_text(json.dumps(report, indent=2) + "\n")
    passed = errors == 0 and latency_passed and scheduling["arrival_schedule_valid"]
    report.update(passed=passed, race_probes_complete=True)
    args.output.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps({"event": "b8.http_load_complete", "passed": passed, "requests": len(results),
                      "arrival_mode": args.arrival_model, "diagnostic_only": args.arrival_model == "staggered",
                      "observed_max_inflight": scheduling["observed_max_inflight"],
                      "arrival_schedule_valid": scheduling["arrival_schedule_valid"],
                      "missed_arrival_slots": scheduling["missed_arrival_slots"], **quantiles, "errors": errors}))
    return 0 if passed else 1


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--origin", required=True)
    parser.add_argument("--ca-cert", type=Path, required=True)
    parser.add_argument("--fixtures", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--waves", type=int, default=24, choices=range(12, 61))
    parser.add_argument("--interval", type=float, default=5, choices=(2.0, 5.0, 10.0))
    parser.add_argument("--arrival-model", choices=("burst", "staggered"), default="burst")
    arguments = parser.parse_args()
    try:
        raise SystemExit(run(arguments))
    except identity.SmokeFailure as error:
        print(json.dumps({"event": "b8.http_load_failed", "code": str(error)}), file=sys.stderr)
        raise SystemExit(1) from None
