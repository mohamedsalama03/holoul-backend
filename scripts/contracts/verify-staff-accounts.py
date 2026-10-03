#!/usr/bin/env python3
"""Verify the candidate adds operations without altering the accepted wire schemas."""
import hashlib
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASE = '9119a094da23cc23c93475be79d076c4c1f2de21'
before_bytes = subprocess.check_output(['git', 'show', f'{BASE}:docs/openapi.json'], cwd=ROOT)
after_bytes = (ROOT / 'docs/openapi.json').read_bytes()
before, after = json.loads(before_bytes), json.loads(after_bytes)
for path, operations in before['paths'].items():
    for method, operation in operations.items():
        assert after['paths'][path][method] == operation, f'Existing operation changed: {method} {path}'
for kind, values in before['components'].items():
    for name, value in values.items():
        assert after['components'][kind][name] == value, f'Existing component changed: {kind}/{name}'
report = {
    'baseline': BASE,
    'previous_sha256': hashlib.sha256(before_bytes).hexdigest(),
    'new_sha256': hashlib.sha256(after_bytes).hexdigest(),
    'operations_added': [op['operationId'] for p, ops in after['paths'].items() for m, op in ops.items()
                         if m not in before['paths'].get(p, {})],
    'schemas_added': sorted(set(after['components']['schemas']) - set(before['components']['schemas'])),
    'existing_operations_and_components_unchanged': True,
}
print(json.dumps(report, indent=2))
