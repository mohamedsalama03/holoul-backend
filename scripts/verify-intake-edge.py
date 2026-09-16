#!/usr/bin/env python3
"""Bounded local ingress regression; no credentials or private payloads."""
import argparse
import concurrent.futures
import json
import urllib.error
import urllib.parse
import urllib.request
import uuid

parser = argparse.ArgumentParser()
parser.add_argument("--origin", required=True)
args = parser.parse_args()
origin = urllib.parse.urlsplit(args.origin)
if origin.scheme != "http" or origin.hostname not in ("localhost", "127.0.0.1") or origin.path:
    raise SystemExit("Only the local HTTP fixture is permitted.")


def probe(_):
    try:
        with urllib.request.urlopen(args.origin + "/health/live", timeout=10) as response:
            return response.status
    except urllib.error.HTTPError as response:
        if response.code != 429:
            raise
        body = json.loads(response.read(2048))
        assert body["error"]["code"] == "RATE_LIMITED"
        assert str(uuid.UUID(body["request_id"])) == body["request_id"].lower()
        assert response.headers.get("X-Request-ID") == body["request_id"]
        assert response.headers.get("Retry-After") == "1"
        assert response.headers.get("Cache-Control") == "no-store"
        return 429


with concurrent.futures.ThreadPoolExecutor(max_workers=16) as executor:
    results = list(executor.map(probe, range(100)))
assert 429 in results and all(status in (200, 429) for status in results)
print(json.dumps({"event": "verification.intake_ingress", "ok": True,
                  "requests": len(results), "rate_limited": results.count(429)}, separators=(",", ":")))
