#!/usr/bin/env python3
"""Regenerate the portable Swagger reference from the authoritative contract.

Requires Python 3 and PyYAML. No application connection or credentials are used.
"""
import hashlib
import json
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / 'docs' / 'swagger'
SOURCE = ROOT / 'docs' / 'openapi.json'
BASELINE = 'e3957df723ea01a6005feaec90d8130a26c93b62'
BASELINE_SHA256 = 'fafbc8b62976788dae0cc98492add8bed014228ad42844d2c7498ca57d1a7f4e'


def main():
    raw = SOURCE.read_bytes()
    if hashlib.sha256(raw).hexdigest() != BASELINE_SHA256:
        raise SystemExit('Contract changed: review and update the reference baseline, identity and guide before rebuilding.')
    spec = json.loads(raw)
    assert spec['openapi'] == '3.1.1'
    operations = [operation for path in spec['paths'].values()
                  for method, operation in path.items()
                  if method in {'get', 'put', 'post', 'delete', 'patch', 'head', 'options', 'trace'}]
    metadata = {
        'version': spec['info']['version'],
        'openapi': spec['openapi'],
        'sha256': hashlib.sha256(raw).hexdigest(),
        'operations': len(operations),
        'schemas': len(spec['components']['schemas']),
        'baseline': BASELINE,
        'swagger_ui': '5.32.11',
        'mode': 'read-only-reference',
    }
    assert len({op['operationId'] for op in operations}) == len(operations)
    for name in ['index.html', 'assets/reference.css', 'assets/reference.js', 'assets/vendor/swagger-ui-bundle.js']:
        assert (OUTPUT / name).stat().st_size > 0, name
    (OUTPUT / 'openapi.json').write_bytes(raw)
    yaml_text = yaml.safe_dump(spec, sort_keys=False, allow_unicode=True, width=100)
    assert yaml.safe_load(yaml_text) == spec
    (OUTPUT / 'openapi.yaml').write_text(yaml_text, encoding='utf-8')
    (OUTPUT / 'assets/contract.js').write_text(
        '// Generated from docs/openapi.json; do not edit.\n'
        + 'window.HOLOUL_REFERENCE = ' + json.dumps(metadata, ensure_ascii=True) + ';\n'
        + 'window.HOLOUL_CONTRACT = ' + json.dumps(spec, ensure_ascii=True, separators=(',', ':')) + ';\n',
        encoding='utf-8')
    (OUTPUT / 'contract-metadata.json').write_text(json.dumps(metadata, indent=2) + '\n', encoding='utf-8')
    files = sorted(path for path in OUTPUT.rglob('*') if path.is_file()
                   and path.name != 'SHA256SUMS' and '.impeccable' not in path.parts)
    manifest = ''.join(f'{hashlib.sha256(path.read_bytes()).hexdigest()}  {path.relative_to(OUTPUT).as_posix()}\n'
                       for path in files)
    (OUTPUT / 'SHA256SUMS').write_text(manifest, encoding='utf-8')
    print(json.dumps(metadata, indent=2))


if __name__ == '__main__':
    main()
