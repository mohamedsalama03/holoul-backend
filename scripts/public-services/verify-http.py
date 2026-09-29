#!/usr/bin/env python3
"""Synthetic local-only real HTTPS/CSRF/MFA/portfolio/contact acceptance. No credential output."""
import argparse, base64, hashlib, hmac, http.cookiejar, json, ssl, struct, subprocess, time, urllib.error, urllib.parse, urllib.request, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--port',type=int,default=8443)
    parser.add_argument('--container',default='holoul-app-1')
    parser.add_argument('--fixtures',type=Path,required=True)
    parser.add_argument('--report',type=Path,required=True)
    parser.add_argument('--samples',type=Path,required=True)
    parser.add_argument('--run-workers',action='store_true')
    args=parser.parse_args()
    if args.port not in (8443,8444) or args.container not in ('holoul-app-1','holoul-public-runtime-app'):
        raise ValueError('Explicit local verification endpoints required')
    env={k:v for line in args.fixtures.read_text().splitlines() if line and not line.startswith('#') for k,v in [line.split('=',1)]}
    origin='https://localhost:8443';connection='https://localhost:'+str(args.port)
    cookies=http.cookiejar.CookieJar();context=ssl._create_unverified_context()
    opener=urllib.request.build_opener(urllib.request.HTTPSHandler(context=context),urllib.request.HTTPCookieProcessor(cookies),urllib.request.ProxyHandler({}))
    observations=[];samples=[]
    def call(method,path,body=None,headers=None,expected=200,raw=False,no_cookie=True):
        outgoing={'Host':'localhost:8443','Origin':origin,'Accept':'application/json',**(headers or {})}
        if method not in ('GET','HEAD'):
            outgoing['X-XSRF-TOKEN']=urllib.parse.unquote(next((c.value for c in cookies if c.name=='XSRF-TOKEN'),''))
        data=body if raw else json.dumps(body or {}).encode() if method not in ('GET','HEAD') else None
        if data is not None:outgoing['Content-Type']='application/octet-stream' if raw else 'application/json'
        request=urllib.request.Request(connection+path,data=data,headers=outgoing,method=method)
        try:response=opener.open(request,timeout=30)
        except urllib.error.HTTPError as error:response=error
        content=response.read();status=response.status
        assert status==expected,(method,path,status,expected)
        assert not no_cookie or not response.headers.get_all('Set-Cookie'),('unexpected cookie',method,path)
        is_json='application/json' in response.headers.get('Content-Type','')
        parsed=json.loads(content) if is_json else None
        safe_headers={name.lower():response.headers.get_all(name) for name in ['Content-Type','Cache-Control','X-Request-ID','ETag','X-Content-Type-Options'] if response.headers.get_all(name)}
        observations.append({'method':method,'path':path,'status':status,'set_cookie':bool(response.headers.get_all('Set-Cookie')),'cache_control':response.headers.get('Cache-Control')})
        if any(part in path for part in ['/public/portfolio','/admin/portfolio','/contact-messages','/public-content/']):
            samples.append({'test':'real-local-https','method':method,'path':path.split('?')[0],'status':status,'headers':safe_headers,'body_length':len(content),'json':is_json,'body':parsed})
        return parsed,response.headers,content
    def workers(queue):
        if args.run_workers:
            result=subprocess.run(['docker','exec',args.container,'php','artisan','queue:work',queue,'--queue='+queue,'--stop-when-empty','--tries=1','--timeout=120'],capture_output=True,text=True,timeout=150)
            assert result.returncode==0,('worker failed',queue)
    def admin(method,path,body=None,etag=None,key=None,expected=200):
        headers={}
        if etag:headers['If-Match']=etag
        if key:headers['Idempotency-Key']=key
        result,_,_=call(method,path,body,headers,expected)
        return result['data']
    call('GET','/health/ready')
    call('GET','/api/v1/public/portfolio/categories')
    call('GET','/sanctum/csrf-cookie',expected=204,no_cookie=False)
    key=str(uuid.uuid4());email='e2e-public-'+str(uuid.uuid4())+'@example.test'
    contact={'full_name':'Synthetic Runtime Contact','email':email,'phone':'+12025550123','company':None,'message':'Synthetic private message for local integration verification only.'}
    receipt,_,_=call('POST','/api/v1/public/contact-messages',contact,{'Idempotency-Key':key},201)
    duplicate,_,_=call('POST','/api/v1/public/contact-messages',contact,{'Idempotency-Key':key},201);assert receipt==duplicate
    call('POST','/api/v1/auth/login',{'email':env['HOLOUL_E2E_ADMIN_EMAIL'],'password':env['HOLOUL_E2E_ADMIN_PASSWORD']},expected=202,no_cookie=False)
    secret=env['HOLOUL_E2E_ADMIN_TOTP_SECRET'];digest=hmac.new(base64.b32decode(secret+'='*((8-len(secret)%8)%8)),struct.pack('>Q',int(time.time())//30),hashlib.sha1).digest();offset=digest[-1]&15
    code=str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)
    call('POST','/api/v1/auth/mfa/challenge',{'code':code},no_cookie=False)
    call('GET','/api/v1/identity/me')
    cap=admin('GET','/api/v1/admin/public-content/capabilities');assert all(cap[x] for x in ['portfolio_read','portfolio_manage','portfolio_publish','contact_read','contact_manage','contact_redact'])
    workers('notifications')
    contact_id=receipt['data']['id'];contact_url='/api/v1/admin/contact-messages/'+contact_id
    deadline=time.monotonic()+30
    while True:
        item=admin('GET',contact_url)
        if item['delivery_status']=='sent':break
        assert time.monotonic()<deadline,('mail not sent',item['delivery_status']);time.sleep(.5)
    admin('GET','/api/v1/admin/contact-messages?limit=1')
    item=admin('PATCH',contact_url,{'status':'resolved'},item['etag'])
    redacted=admin('DELETE',contact_url,etag=item['etag']);assert redacted['message'] is None and redacted['status']=='redacted'
    assert call('POST','/api/v1/public/contact-messages',contact,{'Idempotency-Key':key},201)[0]==receipt
    project=admin('POST','/api/v1/admin/portfolio/projects',{'title':'E2E public portfolio','summary':'A synthetic public portfolio for runtime verification.',
        'description':'Synthetic content created only to verify the local integration candidate end to end.','category_id':'web'},expected=201)
    url='/api/v1/admin/portfolio/projects/'+project['id'];source=(ROOT/'tests/Fixtures/portfolio/valid.png').read_bytes()
    asset=admin('POST',url+'/images',{'media_type':'image/png','byte_size':len(source),'sha256':hashlib.sha256(source).hexdigest(),'alt':'Synthetic test image','display_order':0},project['etag'],expected=201)
    call('PUT',url+'/images/'+asset['id']+'/content',source,{'If-Match':asset['etag']},202,raw=True)
    workers('documents');deadline=time.monotonic()+30
    while True:
        state=admin('GET',url+'/images/'+asset['id'])
        if state['state']=='ready':break
        assert time.monotonic()<deadline,('image not ready',state['state'],state['failure_code']);time.sleep(.5)
    project=admin('GET',url)
    project=admin('PATCH',url,{'cover_image_id':asset['id'],'featured_image_id':asset['id']},project['etag'])
    key=str(uuid.uuid4());etag=project['etag'];published=admin('POST',url+'/publications',{'rights_confirmed':True},etag,key,201)
    assert published==admin('POST',url+'/publications',{'rights_confirmed':True},etag,key,201)
    admin('GET','/api/v1/admin/portfolio/projects?limit=1')
    detail,headers,_=call('GET','/api/v1/public/portfolio/projects/'+project['public_id']);assert 's-maxage=60' in headers['Cache-Control'] and 'no-store' not in headers['Cache-Control']
    image=detail['data']['images'][0]['id'];image_url='/api/v1/public/portfolio/images/'+image
    for variant in ['card','gallery']:
        _,headers,bytes_=call('GET',image_url+'/'+variant);assert headers['Content-Type']=='image/webp' and bytes_.startswith(b'RIFF')
    call('GET','/api/v1/public/portfolio/projects?category=web&limit=1');call('GET','/api/v1/public/portfolio/categories')
    unpublished=admin('POST',url+'/unpublications',etag=published['etag'],key=str(uuid.uuid4()))
    withdrawn_at=time.monotonic()
    for target in ['/api/v1/public/portfolio/projects/'+project['public_id'],image_url+'/card',image_url+'/gallery']:
        _,headers,_=call('GET',target,expected=404);assert 'no-store' in headers['Cache-Control']
    withdrawal_ms=round((time.monotonic()-withdrawn_at)*1000,2)
    project=admin('DELETE',url+'/images/'+asset['id'],etag=unpublished['etag'])
    # Deliberate PNG trailing bytes must be rejected by the real isolated parser.
    bad_source=source+b'NOT-AN-IMAGE-TRAILER'
    bad=admin('POST',url+'/images',{'media_type':'image/png','byte_size':len(bad_source),'sha256':hashlib.sha256(bad_source).hexdigest(),
        'alt':'Synthetic rejected image','display_order':1},project['etag'],expected=201)
    call('PUT',url+'/images/'+bad['id']+'/content',bad_source,{'If-Match':bad['etag']},202,raw=True)
    workers('documents');deadline=time.monotonic()+30
    while True:
        rejected=admin('GET',url+'/images/'+bad['id'])
        if rejected['state']=='rejected':break
        assert time.monotonic()<deadline,('invalid image not rejected',rejected['state']);time.sleep(.5)
    assert rejected['failure_code']=='invalid_image'
    call('GET','/api/v1/public/portfolio/projects/'+project['public_id'],expected=404)
    call('POST','/api/v1/auth/logout',no_cookie=False)
    call('GET','/api/v1/public/portfolio/categories')
    call('GET','/api/v1/identity/me',expected=401)
    # Mailpit receives a reference-only notice. No message body, sender PII or unusable dashboard link.
    mail_opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
    query=urllib.parse.quote('subject:'+receipt['data']['reference'])
    search=json.load(mail_opener.open('http://localhost:8025/api/v1/search?query='+query,timeout=10))
    matches=search.get('messages',[]);assert len(matches)==1,('expected one local mail',len(matches))
    message=json.load(mail_opener.open('http://localhost:8025/api/v1/message/'+matches[0]['ID'],timeout=10))
    text=message.get('Text','');assert receipt['data']['reference'] in text and email not in text and contact['message'] not in text and '/admin/contact-messages/' not in text
    report={'passed':True,'origin':origin,'connection_port':args.port,'runtime_container':args.container,'checks':len(observations),'origin_withdrawal_observed_ms':withdrawal_ms,
        'mailpit_receipt_notifications':len(matches),'full_website_60_second_cache_acceptance':'pending frontend integration; local edge has no shared cache','observations':observations}
    args.report.write_text(json.dumps(report,indent=2)+'\n');args.samples.write_text(''.join(json.dumps(x,ensure_ascii=False)+'\n' for x in samples))
    print(json.dumps({k:v for k,v in report.items() if k!='observations'}))

if __name__=='__main__':main()
