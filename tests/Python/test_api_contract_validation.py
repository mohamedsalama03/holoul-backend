"""Prove the contract gate rejects realistic response drift, using synthetic samples."""
import contextlib
import copy
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts/contracts"))
from validate import validate  # noqa: E402


class ContractGateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.samples = [json.loads(line) for line in Path(os.environ["HOLOUL_CONTRACT_SAMPLES"]).read_text().splitlines()]

    def assert_rejected(self, select, mutate, expected_issue):
        samples = copy.deepcopy(self.samples)
        target = next(sample for sample in samples if select(sample))
        mutate(target)
        with tempfile.TemporaryDirectory(prefix="holoul-contract-") as directory:
            source, report = Path(directory) / "samples.jsonl", Path(directory) / "report.json"
            source.write_text("".join(json.dumps(sample) + "\n" for sample in samples))
            with contextlib.redirect_stdout(io.StringIO()), self.assertRaises(AssertionError):
                validate(source, report)
            failures = json.loads(report.read_text())["failures"]
            self.assertTrue(any(expected_issue in item["issue"] for item in failures))

    def test_extra_internal_field_in_success_is_rejected(self):
        self.assert_rejected(lambda s: s["path"] == "/api/v1" and s["status"] == 200,
                             lambda s: s["body"].update(storage_key="synthetic-private-marker"), "additionalProperties")

    def test_missing_success_etag_is_rejected(self):
        self.assert_rejected(lambda s: s["status"] == 200 and "etag" in s["headers"],
                             lambda s: s["headers"].pop("etag"), "missing response header: ETag")

    def test_content_in_204_is_rejected(self):
        self.assert_rejected(lambda s: s["status"] == 204,
                             lambda s: s.update(body_length=1), "204 must be bodyless")

    def test_html_in_place_of_binary_download_is_rejected(self):
        self.assert_rejected(lambda s: s["status"] == 200 and s["path"].endswith("/download") and not s["json"],
                             lambda s: s["headers"].update({"content-type": ["text/html"]}), "undocumented binary media")


if __name__ == "__main__":
    unittest.main()
