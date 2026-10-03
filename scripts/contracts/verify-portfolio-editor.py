#!/usr/bin/env python3
"""Prove the candidate differs from 1.6.2 only by its recorded role extension."""
import copy
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
delta = json.loads((ROOT / 'docs/contracts/portfolio-editor-delta.json').read_text())
assert delta['baseline_sha256'] == '32fa258873794513849eb773a4c7bf41e4e2f8dd66ec7ef530877b775284386e'
raw = (ROOT / 'docs/openapi.json').read_bytes()
current = json.loads(raw)
restored = copy.deepcopy(current)
for change in delta['changes']:
    node = restored
    for part in change['path'][:-1]:
        node = node[part]
    key = change['path'][-1]
    assert node[key] == change['after'], '/'.join(change['path'])
    node[key] = change['before']
canonical = json.dumps(restored, sort_keys=True, separators=(',', ':')).encode()
assert hashlib.sha256(canonical).hexdigest() == delta['baseline_canonical_sha256'], 'Unrecorded contract change'
print(json.dumps({
    'previous_sha256': delta['baseline_sha256'],
    'new_sha256': hashlib.sha256(raw).hexdigest(),
    'operations_added': [],
    'schemas_added': [],
    'schemas_changed': sorted({c['path'][2] for c in delta['changes'] if c['path'][:2] == ['components', 'schemas']}),
    'unrecorded_changes': 0,
}, indent=2))
