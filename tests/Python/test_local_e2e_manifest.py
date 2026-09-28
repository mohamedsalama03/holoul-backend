"""Local fixture migration must preserve account ownership and private credentials."""
import importlib.util
import json
import os
from pathlib import Path
import secrets
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location('local_e2e', Path(__file__).resolve().parents[2] / 'scripts/local-e2e.py')
fixture = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(fixture)


class LocalManifestTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.addCleanup(self.temporary.cleanup)

    def legacy(self):
        value = {'run': secrets.token_hex(12), 'passwords': {
            k: 'E2E-' + secrets.token_hex(32) + '!aA9' for k in fixture.LABELS[:3]}}
        path = self.root / 'manifest.json'
        path.write_text(json.dumps(value))
        path.chmod(0o600)
        return value

    def test_upgrade_preserves_existing_identities_and_credentials_and_is_idempotent(self):
        previous = self.legacy()
        fixture.prepare_manifest(self.root)
        path = self.root / 'manifest.json'
        upgraded = json.loads(path.read_text())
        self.assertTrue(upgraded['run'] == previous['run'])
        self.assertTrue(all(upgraded['passwords'][k] == v for k, v in previous['passwords'].items()))
        self.assertEqual(list(upgraded['passwords']), fixture.LABELS)
        self.assertEqual(len(set(upgraded['passwords'].values())), 5)
        self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        before = path.read_bytes()
        fixture.prepare_manifest(self.root)
        self.assertTrue(before == path.read_bytes())

    def test_new_manifest_is_complete_and_private(self):
        fixture.prepare_manifest(self.root)
        path = self.root / 'manifest.json'
        value = json.loads(path.read_text())
        self.assertEqual(list(value['passwords']), fixture.LABELS)
        self.assertEqual(path.stat().st_mode & 0o777, 0o600)

    def test_unsafe_or_unowned_manifest_is_rejected_without_rewrite(self):
        value = self.legacy()
        path = self.root / 'manifest.json'
        for bad in [dict(value, email='real@example.test'),
                    dict(value, run='../foreign'),
                    dict(value, passwords=dict.fromkeys(fixture.LABELS[:3], value['passwords']['staff']))]:
            path.write_text(json.dumps(bad))
            before = path.read_bytes()
            with self.assertRaises(RuntimeError):
                fixture.prepare_manifest(self.root)
            self.assertTrue(before == path.read_bytes())
        path.chmod(0o644)
        with self.assertRaises(RuntimeError):
            fixture.prepare_manifest(self.root)

    def test_symlink_is_rejected(self):
        target = self.root / 'foreign.json'
        target.write_text('{}')
        (self.root / 'manifest.json').symlink_to(target)
        with self.assertRaises(RuntimeError):
            fixture.prepare_manifest(self.root)
        self.assertEqual(target.read_text(), '{}')


if __name__ == '__main__':
    unittest.main()
