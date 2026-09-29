#!/usr/bin/env python3
"""Reviewed additive PublicPortfolio/Contact contract. Existing fragments are untouched."""
import json
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
S, P = {}, {}
def ref(name): return {"$ref": "#/components/schemas/"+name}
def obj(props, required=None): return {"type":"object","additionalProperties":False,"required":list(props) if required is None else required,"properties":props}
def arr(item, maximum, minimum=0): return {"type":"array","items":item,"maxItems":maximum,"minItems":minimum}
def string(minimum=1, maximum=None): return {"type":"string","minLength":minimum,**({"maxLength":maximum} if maximum else {})}
def enum(*values): return {"type":"string","enum":list(values)}
def nullable(schema): return {"anyOf":[schema,{"type":"null"}]}
def envelope(name): return obj({"data":ref(name)})
uuid={"type":"string","format":"uuid"}
uuid7={**uuid,"pattern":"^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"}
date={"type":"string","format":"date-time"}
etag={"type":"string","pattern":'^"[0-9a-f-]{36}:[1-9][0-9]*"$'}
category=enum('web','mobile','business','commerce','custom')
meta=obj({'limit':{'type':'integer','minimum':1,'maximum':48},'next_cursor':nullable(string(1,512))})
S['PublicPortfolioCategory']=obj({'id':category,'name':string(1,80),'display_order':{'type':'integer','minimum':0},'published_count':{'type':'integer','minimum':0}})
S['PublicPortfolioImageVariant']=obj({'name':enum('card','gallery'),'url':{'type':'string','pattern':'^/api/v1/public/portfolio/images/[0-9a-f-]{36}/(card|gallery)$'},'width':{'type':'integer','minimum':1,'maximum':1800},'height':{'type':'integer','minimum':1,'maximum':1400},'media_type':{'const':'image/webp'}})
S['PublicPortfolioImage']=obj({'id':uuid7,'alt':string(1,240),'variants':arr(ref('PublicPortfolioImageVariant'),2,2)})
card={'id':{**uuid,'description':'UUIDv7 for new records; imported legacy UUIDv4 is preserved for existing project links.'},'title':string(2,120),'summary':string(10,240),'category_id':category,'cover_image':ref('PublicPortfolioImage'),'updated_at':date}
S['PublicPortfolioCard']=obj(card)
S['PublicPortfolioDetail']=obj({**card,'description':string(30,12000),'images':arr(ref('PublicPortfolioImage'),8,1),'featured_image_id':uuid7})
S['PublicPortfolioPage']=obj({'data':arr(ref('PublicPortfolioCard'),48),'meta':meta})
S['PublicPortfolioDetailResponse']=envelope('PublicPortfolioDetail')
S['PublicPortfolioCategoriesResponse']=obj({'data':arr(ref('PublicPortfolioCategory'),5,5)})
S['AdminPublicContentCapabilities']=obj({**{k:{'type':'boolean'} for k in ['portfolio_read','portfolio_manage','portfolio_publish','contact_read','contact_manage','contact_redact']},'recent_password_confirmation':{'type':'boolean'}})
S['AdminPublicContentCapabilitiesResponse']=envelope('AdminPublicContentCapabilities')
editor={'title':string(2,120),'summary':string(10,240),'description':string(30,12000),'category_id':category}
S['AdminPortfolioCreateInput']=obj(editor)
S['AdminPortfolioUpdateInput']=obj({**editor,'cover_image_id':nullable(uuid7),'featured_image_id':nullable(uuid7)},[])
S['AdminPortfolioImageInput']=obj({'media_type':enum('image/jpeg','image/png','image/webp'),'byte_size':{'type':'integer','minimum':1,'maximum':5242880},'sha256':{'type':'string','pattern':'^[a-f0-9]{64}$'},'alt':string(1,240),'display_order':{'type':'integer','minimum':0,'maximum':7}})
S['AdminPortfolioPublishInput']=obj({'rights_confirmed':{'const':True,'description':'Staff explicitly attests authorization to publish the content and all images.'}})
S['PublicServicesEmptyInput']=obj({})
image={**S['AdminPortfolioImageInput']['properties'],'id':uuid7,'project_id':uuid7,'state':enum('reserved','processing','ready','rejected','removed','expired'),'failure_code':nullable(enum('invalid_image','resource_limit','authority_revoked','processing_unavailable','processing_failed')),'expires_at':date,'etag':etag}
S['AdminPortfolioImageStatus']=obj(image)
S['AdminPortfolioImageResponse']=envelope('AdminPortfolioImageStatus')
admincard={'id':uuid7,'public_id':uuid,'title':string(2,120),'summary':string(10,240),'category_id':category,'status':enum('draft','published'),'updated_at':date,'etag':etag}
S['AdminPortfolioCard']=obj(admincard)
S['AdminPortfolioProject']=obj({**admincard,'description':string(30,12000),'cover_image_id':nullable(uuid7),'featured_image_id':nullable(uuid7),'publication_id':nullable(uuid7),'created_at':date,'images':arr(ref('AdminPortfolioImageStatus'),8)})
S['AdminPortfolioResponse']=envelope('AdminPortfolioProject')
S['AdminPortfolioPage']=obj({'data':arr(ref('AdminPortfolioCard'),48),'meta':meta})
S['PublicContactInput']=obj({'full_name':string(2,120),'email':{'type':'string','format':'email','maxLength':254},'phone':{'type':'string','minLength':2,'maxLength':64,'description':'Required international phone, starting +; normalized and validated with libphonenumber to E.164. No country inference.'},'company':nullable(string(1,160)),'message':string(10,5000)},['full_name','email','phone','message'])
S['PublicContactInput']['description']='NFC-normalized plain text; email follows Identity normalization; blank company becomes null; unknown fields rejected; JSON <=32 KiB. No attachments, owner IDs or recipient override.'
S['PublicContactReceipt']=obj({'id':uuid7,'reference':{'type':'string','pattern':'^CNT-[0-9]{4}-[0-9]{5,19}$'},'status':{'const':'received'},'received_at':date})
S['PublicContactReceiptResponse']=envelope('PublicContactReceipt')
contact={'id':uuid7,'reference':{'type':'string','pattern':'^CNT-[0-9]{4}-[0-9]{5,19}$'},'full_name':nullable(string(2,120)),'email':nullable({'type':'string','format':'email','maxLength':254}),'status':enum('received','in_progress','resolved','spam','redacted'),'received_at':date,'updated_at':date,'redacted_at':nullable(date),'etag':etag}
S['AdminContactSummary']=obj(contact)
S['AdminContactMessage']=obj({**contact,'phone':nullable({'type':'string','pattern':'^\\+[1-9][0-9]{1,14}$'}),'company':nullable(string(1,160)),'message':nullable(string(10,5000)),'delivery_status':enum('pending','sending','sent','uncertain','blocked','suppressed')})
S['AdminContactResponse']=envelope('AdminContactMessage')
S['AdminContactPage']=obj({'data':arr(ref('AdminContactSummary'),50),'meta':obj({'limit':{'type':'integer','minimum':1,'maximum':50},'next_cursor':nullable(string(1,512))})})
S['AdminContactUpdateInput']=obj({'status':enum('received','in_progress','resolved','spam')})
params={'PublicServicesIdempotencyKey':{'name':'Idempotency-Key','in':'header','required':True,'schema':uuid,'description':'Random UUID. Retain key, normalized body and precondition for an exact uncertain retry. Contact key window is [first receipt time, +72h); same body replays identical 201, different body=409; at/after expiry a new receipt is created atomically. Never automatically retry an uncertain contact operation with a new key or after expiry. Portfolio publication keys are retained with immutable history and scoped to the staff actor.'}}
errors={401:'Unauthorized',404:'NotFound',409:'Conflict',412:'PreconditionFailed',422:'ValidationError',428:'PreconditionRequired'}
def op(path,method,name,summary,result,status=200,input=None,permission=None,public=False,version=False,key=False,sensitive=False,extra='',binary=False):
    administrative=not public
    parameters=[{'$ref':'#/components/parameters/RequestId'},{'$ref':'#/components/parameters/Origin'}]
    for part in path.split('/'):
        if part.startswith('{'):
            field=part[1:-1]
            schema=enum('card','gallery') if field=='variant' else (uuid if public and field=='project' else uuid7)
            parameters.append({'name':field,'in':'path','required':True,'schema':schema})
    if method not in ('get','head'): parameters.append({'$ref':'#/components/parameters/CsrfToken'})
    if version: parameters.append({'$ref':'#/components/parameters/IfMatch'})
    if key: parameters.append({'$ref':'#/components/parameters/PublicServicesIdempotencyKey'})
    headers={'Cache-Control':{'schema':{'type':'string'}},'X-Request-ID':{'schema':uuid}}
    if result in ('AdminPortfolioResponse','AdminPortfolioImageResponse','AdminContactResponse'): headers['ETag']={'schema':etag}
    response={'description':summary,'headers':headers,'content':{'image/webp' if binary else 'application/json':{'schema':{'type':'string','format':'binary'} if binary else ref(result)}}}
    responses={str(status):response}
    codes={422}
    if administrative: codes.add(401)
    if '{' in path: codes.add(404)
    if method not in ('get','head'): codes.add(409)
    if version: codes.update([412,428])
    for code in codes: responses[str(code)]={'$ref':'#/components/responses/'+errors[code]}
    description=extra
    if administrative: description+=' Requires an enabled staff identity, verified email, complete MFA and current server-side permission. Only Super Admin and Administrator receive the six new permissions by default. Existing staff invitation and role-grant policy is unchanged.'
    if sensitive: description+=' Requires recent password confirmation using the existing identityConfirmPassword operation.'
    if public and method=='get': description+=' Anonymous, cookie-independent read: no session bootstrap, Set-Cookie or authentication state changes. Success Cache-Control is public,max-age=0,s-maxage=60,must-revalidate. Errors are no-store; no stale-* fallback or 304. The full website/cache path must keep the total withdrawal budget within 60 seconds; the local edge itself does not cache.'
    operation={'operationId':name,'summary':summary,'description':description.strip(),'tags':['Public portfolio' if 'portfolio' in path else 'Contact' if 'contact-messages' in path else 'Public content administration'],
        'security':[] if public and method=='get' else [{'SessionCookie':[]}], 'x-personas':['public'] if public and method=='get' else ['guest','customer','staff'] if name=='publicContactCreate' else ['staff'],
        'x-permissions':[permission] if permission else [],'x-frontend-feature':summary,'x-source':['routes/public-content.php','app/Application/PublicServices/ContactApi.php' if 'contact-messages' in path else 'app/Application/PublicServices/PublicContentCapabilities.php' if 'capabilities' in path else 'app/Application/PublicServices/PortfolioApi.php'],
        'parameters':parameters,'responses':responses}
    if input:
        operation['requestBody']={'required':True,'content':{'application/octet-stream' if input=='binary' else 'application/json':{'schema':{'type':'string','format':'binary','maxLength':5242880} if input=='binary' else ref(input)}}}
    P.setdefault(path,{})[method]=operation
    return operation
base='/api/v1'
pub=base+'/public/portfolio'
admin=base+'/admin/portfolio/projects'
c=base+'/admin/contact-messages'
listop=op(pub+'/projects','get','publicPortfolioList','List published portfolio cards','PublicPortfolioPage',public=True,extra='Stable published_at DESC, publication ID DESC cursor; concurrent changes do not provide a page snapshot. The website deduplicates by project id.')
op(pub+'/projects/{project}','get','publicPortfolioDetail','Read a published portfolio project','PublicPortfolioDetailResponse',public=True,extra='Draft edits do not change the immutable public snapshot. Unpublished, unknown and retired aliases return 404.')
op(pub+'/categories','get','publicPortfolioCategories','List portfolio categories and published counts','PublicPortfolioCategoriesResponse',public=True,extra='Fixed editorial category codes, independent from the intake UUID taxonomy.')
op(pub+'/images/{image}/{variant}','get','publicPortfolioImage','Read immutable published WebP bytes','',public=True,binary=True,extra='The image id is a UUIDv7 publication image, not an admin upload asset. Every publication issues fresh ids, including unchanged bytes. Replaced/unpublished image ids return 404 at origin and can never revive. Membership is rechecked after private storage I/O. Card fits 640x640; gallery fits 1800x1400; neither upscales. No metadata or private storage keys are exposed.')
op(base+'/public/contact-messages','post','publicContactCreate','Save a contact message and return its durable receipt','PublicContactReceiptResponse',201,'PublicContactInput',public=True,key=True,extra='Explicit CSRF bootstrap is required for every persona. The response never creates, rotates or replaces authentication state. Receipt and one durable notification intent commit atomically. 201 confirms storage, not email delivery. Email goes only to configured info@holoul.ly and contains a reference plus the authorized dashboard link once that page is accepted. Ambiguous SMTP outcome is not blindly retried. Automatic redaction is disabled; the proposed 180-day retention period remains unapproved.')
op(base+'/admin/public-content/capabilities','get','adminPublicContentCapabilities','Read own public-content permissions','AdminPublicContentCapabilitiesResponse',extra='Booleans express currently granted permissions; sensitive actions also require recent_password_confirmation. Existing IdentityCurrentUser/StaffCapabilities schemas are unchanged.')
a=op(admin,'get','adminPortfolioList','List editorial portfolio projects','AdminPortfolioPage',permission='portfolio.read')
op(admin,'post','adminPortfolioCreate','Create an independent editorial draft','AdminPortfolioResponse',201,'AdminPortfolioCreateInput',permission='portfolio.manage',extra='Does not create or expose any private customer project. Plain Unicode NFC text only; display as text, never trusted HTML.')
op(admin+'/{project}','get','adminPortfolioDetail','Read an editorial draft and image states','AdminPortfolioResponse',permission='portfolio.read')
op(admin+'/{project}','patch','adminPortfolioUpdate','Edit draft fields and select cover/featured assets','AdminPortfolioResponse',input='AdminPortfolioUpdateInput',permission='portfolio.manage',version=True,extra='The strong version precondition is the project ETag. The currently published snapshot is unchanged until explicit publication. Selected image ids must belong to this project.')
op(admin+'/{project}/images','post','adminPortfolioReserveImage','Reserve a private image upload','AdminPortfolioImageResponse',201,'AdminPortfolioImageInput',permission='portfolio.manage',version=True,extra='If-Match is the parent project ETag. At most 8 active reservations/assets. Reservation expires in one hour. Only static JPEG/PNG/WebP <=5 MiB and <=24 million pixels; isolated processing corrects orientation, strips metadata and emits WebP. The reserving staff identity must perform the upload. Expired reservations are retired by bounded reconciliation.')
op(admin+'/{project}/images/{image}/content','put','adminPortfolioUploadImage','Upload exact reserved bytes for isolated processing','AdminPortfolioImageResponse',202,'binary',permission='portfolio.manage',version=True,extra='If-Match is the image reservation ETag, not the project ETag. Raw application/octet-stream body must match reserved length and SHA-256. Current staff/session authority is rechecked after storage I/O. A response lost after acceptance requires polling image status; no blind overwrite. 202 means processing queued; only ready images may publish.')
op(admin+'/{project}/images/{image}','get','adminPortfolioImageStatus','Read private image reservation and processing state','AdminPortfolioImageResponse',permission='portfolio.read')
op(admin+'/{project}/images/{image}','delete','adminPortfolioRemoveImage','Retire an editorial image','AdminPortfolioResponse',permission='portfolio.manage',version=True,sensitive=True,extra='If-Match is the parent project ETag. A currently published image cannot be removed; publish a replacement set or unpublish first. History remains; private retired bytes are purged after a 24-hour race-safety grace period.')
op(admin+'/{project}/publications','post','adminPortfolioPublish','Publish an immutable portfolio snapshot','AdminPortfolioResponse',201,'AdminPortfolioPublishInput',permission='portfolio.publish',version=True,key=True,sensitive=True,extra='Requires 1–8 ready images with alt text, owned cover and featured images, and explicit publication-rights confirmation. If-Match is the project ETag. Snapshot, new public image ids, audit and durable invalidation intent commit together. Exact command replay returns the original response; re-fetch detail for current state.')
op(admin+'/{project}/unpublications','post','adminPortfolioUnpublish','Withdraw a published portfolio project','AdminPortfolioResponse',input='PublicServicesEmptyInput',permission='portfolio.publish',version=True,key=True,sensitive=True,extra='Withdraws the origin immediately at commit, preserving snapshots/audit. If-Match is the project ETag. All image ids from the withdrawn publication become unavailable; a later publication uses fresh ids. Durable invalidation tracks origin application; no CDN is configured on the local edge.')
cl=op(c,'get','adminContactList','List contact inbox entries','AdminContactPage',permission='contact.read',extra='Descending immutable receipt UUIDv7; cursor scoped to status. Listing and personal-content reads are audited without logging message bodies or contact details.')
op(c+'/{message}','get','adminContactDetail','Read a contact message and delivery outcome','AdminContactResponse',permission='contact.read')
op(c+'/{message}','patch','adminContactUpdate','Update contact handling status','AdminContactResponse',input='AdminContactUpdateInput',permission='contact.manage',version=True,extra='If-Match is the receipt administrative ETag; received content and receipt identity stay immutable. Redacted content cannot be restored.')
op(c+'/{message}','delete','adminContactRedact','Manually redact personal contact content','AdminContactResponse',permission='contact.redact',version=True,sensitive=True,extra='Nulls personal fields while preserving immutable receipt, audit and delivery history. If-Match is the receipt administrative ETag. No anonymous deletion by email. Backups require a reviewed retention/restore procedure; no scheduled redaction is enabled.')
for operation,filtername,filters,maximum in [(listop,'category',list(category['enum']),48),(a,'status',['draft','published'],48),(cl,'status',['received','in_progress','resolved','spam','redacted'],50)]:
    operation['parameters'] += [{'name':filtername,'in':'query','required':False,'schema':enum(*filters)}, {'name':'limit','in':'query','required':False,'schema':{'type':'integer','minimum':1,'maximum':maximum,'default':25 if operation is cl else 12}}, {'name':'cursor','in':'query','required':False,'schema':string(1,512)}]
fragment={'paths':P,'components':{'schemas':S,'parameters':params}}
(ROOT/'docs/contracts/public-services.json').write_text(json.dumps(fragment,ensure_ascii=False,indent=2)+'\n')
print('Reviewed public-services fragment:',sum(len(v) for v in P.values()),'operations,',len(S),'schemas')
