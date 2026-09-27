#!/usr/bin/env python3
"""Offline OpenAPI + JSON Schema validation over real PHPUnit HTTP responses."""
import argparse
import collections
import copy
import json
import re
from pathlib import Path

from jsonschema import Draft202012Validator, FormatChecker
from openapi_spec_validator import OpenAPIV31SpecValidator
from referencing import Registry, Resource

from build import ERRORS, METHODS, ROOT, build, encoded, matrix, source_manifest


def dereference(spec, node):
    while "$ref" in node:
        assert node["$ref"].startswith("#/"), "Only offline local contract references are allowed"
        value = spec
        for key in node["$ref"][2:].split("/"):
            value = value[key.replace("~1", "/").replace("~0", "~")]
        node = value
    return node


def validate(samples_path, report_path):
    spec = json.loads((ROOT / "docs/openapi.json").read_text())
    assert spec == build(), "Generated OpenAPI differs from reviewed fragments"
    assert (ROOT / "docs/API-ENDPOINT-MATRIX.md").read_text() == matrix(spec), "Endpoint matrix drift"
    assert json.loads((ROOT / "docs/contracts/source-manifest.json").read_text()) == source_manifest(), "Unreviewed implementation changes"
    def offline_refs(node):
        if isinstance(node, dict):
            if "$ref" in node:
                assert node["$ref"].startswith("#/"), "Remote contract references are forbidden"
            for value in node.values():
                offline_refs(value)
        elif isinstance(node, list):
            for value in node:
                offline_refs(value)
    offline_refs(spec)
    OpenAPIV31SpecValidator(spec).validate()
    # Give JSON Schema an explicit dialect/root so all local component refs stay offline.
    schema_root = {**spec, "$schema": "https://json-schema.org/draft/2020-12/schema"}
    registry = Registry().with_resource("urn:holoul:contract", Resource.from_contents(schema_root))

    def validator(schema):
        return Draft202012Validator({"$ref": "urn:holoul:contract", **schema_root, **schema}, registry=registry,
                                    format_checker=FormatChecker(formats=["uuid", "date-time", "date", "email"]))

    operations = {}
    operation_ids = set()
    for path, item in spec["paths"].items():
        assert path.startswith("/api/v1") or path == "/sanctum/csrf-cookie", path
        for method, op in item.items():
            if method not in METHODS:
                continue
            key = method.upper() + " " + path
            assert op["operationId"] not in operation_ids, "Duplicate operationId"
            operation_ids.add(op["operationId"])
            for field in ("security", "x-personas", "x-permissions", "x-frontend-feature", "x-source"):
                assert field in op, (key, field)
            assert op["x-source"] and all((ROOT / source).is_file() for source in op["x-source"]), key
            for status, response in op["responses"].items():
                response = dereference(spec, response)
                if status.startswith("2") and status != "204":
                    assert response.get("content"), "Missing success body: " + key
                    for media in response["content"].values():
                        assert media.get("schema"), "Placeholder response schema: " + key
            parameters = [dereference(spec, x) for x in item.get("parameters", []) + op.get("parameters", [])]
            assert {x["name"] for x in parameters if x["in"] == "path"} == set(re.findall(r"{([^}]+)}", path)), key
            operations[key] = op
    matchers = [(re.compile("^" + re.sub(r"\\\{[^}]+\\\}", "[^/]+", re.escape(key)) + "$"), key)
                for key in sorted(operations, key=lambda k: (k.count("{"), k))]
    covered = collections.Counter()
    statuses = collections.Counter()
    success_covered = set()
    failures = []
    samples = 0
    unknown_successes = set()
    error_validator = validator({"$ref": "#/components/schemas/ErrorEnvelope"})
    for line in Path(samples_path).read_text().splitlines():
        sample = json.loads(line)
        status = str(sample["status"])
        actual = sample["method"] + " " + sample["path"]
        key = next((key for pattern, key in matchers if pattern.fullmatch(actual)), None)
        if sample["status"] >= 400 and not sample["json"]:
            failures.append({"operation": key or "test-only error route", "test": sample["test"], "status": status, "issue": "error must use JSON envelope"})
            continue
        if sample["json"] and sample["status"] >= 400:
            # New versioned operations may have a separately reviewed error
            # schema; legacy operations and test-only routes retain the exact
            # original envelope. Never relax the global error validator.
            schema_validator = error_validator
            if key and status in operations[key]["responses"]:
                response = dereference(spec, operations[key]["responses"][status])
                schema = response.get("content", {}).get("application/json", {}).get("schema")
                assert schema, (key, status, "Missing documented error schema")
                schema_validator = validator(schema)
            if sample["status"] in ERRORS:
                if sample["body"].get("error", {}).get("code") != ERRORS[sample["status"]][1]:
                    failures.append({"operation": key or "test-only error route", "test": sample["test"], "status": status, "issue": "status/code mismatch"})
            if sample["body"].get("request_id") != sample["headers"].get("x-request-id", [None])[0]:
                failures.append({"operation": key or "test-only error route", "test": sample["test"], "status": status, "issue": "request_id/header mismatch"})
        elif key:
            op = operations[key]
            if status not in op["responses"]:
                failures.append({"operation": key, "test": sample["test"], "status": status, "issue": "undocumented status"})
                continue
            response = dereference(spec, op["responses"][status])
            for name, header in response.get("headers", {}).items():
                header = dereference(spec, header)
                values = sample["headers"].get(name.lower())
                # Documented success headers are required unless explicitly optional.
                if header.get("x-optional", False):
                    continue
                if not values:
                    failures.append({"operation": key, "test": sample["test"], "status": status, "issue": "missing response header: " + name})
                elif "schema" in header:
                    for header_error in validator(header["schema"]).iter_errors(values[0]):
                        failures.append({"operation": key, "test": sample["test"], "status": status, "issue": "header schema: " + name + ":" + header_error.validator})
            if status == "204" or not sample["json"]:
                if status == "204":
                    if sample.get("body_length") != 0 or sample["json"]:
                        failures.append({"operation": key, "test": sample["test"], "status": status, "issue": "204 must be bodyless"})
                else:
                    content_type = sample["headers"].get("content-type", [""])[0].split(";")[0]
                    if content_type not in response.get("content", {}):
                        failures.append({"operation": key, "test": sample["test"], "status": status, "issue": "undocumented binary media"})
                schema_validator = None
            else:
                media = response.get("content", {}).get("application/json")
                if not media:
                    failures.append({"operation": key, "test": sample["test"], "status": status, "issue": "undocumented JSON body"})
                    continue
                schema_validator = validator(media["schema"])
            if sample["status"] < 300:
                success_covered.add(key)
        else:
            if sample["status"] < 300:
                unknown_successes.add(actual)
            continue
        samples += 1
        statuses[status] += 1
        if key:
            covered[key] += 1
            if status not in operations[key]["responses"]:
                failures.append({"operation": key, "test": sample["test"], "status": status, "issue": "undocumented error status"})
        if schema_validator:
            for error in schema_validator.iter_errors(sample["body"]):
                # Never include response values (even synthetic MFA/recovery tokens) in reports.
                failures.append({"operation": key or "test-only error route", "test": sample["test"], "status": status,
                                 "instance_path": "/".join(map(str, error.absolute_path)),
                                 "schema_path": "/".join(map(str, error.absolute_schema_path)), "issue": error.validator})
    # Demonstrate that the checker rejects internal-field leakage and missing required fields.
    sample_error = spec["components"]["responses"]["NotFound"]["content"]["application/json"]["example"]
    assert error_validator.is_valid(sample_error)
    mutated = copy.deepcopy(sample_error)
    mutated["storage_key"] = "synthetic-private-marker"
    assert not error_validator.is_valid(mutated), "Leakage sentinel was accepted"
    mutated = copy.deepcopy(sample_error)
    del mutated["request_id"]
    assert not error_validator.is_valid(mutated), "Missing correlation sentinel was accepted"
    staff_error_validator = validator({"$ref": "#/components/schemas/StaffErrorEnvelope"})
    reason_error = copy.deepcopy(sample_error)
    reason_error["error"]["reason"] = "NOT_FOUND"
    assert staff_error_validator.is_valid(reason_error), "Reviewed staff reason rejected"
    assert not error_validator.is_valid(reason_error), "Legacy errors were silently extended"
    reason_error["error"]["reason"] = "arbitrary internal exception"
    assert not staff_error_validator.is_valid(reason_error), "Unreviewed reason accepted"
    reason_error["error"]["reason"] = "NOT_FOUND"
    reason_error["error"]["token"] = "synthetic-secret-marker"
    assert not staff_error_validator.is_valid(reason_error), "Staff secret leakage accepted"
    assert samples > 0 and success_covered, "No actual API responses were recorded"
    expected_coverage = set(json.loads((ROOT / "docs/contracts/required-response-coverage.json").read_text()))
    assert expected_coverage and expected_coverage <= operations.keys(), "Invalid reviewed response coverage inventory"
    missing_coverage = sorted(expected_coverage - success_covered)
    report = {"openapi": spec["openapi"], "documented_operations": len(operations),
              "validated_responses": samples, "observed_operations": len(covered),
              "success_covered_operations": len(success_covered), "status_counts": dict(sorted(statuses.items())),
              "success_coverage": sorted(success_covered), "no_success_sample": sorted(operations.keys() - success_covered),
              "undocumented_successes": sorted(unknown_successes), "negative_validator_sentinels": 5, "positive_validator_sentinels": 1,
              "required_success_coverage": len(expected_coverage), "missing_required_success_samples": missing_coverage,
              "failures": failures, "passed": not failures and not unknown_successes and not missing_coverage}
    Path(report_path).write_text(encoded(report))
    print(encoded({k: v for k, v in report.items() if k not in {"failures", "success_coverage", "no_success_sample"}}))
    if failures:
        print(encoded(failures[:30]))
    assert report["passed"], f"Contract verification failed: {len(failures)} schema/status failures"


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--samples", required=True)
    parser.add_argument("--report", required=True)
    args = parser.parse_args()
    validate(args.samples, args.report)
