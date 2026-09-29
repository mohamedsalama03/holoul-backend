#!/usr/bin/env python3
"""Start isolated candidate test infrastructure without replacing the shared 8443 runtime."""
import json, subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
BASE=['docker','compose','-p','holoul-public-verify','-f',str(ROOT/'compose.public-verify.yaml')]
subprocess.run(BASE+['up','-d','--wait','--wait-timeout','60','public-verify-postgres','public-verify-redis','portfolio-processor'],check=True)
def address(name):
    value=json.loads(subprocess.check_output(['docker','inspect',name]))[0]
    return value['NetworkSettings']['Networks']['holoul_internal']['IPAddress']
# /etc/hosts selects only the isolated endpoints while preserving the exact local
# hostnames required by the inherited startup-security tests. No shared aliases.
override={'services':{'verify':{'environment':{'DB_HOST':'postgres','REDIS_HOST':'redis','MAIL_SCHEME':'smtp'},
    'extra_hosts':{'postgres':address('holoul-public-verify-public-verify-postgres-1'),'redis':address('holoul-public-verify-public-verify-redis-1')}}}}
private=ROOT/'artifacts/public-services-private';private.mkdir(mode=0o700,parents=True,exist_ok=True)
file=private/'verification-network.json';file.write_text(json.dumps(override,indent=2));file.chmod(0o600)
subprocess.run(BASE+['-f',str(file),'up','-d','--no-deps','verify'],check=True)
print('Isolated holoul_test verification container ready; shared 8443 stack unchanged.')
