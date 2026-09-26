#!/usr/bin/env python3
"""Reset only this synthetic local identity set; keep credentials out of process arguments/logs."""
import argparse
import json
import os
from pathlib import Path
import secrets
import subprocess

ROOT = Path(__file__).resolve().parents[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--reset', action='store_true', required=True)
    parser.add_argument('--frontend-env', type=Path, help='Ignored .env.e2e.local in the frontend repository')
    args = parser.parse_args()
    os.umask(0o077)
    private = ROOT / 'artifacts/local-e2e-private'
    if private.is_symlink():
        raise RuntimeError('Refusing symlinked private directory')
    private.mkdir(mode=0o700, exist_ok=True)
    private.chmod(0o700)
    manifest = private / 'manifest.json'
    if manifest.is_symlink():
        raise RuntimeError('Refusing symlinked fixture configuration')
    if not manifest.exists():
        values = {'run': secrets.token_hex(12), 'passwords': {
            label: 'E2E-' + secrets.token_hex(32) + '!aA9'
            for label in ['staff', 'enroll_staff', 'customer']}}
        with manifest.open('x') as stream:
            json.dump(values, stream)
    manifest.chmod(0o600)
    destination = args.frontend_env
    if destination is not None:
        destination = destination.absolute()
        if destination.name != '.env.e2e.local' or destination.is_symlink() or not (destination.parent / '.git').exists():
            raise RuntimeError('Expected the frontend ignored .env.e2e.local')
        subprocess.run(['git', '-C', str(destination.parent), 'check-ignore', '-q', '--', destination.name], check=True)
        if destination.exists() and b'# Generated synthetic local E2E identities.' not in destination.read_bytes():
            raise RuntimeError('Refusing to overwrite a frontend environment not owned by this fixture')
    environment = dict(os.environ, HOLOUL_APP_IMAGE='holoul-app:f1-e1-development', HOLOUL_APP_ENV='local',
                       HOLOUL_DEPLOYMENT_PROFILE='local-verification', HOLOUL_AI_ENABLED='false', HOLOUL_HTTPS_PORT='8443')
    subprocess.run(['docker', 'compose', 'run', '--rm', '--no-deps', '-T', '--env', 'HOLOUL_LOCAL_E2E=1',
                    '--volume', f'{private}:/local-e2e-private', 'app', 'php', 'tools/local-e2e/run.php'],
                   cwd=ROOT, env=environment, check=True)
    if destination is not None:
        data = (private / 'frontend.env').read_bytes()
        fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
        destination.chmod(0o600)
        print('Ignored frontend environment updated. No credentials printed.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.CalledProcessError):
        raise SystemExit('Local E2E setup failed; no credentials printed. Check the local profile and private files.')
