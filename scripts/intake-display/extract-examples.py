#!/usr/bin/env python3
"""Select only synthetic staff intake responses; never export authentication or capability responses."""
import argparse, json
from pathlib import Path
parser = argparse.ArgumentParser(); parser.add_argument("--samples", type=Path, required=True); parser.add_argument("--output", type=Path, required=True); args = parser.parse_args()
rows = [json.loads(line) for line in args.samples.read_text().splitlines()]
rows = [r for r in rows if "IntakeDisplayHttpTest::" in r["test"] and r["path"].startswith("/api/v1/admin/project-requests")]
assert rows
examples = []
def data(row): return (row.get("body") or {}).get("data")
def select(label, predicate):
    matches = [r for r in rows if predicate(r)]
    assert matches, label
    row = matches[-1]
    examples.append({"scenario": label, "method": row["method"], "path": row["path"], "status": row["status"],
                     "view": "dashboard" if "display" in label or "actor" in label else "default",
                     "headers": {k: v for k, v in row["headers"].items() if k in {"etag", "content-type", "cache-control"}}, "body": row["body"]})
def detail(row): return row["status"] == 200 and isinstance(data(row), dict) and "latest_revision" in data(row) and "customer_display_name" in data(row)
select("account detail display", lambda r: detail(r) and data(r)["provenance"] == "customer" and "links_to_directory" in r["test"])
select("guest unclaimed detail display", lambda r: detail(r) and data(r)["provenance"] == "guest" and data(r)["claimed"] is False)
select("guest claimed amended detail display", lambda r: detail(r) and data(r)["provenance"] == "guest" and data(r)["claimed"] is True)
select("closed request assigned staff display", lambda r: detail(r) and data(r)["state"] == "rejected" and "closed_request" in r["test"])
select("by-reference detail display", lambda r: detail(r) and "/by-reference/" in r["path"])
select("request list display", lambda r: r["status"] == 200 and isinstance(data(r), list) and len(data(r)) > 1 and "customer_display_name" in data(r)[0])
select("customer staff customer history actors", lambda r: r["status"] == 200 and r["path"].endswith("/history") and isinstance(data(r), list)
       and [a.get("actor", {}).get("kind") for a in data(r)] == ["customer", "staff", "customer"])
select("guest history actor after claim and amendment", lambda r: r["status"] == 200 and r["path"].endswith("/history") and isinstance(data(r), list)
       and len(data(r)) == 1 and data(r)[0].get("actor", {}).get("kind") == "guest")
select("automated expiry system actor", lambda r: r["status"] == 200 and r["path"].endswith("/history") and isinstance(data(r), list)
       and data(r) and data(r)[-1].get("actor", {}).get("kind") == "system")
select("disabled historical staff assignment display", lambda r: r["status"] == 200 and r["path"].endswith("/assignments") and isinstance(data(r), list)
       and len(data(r)) == 2 and "assigned_by_staff" in data(r)[0])
select("unchanged command summary", lambda r: r["method"] == "POST" and r["status"] == 200 and isinstance(data(r), dict) and "assigned_staff_id" in data(r))
select("stale command rejection", lambda r: r["method"] == "POST" and r["status"] == 412)
for example in examples:
    encoded = json.dumps(example).lower()
    for forbidden in ('"password"', '"secret"', '"claim_token"', '"capability"', '"recovery_codes"', '"set-cookie"'):
        assert forbidden not in encoded, forbidden
args.output.write_text(json.dumps({"source": "Actual synthetic PostgreSQL HTTP tests; no authentication responses", "examples": examples}, ensure_ascii=False, indent=2) + "\n")
print(json.dumps({"examples": len(examples), "secret_fields": 0}))
