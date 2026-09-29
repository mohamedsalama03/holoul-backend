#!/usr/bin/env python3
"""Enforce the exact 1.4 default contract and the five explicit display variants."""
import argparse, copy, hashlib, json
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
METHODS = {"get", "post", "put", "patch", "delete", "head", "options"}
EXPECTED = {"intakeStaffList", "intakeStaffDetail", "intakeStaffReference", "intakeStaffHistory", "intakeStaffAssignments"}
parser = argparse.ArgumentParser()
parser.add_argument("--report", type=Path, required=True)
parser.add_argument("--routes", type=Path)
args = parser.parse_args()
old_bytes = (ROOT / "docs/intake-display/baseline-1.4.0.openapi.json").read_bytes()
new_bytes = (ROOT / "docs/openapi.json").read_bytes()
assert hashlib.sha256(old_bytes).hexdigest() == "7739bd957665b285c3baea8a4883aa987d6d11dee3fb43bcc5ef1ff9fb0c8051"
old, new = json.loads(old_bytes), json.loads(new_bytes)
def operations(spec):
    return {(method.upper(), path): op for path, item in spec["paths"].items() for method, op in item.items() if method in METHODS}
a, b = operations(old), operations(new)
assert a.keys() == b.keys()
changed = []
for key, previous in a.items():
    current = copy.deepcopy(b[key])
    if previous == current:
        continue
    operation = previous["operationId"]
    assert operation in EXPECTED, ("Unexpected operation change", key)
    changed.append(operation)
    assert current["parameters"][-1] == {"$ref": "#/components/parameters/IntakeDashboardView"}
    assert current["parameters"][:-1] == previous["parameters"]
    variants = current["responses"]["200"]["content"]["application/json"]["schema"]["anyOf"]
    original = previous["responses"]["200"]["content"]["application/json"]["schema"]
    assert variants[0] == original
    assert current.pop("x-response-views") == {"default": original, "dashboard": variants[1]}
    current["responses"]["200"]["content"]["application/json"]["schema"] = original
    current["parameters"] = previous["parameters"]
    assert current["description"].startswith(previous["description"])
    assert set(previous["x-source"]) <= set(current["x-source"])
    current["description"] = previous["description"]
    current["x-source"] = previous["x-source"]
    assert current == previous, ("Unexpected default/security/header change", key)
assert set(changed) == EXPECTED
for kind, entries in old["components"].items():
    for name, value in entries.items():
        assert new["components"][kind][name] == value, ("Existing component changed", kind, name)
added_schemas = sorted(new["components"]["schemas"].keys() - old["components"]["schemas"].keys())
added_params = sorted(new["components"]["parameters"].keys() - old["components"]["parameters"].keys())
assert len(added_schemas) == 10 and added_params == ["IntakeDashboardView"]
# Every added object stays strict, including nested actors; no broad additionalProperties escape hatch.
def strict(node):
    if isinstance(node, dict):
        if node.get("type") == "object":
            assert node.get("additionalProperties") is False
        for value in node.values(): strict(value)
    elif isinstance(node, list):
        for value in node: strict(value)
for name in added_schemas: strict(new["components"]["schemas"][name])
route_result = None
if args.routes:
    actual = {(method, "/" + row["uri"]) for row in json.loads(args.routes.read_text())
              if row["uri"].startswith("api/v1") or row["uri"] == "sanctum/csrf-cookie"
              for method in row["method"].split("|") if method != "HEAD"}
    assert actual == b.keys(), {"undocumented": sorted(actual - b.keys()), "missing": sorted(b.keys() - actual)}
    route_result = {"matched": len(actual), "missing": [], "undocumented": []}
report = {"passed": True, "baseline_commit": "3bc85401e96a1f8b843d1db0f13a4ae9b86572ee",
          "previous_version": old["info"]["version"], "new_version": new["info"]["version"],
          "previous_sha256": hashlib.sha256(old_bytes).hexdigest(), "new_sha256": hashlib.sha256(new_bytes).hexdigest(),
          "operations": len(b), "operations_added": [], "operations_opt_in_expanded": sorted(changed),
          "unchanged_operations": len(b) - len(changed), "existing_schemas_unchanged": len(old["components"]["schemas"]),
          "schemas_added": added_schemas, "parameters_added": added_params, "permissions_and_security_unchanged": True,
          "routes": route_result}
args.report.write_text(json.dumps(report, indent=2) + "\n")
print(json.dumps(report, indent=2))
