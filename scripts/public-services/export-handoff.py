#!/usr/bin/env python3
"""Export a separate offline Swagger package; accepted older reference/frontends stay intact."""
import argparse,hashlib,json,shutil,subprocess,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
parser=argparse.ArgumentParser();parser.add_argument('--output',type=Path,required=True);args=parser.parse_args()
output=args.output.resolve();output.mkdir(parents=True,exist_ok=True)
raw=(ROOT/'docs/openapi.json').read_bytes();spec=json.loads(raw);sha=hashlib.sha256(raw).hexdigest()
assert sha==json.loads((ROOT/'docs/public-services/evidence/contract-compatibility-final.json').read_text())['new_sha256']
operations=[op for item in spec['paths'].values() for method,op in item.items() if method in {'get','post','put','patch','delete','head','options'}]
assert len(operations)==202
shutil.copytree(ROOT/'docs/swagger/assets',output/'assets',dirs_exist_ok=True)
html=(ROOT/'docs/swagger/index.html').read_text().replace('Includes G1 intake, staff onboarding and customer registration sorting.','Includes G1 intake, staff onboarding, customer registration sorting, PublicPortfolio and Contact.')
html=html.replace('Candidate baseline: <code>e3957df723ea01a6005feaec90d8130a26c93b62</code>','Application baseline: <code>e3957df723ea01a6005feaec90d8130a26c93b62</code><br>Public services proposal R2: <code>0f3be1e4c1ec31a6faed7a4d71cfbb4a02e3003d</code>')
html=html.replace('<span id="footer-count">182</span>','<span id="footer-count">202</span>')
html=html.replace('Public services proposal R2: <code>0f3be1e4c1ec31a6faed7a4d71cfbb4a02e3003d</code>',
 'Public services proposal R2: <code>0f3be1e4c1ec31a6faed7a4d71cfbb4a02e3003d</code><br>Implementation: <code>94d48368a2a3722ed3d147e02b0875e266844d6e</code><br>Local runtime checkpoint: <code>7c9de8d5eaaf30fc4838445132b5679f495979cb</code>')
(output/'index.html').write_text(html)
metadata={'version':spec['info']['version'],'openapi':spec['openapi'],'sha256':sha,'operations':len(operations),'schemas':len(spec['components']['schemas']),
 'baseline':'e3957df723ea01a6005feaec90d8130a26c93b62','swagger_ui':'5.32.11','mode':'read-only-reference'}
(output/'openapi.json').write_bytes(raw)
yaml=subprocess.check_output(['docker','run','--rm','--network','none','-v',str(ROOT)+':/work:ro','holoul-contracts:b8-p3','-c',
 'import json,yaml; print(yaml.safe_dump(json.load(open("/work/docs/openapi.json")),sort_keys=False,allow_unicode=True,width=100),end="")'])
(output/'openapi.yaml').write_bytes(yaml)
(output/'assets/contract.js').write_text('// Generated offline contract; no API credentials.\nwindow.HOLOUL_REFERENCE = '+json.dumps(metadata)+';\nwindow.HOLOUL_CONTRACT = '+json.dumps(spec,ensure_ascii=True,separators=(',',':'))+';\n')
(output/'contract-metadata.json').write_text(json.dumps(metadata,indent=2)+'\n')
shutil.copyfile(ROOT/'docs/swagger/THIRD-PARTY-NOTICES.md',output/'THIRD-PARTY-NOTICES.md')
for name in ['CHANGELOG.md','response-examples.json','TAXONOMY-AND-LEGACY-CUTOVER-AR.md','VERIFICATION.md','HANDOFF-AR.md','CONTACT-RESTORE-PROCEDURE.md','FRONTEND-INTEGRATION-AR.md']:
 source=ROOT/'docs/public-services'/name
 if source.exists():shutil.copyfile(source,output/name)
shutil.copyfile(ROOT/'docs/public-services/SWAGGER-README-AR.md',output/'README.md')
# Curated, sanitised proof only. Raw authentication captures, fixture environments and backups remain private.
proof=output/'evidence';proof.mkdir(exist_ok=True)
for name in ['contract-compatibility-final.json','contract-final.json','contract-negative-tests.txt','phpunit-summary.json','phpunit-subsets.json',
 'full-phpunit-third.xml','historical-upgrade-final.xml','phpstan-final.txt','pint-final.txt','composer-validate.txt','composer-audit.json',
 'processor-tests.txt','route-contract-drift-final.json','runtime-source-manifest-match.json','runtime-local-https.json','local-runtime-final.json',
 'local-runtime-config.json','local-history-before.json','local-history-after-migration.json','local-pre-upgrade-backup.json',
 'frontend-final.json','frontend-preserved.json','frontend-first-skipped.json','inherited-security-tests-preserved.json',
 'taxonomy-local-apply.json','taxonomy-local-repeat.json','B8-TAXONOMY-INVENTORY-LOCAL.json','B8-TAXONOMY-AFTER-LOCAL.json','legacy-portfolio-inventory.json']:
 shutil.copyfile(ROOT/'docs/public-services/evidence'/name,proof/name)

files=sorted(f for f in output.rglob('*') if f.is_file() and f.name!='SHA256SUMS')
(output/'SHA256SUMS').write_text(''.join(hashlib.sha256(f.read_bytes()).hexdigest()+'  '+f.relative_to(output).as_posix()+'\n' for f in files))
archive=output.with_name(output.name+'.zip')
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as package:
 for f in sorted(output.rglob('*')):
  if f.is_file():package.write(f,output.name+'/'+f.relative_to(output).as_posix())
print(json.dumps({'directory':str(output),'zip':str(archive),'contract_sha256':sha,'operations':202,'schemas':250}))
