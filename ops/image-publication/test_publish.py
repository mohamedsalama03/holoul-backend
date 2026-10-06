"""Fail-closed tests; fixture identities never represent released images."""
import hashlib
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch

import publish as p

WORKFLOW = "1" * 40


def fixture(layout, architecture="amd64", revision=p.SOURCE, user="holoul", sbom=True, oci_artifact=False):
    (layout / "blobs/sha256").mkdir(parents=True)

    def put(data, media):
        content = json.dumps(data).encode() if isinstance(data, dict) else data
        value = hashlib.sha256(content).hexdigest()
        (layout / "blobs/sha256" / value).write_bytes(content)
        return {"digest": "sha256:" + value, "size": len(content), "mediaType": media}

    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as stream:
        member = tarfile.TarInfo("app/fixture.txt")
        member.size = 7
        stream.addfile(member, io.BytesIO(b"fixture"))
    layer = put(buffer.getvalue(), "application/vnd.oci.image.layer.v1.tar+gzip")
    # Realistic minimal config with an uncompressed diff_id for OCI transport smoke tests.
    with tarfile.open(fileobj=io.BytesIO(buffer.getvalue()), mode="r:gz"):
        import gzip
        uncompressed = gzip.decompress(buffer.getvalue())
    config = put({"architecture": architecture, "os": "linux",
                  "rootfs": {"type": "layers", "diff_ids": ["sha256:" + hashlib.sha256(uncompressed).hexdigest()]},
                  "config": {"User": user, "Entrypoint": ["fixture"], "Cmd": [],
                             "Labels": {"org.opencontainers.image.revision": revision,
                             "org.opencontainers.image.source": "https://github.com/" + p.REPOSITORY,
                             "org.opencontainers.image.title": "holoul-backend",
                             "org.opencontainers.image.created": "2026-10-06T00:00:00Z",
                             "ly.com.holoul.workflow.revision": WORKFLOW}}},
                 "application/vnd.oci.image.config.v1+json")
    runtime = put({"schemaVersion": 2, "mediaType": "application/vnd.oci.image.manifest.v1+json",
                   "config": config, "layers": [layer]}, "application/vnd.oci.image.manifest.v1+json")
    runtime["platform"] = {"os": "linux", "architecture": architecture}
    predicates = ["https://slsa.dev/provenance/v0.2"] + (["https://spdx.dev/Document"] if sbom else [])
    statements = [put({"_type": "https://in-toto.io/Statement/v0.1", "predicateType": kind,
                       "subject": [{"name": "fixture", "digest": {"sha256": runtime["digest"][7:]}}],
                       "predicate": {}}, "application/vnd.in-toto+json") for kind in predicates]
    att_config = put({} if oci_artifact else {"os": "unknown", "architecture": "unknown",
                      "rootfs": {"type": "layers", "diff_ids": []}, "config": {}},
                     "application/vnd.oci.image.config.v1+json")
    att_body = {"schemaVersion": 2, "mediaType": "application/vnd.oci.image.manifest.v1+json",
                "config": att_config, "layers": statements}
    if oci_artifact:
        att_body.update({"artifactType": "application/vnd.docker.attestation.manifest.v1+json", "subject": runtime})
    attestation = put(att_body, "application/vnd.oci.image.manifest.v1+json")
    attestation.update({"platform": {"os": "unknown", "architecture": "unknown"}, "annotations": {
        "vnd.docker.reference.type": "attestation-manifest", "vnd.docker.reference.digest": runtime["digest"]}})
    root = put({"schemaVersion": 2, "mediaType": "application/vnd.oci.image.index.v1+json",
                "manifests": [runtime, attestation]}, "application/vnd.oci.image.index.v1+json")
    (layout / "index.json").write_text(json.dumps({"schemaVersion": 2, "manifests": [root]}))
    (layout / "oci-layout").write_text('{"imageLayoutVersion":"1.0.0"}')
    return root, runtime, layer


class PublicationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.env = patch.dict(os.environ, {"WORKFLOW_SHA": WORKFLOW, "GITHUB_RUN_ID": "123",
                                          "GITHUB_RUN_ATTEMPT": "1"})
        self.env.start()

    def tearDown(self):
        self.env.stop()
        self.temp.cleanup()

    def inspect(self, **kwargs):
        layout = self.root / "layout"
        fixture(layout, **kwargs)
        return p.inspect_layout(layout, "backend")

    def test_valid_single_platform_and_attestations(self):
        result, _ = self.inspect()
        self.assertEqual("linux/amd64", result["platform"])
        self.assertEqual(2, len(result["attestation_predicates"]))
        self.assertEqual("holoul", result["image_user"])
        self.assertGreater(result["registry_blob_bytes_including_attestations"], 0)

    def test_wrong_platform_rejected(self):
        with self.assertRaisesRegex(RuntimeError, "wrong platform"):
            self.inspect(architecture="arm64")

    def test_modern_oci_attestation_with_empty_config(self):
        result, _ = self.inspect(oci_artifact=True)
        self.assertEqual(2, len(result["attestation_predicates"]))

    def test_wrong_source_label_rejected(self):
        with self.assertRaisesRegex(RuntimeError, "source revision"):
            self.inspect(revision=WORKFLOW)

    def test_root_backend_rejected(self):
        with self.assertRaisesRegex(RuntimeError, "runtime user"):
            self.inspect(user="0")

    def test_missing_sbom_rejected(self):
        with self.assertRaisesRegex(RuntimeError, "SBOM"):
            self.inspect(sbom=False)

    def test_corrupted_layer_rejected(self):
        layout = self.root / "layout"
        _, _, layer = fixture(layout)
        path = layout / "blobs/sha256" / layer["digest"][7:]
        value = path.read_bytes()
        path.write_bytes(b"!" + value[1:])
        with self.assertRaisesRegex(RuntimeError, "digest mismatch"):
            p.inspect_layout(layout, "backend")

    def test_multiple_outputs_rejected(self):
        layout = self.root / "layout"
        root, _, _ = fixture(layout)
        (layout / "index.json").write_text(json.dumps({"manifests": [root, root]}))
        with self.assertRaisesRegex(RuntimeError, "one OCI"):
            p.inspect_layout(layout, "backend")

    def test_wrong_workflow_label_rejected(self):
        layout = self.root / "layout"
        fixture(layout)
        with patch.dict(os.environ, {"WORKFLOW_SHA": "2" * 40}):
            with self.assertRaisesRegex(RuntimeError, "workflow label"):
                p.inspect_layout(layout, "backend")

    def test_secrets_sanitized_without_losing_failure(self):
        raw = {"SchemaVersion": 2, "Results": [{"Target": "private/token-file", "Secrets": [{
            "RuleID": "fixture-rule", "Severity": "HIGH", "StartLine": 1,
            "Match": "do-not-publish-this-value", "Code": {"Lines": ["also-private"]}}]}]}
        result = p.scan_report(raw, True)
        self.assertEqual(1, len(result))
        for forbidden in ("do-not-publish", "also-private", "private/token", "Match", "Code"):
            self.assertNotIn(forbidden, json.dumps(result))

    def test_high_critical_findings_retained(self):
        raw = {"SchemaVersion": 2, "Results": [{"Vulnerabilities": [{"VulnerabilityID": "CVE-fixture",
                "Severity": "HIGH", "PkgName": "fixture", "FixedVersion": "2"}]}]}
        self.assertEqual("CVE-fixture", p.scan_report(raw, False)[0]["VulnerabilityID"])

    def test_unknown_scan_report_rejected(self):
        with self.assertRaisesRegex(RuntimeError, "schema"):
            p.scan_report({}, False)

    def archive(self, name, members):
        path = self.root / name
        with tarfile.open(path, "w") as stream:
            for filename, content, kind in members:
                info = tarfile.TarInfo(filename)
                info.type = kind
                if kind == tarfile.REGTYPE:
                    info.size = len(content)
                    stream.addfile(info, io.BytesIO(content))
                else:
                    info.linkname = "/etc/passwd"
                    stream.addfile(info)
        return path

    def test_scan_includes_deleted_lower_layer_content(self):
        lower = self.archive("lower.tar", [("app/password", b"old-value", tarfile.REGTYPE)])
        upper = self.archive("upper.tar", [("app/.wh.password", b"", tarfile.REGTYPE)])
        p.extract_regular_files(lower, self.root / "layers/0")
        p.extract_regular_files(upper, self.root / "layers/1")
        self.assertEqual(b"old-value", (self.root / "layers/0/app/password").read_bytes())

    def test_no_symlinks_materialized(self):
        archive = self.archive("links.tar", [("escape", b"", tarfile.SYMTYPE),
                                             ("regular", b"hello", tarfile.REGTYPE)])
        p.extract_regular_files(archive, self.root / "files")
        self.assertFalse((self.root / "files/escape").exists())
        self.assertEqual(b"hello", (self.root / "files/regular").read_bytes())

    def test_path_traversal_rejected(self):
        archive = self.archive("escape.tar", [("../outside", b"bad", tarfile.REGTYPE)])
        with self.assertRaisesRegex(RuntimeError, "unsafe layer path"):
            p.extract_regular_files(archive, self.root / "files")

    def records(self):
        for image in p.IMAGES:
            name = "ghcr.io/mohamedsalama03/holoul-" + image
            record = {"source_sha": p.SOURCE, "workflow_sha": WORKFLOW, "run_id": "123", "run_attempt": "1",
                      "image": name, "published_verified": True, "registry_pull_verified": True,
                      "scan": {"passed": True}, "ghcr_visibility": "private",
                      "immutable_reference": name + "@sha256:" + "3" * 64,
                      "inspection": {"manifest_digest": "sha256:" + "3" * 64, "platform": "linux/amd64"}}
            p.write_json(self.root / (image + ".json"), record)

    def change_record(self, field, value):
        path = self.root / "redis.json"
        record = json.loads(path.read_text())
        record[field] = value
        p.write_json(path, record)

    def test_complete_verified_evidence_can_be_aggregated(self):
        self.records()
        self.assertEqual(6, len(p.inventory_records(self.root)))

    def test_missing_image_blocks_inventory(self):
        self.records()
        (self.root / "redis.json").unlink()
        with self.assertRaises(FileNotFoundError):
            p.inventory_records(self.root)

    def test_unverified_publication_blocks_inventory(self):
        self.records()
        self.change_record("published_verified", False)
        with self.assertRaisesRegex(RuntimeError, "unverified image"):
            p.inventory_records(self.root)

    def test_mixed_run_blocks_inventory(self):
        self.records()
        self.change_record("run_attempt", "2")
        with self.assertRaisesRegex(RuntimeError, "mixed run"):
            p.inventory_records(self.root)

    def test_workflow_source_cannot_replace_application_source(self):
        self.records()
        self.change_record("source_sha", WORKFLOW)
        with self.assertRaisesRegex(RuntimeError, "mixed source"):
            p.inventory_records(self.root)

    def test_tag_cannot_replace_digest(self):
        self.records()
        self.change_record("immutable_reference", "ghcr.io/mohamedsalama03/holoul-redis:sha-tag")
        with self.assertRaisesRegex(RuntimeError, "registry identity"):
            p.inventory_records(self.root)

    def test_public_package_blocks_inventory(self):
        self.records()
        self.change_record("ghcr_visibility", "public")
        with self.assertRaisesRegex(RuntimeError, "unverified image"):
            p.inventory_records(self.root)

    def test_failed_scan_blocks_inventory(self):
        self.records()
        self.change_record("scan", {"passed": False})
        with self.assertRaisesRegex(RuntimeError, "unverified image"):
            p.inventory_records(self.root)

    def test_source_input_rejects_empty_short_and_different(self):
        for value in ("", p.SOURCE[:12], "release/unified-vps-candidate", WORKFLOW):
            with self.subTest(value=value), patch.dict(os.environ, {"SOURCE_SHA": value}):
                with self.assertRaisesRegex(RuntimeError, "frozen full SHA"):
                    p.context(self.root)

    def test_build_selects_runtime_and_frozen_context(self):
        source = self.root / "source"
        inspection = {"manifest_digest": "sha256:" + "4" * 64}
        (self.root / "build-metadata.json").write_text(json.dumps({"containerimage.digest": inspection["manifest_digest"]}))
        with patch.dict(os.environ, {"GH_TOKEN": ""}), patch.object(p, "run") as run, patch.object(p, "inspect_layout", return_value=(inspection, {})):
            p.build(source, self.root, "backend")
        command = run.call_args.args[0]
        self.assertEqual("runtime", command[command.index("--target") + 1])
        self.assertEqual(str(source), command[-1])
        self.assertNotIn("--push", command)
        self.assertNotIn("--build-arg", command)

    def test_registry_token_not_allowed_during_build_or_scan(self):
        with patch.dict(os.environ, {"GH_TOKEN": "synthetic-marker"}):
            for method in (p.build, p.scan):
                with self.assertRaisesRegex(RuntimeError, "token must not"):
                    method(self.root, self.root, "backend")


if __name__ == "__main__":
    unittest.main()
