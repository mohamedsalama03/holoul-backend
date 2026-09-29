#!/usr/bin/env python3
"""Export only real synthetic responses for the 20 new operations, never auth captures."""
import argparse,hashlib,json,re
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
parser=argparse.ArgumentParser();parser.add_argument('--runtime',type=Path,required=True);parser.add_argument('--focused',type=Path,required=True);args=parser.parse_args()
raw=(ROOT/'docs/openapi.json').read_bytes();spec=json.loads(raw)
baseline=json.loads((ROOT/'docs/public-services/baseline-1.3.0.openapi.json').read_text())
methods={'get','post','put','patch','delete','head','options'}
old={op['operationId'] for item in baseline['paths'].values() for method,op in item.items() if method in methods}
new=[(method.upper(),path,op['operationId']) for path,item in spec['paths'].items() for method,op in item.items() if method in methods and op['operationId'] not in old]
assert len(new)==20
runtime=[json.loads(line) for line in args.runtime.read_text().splitlines()]
focused=[json.loads(line) for line in args.focused.read_text().splitlines()]
safe_headers={'content-type','cache-control','x-request-id','etag','x-content-type-options','retry-after'}
def match(sample):
 for method,path,name in new:
  if method==sample['method'] and re.fullmatch(re.sub(r'\{[^}]+\}',r'[^/]+',path),sample['path'].split('?')[0]):return path,name
 return None
def clean(sample,label=None):
 path,name=match(sample)
 body=sample.get('body')
 def inspect(value):
  if isinstance(value,dict):
   assert not set(value)&{'password','totp_secret','recovery_codes','token','session_id','secret'}
   for child in value.values():inspect(child)
  elif isinstance(value,list):
   for child in value:inspect(child)
 inspect(body)
 return {'operationId':name,**({'scenario':label} if label else {}),'method':sample['method'],'path_template':path,
  'actual_test_path':sample['path'],'status':sample['status'],'headers':{k:v for k,v in sample['headers'].items() if k.lower() in safe_headers},
  'body':body,'binary_bytes':sample['body_length'] if not sample['json'] else None}
examples=[]
for method,path,name in new:
 candidates=[s for s in runtime+focused if match(s)==(path,name) and 200<=s['status']<300]
 assert candidates,'Missing operation example: '+name
 examples.append(clean(candidates[0]))
scenarios=[]
receipts=[s for s in runtime if match(s) and match(s)[1]=='publicContactCreate' and s['status']==201]
assert len(receipts)>=3 and all(s['body']==receipts[0]['body'] for s in receipts)
scenarios.extend([clean(receipts[0],'saved receipt'),clean(receipts[1],'exact retry; same receipt'),clean(receipts[-1],'same receipt after authorised redaction')])
for name,status,label,predicate in [
 ('adminPortfolioImageStatus',200,'rejected real image',lambda s:s['body']['data']['state']=='rejected'),
 ('adminPortfolioDetail',200,'editorial draft',lambda s:s['body']['data']['status']=='draft' and s['body']['data']['publication_id'] is None),
 ('publicPortfolioDetail',404,'withdrawn or unpublished project',lambda s:True),
 ('publicPortfolioImage',404,'withdrawn image version',lambda s:True),
 ('publicContactCreate',409,'same key with different normalized body',lambda s:True),
 ('publicContactCreate',422,'invalid contact fields',lambda s:True),
 ('adminContactRedact',403,'redaction lacks required authority or recent confirmation',lambda s:True)]:
 candidates=[s for s in runtime+focused if match(s) and match(s)[1]==name and s['status']==status and predicate(s)]
 assert candidates,'Missing scenario: '+label
 scenarios.append(clean(candidates[0],label))
output={'notice':'Real synthetic test responses. Historical example image links were intentionally withdrawn; not permanent demo data. Authentication secrets and cookies are excluded.',
 'contract_sha256':hashlib.sha256(raw).hexdigest(),'examples':examples,'scenarios':scenarios}
(ROOT/'docs/public-services/response-examples.json').write_text(json.dumps(output,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({'operations':len(examples),'scenarios':len(scenarios),'source':'real test captures only'}))
