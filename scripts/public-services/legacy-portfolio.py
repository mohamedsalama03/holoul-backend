#!/usr/bin/env python3
"""Read-only legacy inventory; explicit frozen export. Never reads access-key.txt."""
import argparse, hashlib, json, shutil, uuid
from pathlib import Path

def inventory(source):
    source = source.resolve(strict=True)
    raw = (source / 'projects.json').read_bytes()
    if len(raw) > 5 * 1024 * 1024:
        raise ValueError('Manifest too large')
    projects = json.loads(raw)
    if not isinstance(projects, list) or len(projects) > 1000:
        raise ValueError('Expected a bounded project list')
    images, ids = [], set()
    for project in projects:
        if uuid.UUID(project['id']).version != 4 or project['id'] in ids:
            raise ValueError('Invalid/duplicate legacy project identity')
        ids.add(project['id'])
        for image in project['images']:
            ident = image['id']
            if uuid.UUID(ident).version != 4 or any(x['id'] == ident for x in images):
                raise ValueError('Invalid/duplicate legacy image identity')
            file = source / 'images' / (ident + '.webp')
            if file.is_symlink() or file.resolve().parent != (source / 'images').resolve():
                raise ValueError('Image path escapes source')
            data = file.read_bytes()
            if not 0 < len(data) <= 5 * 1024 * 1024:
                raise ValueError('Image size exceeds approved bound')
            images.append({'id': ident, 'byte_size': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
    if (source / 'projects.json').read_bytes() != raw:
        raise ValueError('Source changed during inventory')
    return {'format': 1, 'source_sha256': hashlib.sha256(raw).hexdigest(), 'projects': projects, 'images': images}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('source', type=Path)
    parser.add_argument('--inventory', type=Path, required=True)
    parser.add_argument('--bundle', type=Path)
    parser.add_argument('--writes-frozen', action='store_true')
    args = parser.parse_args()
    data = inventory(args.source)
    summary = {k: v for k, v in data.items() if k != 'projects'}
    summary['projects'] = [{'id': p['id'], 'status': p['status'], 'image_ids': [i['id'] for i in p['images']]} for p in data['projects']]
    summary['published_count'] = sum(p['status'] == 'published' for p in data['projects'])
    summary['draft_count'] = len(data['projects']) - summary['published_count']
    summary['write_freeze_confirmed'] = args.writes_frozen
    args.inventory.write_text(json.dumps(summary, ensure_ascii=False, indent=2) + '\n')
    if args.bundle:
        if not args.writes_frozen:
            raise ValueError('Consistent export requires the reviewed legacy write freeze')
        args.bundle.mkdir(mode=0o700, parents=True, exist_ok=False)
        (args.bundle / 'images').mkdir(mode=0o700)
        for image in data['images']:
            target = args.bundle / 'images' / (image['id'] + '.webp')
            shutil.copyfile(args.source / 'images' / target.name, target)
            target.chmod(0o600)
            if hashlib.sha256(target.read_bytes()).hexdigest() != image['sha256']:
                raise ValueError('Source image changed during export; reject this bundle')
        if inventory(args.source) != data:
            raise ValueError('Source changed during export; reject this bundle')
        manifest = args.bundle / 'manifest.json'
        manifest.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n')
        manifest.chmod(0o600)
    print(json.dumps({'projects': len(data['projects']), 'images': len(data['images']), 'bundle_written': bool(args.bundle)}))

if __name__ == '__main__':
    main()
