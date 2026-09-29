#!/usr/bin/env python3
"""Verify the local display candidate with reserved synthetic identities; never output credentials."""
import argparse, base64, hashlib, hmac, http.cookiejar, json, ssl, struct, time, urllib.error, urllib.parse, urllib.request, uuid
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--fixtures', type=Path, required=True)
parser.add_argument('--report', type=Path, required=True)
parser.add_argument('--samples', type=Path, required=True)
args = parser.parse_args()
settings = dict(line.split('=', 1) for line in args.fixtures.read_text().splitlines() if line and not line.startswith('#'))
for prefix in ('HOLOUL_E2E_STAFF', 'HOLOUL_E2E_CUSTOMER'):
    label = 'staff' if prefix.endswith('STAFF') else 'customer'
    assert settings[prefix + '_EMAIL'] == 'local-e2e-' + settings['HOLOUL_E2E_RUN'] + '-' + label + '@example.test', 'Exact fixture identity required'
origin = 'https://localhost:8443'
observations, samples, owned = [], [], []
def remember(id_):
    owned.append(id_)
    # Keep only exact test-created IDs for targeted recovery if the process is interrupted.
    receipt = args.samples.with_suffix('.owned.json')
    receipt.write_text(json.dumps({'request_ids': owned})); receipt.chmod(0o600)
class Browser:
    def __init__(self):
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=ssl._create_unverified_context()),
            urllib.request.HTTPCookieProcessor(self.cookies), urllib.request.ProxyHandler({}))
    def call(self, method, path, data=None, headers=None, status=200, rotation=False):
        outgoing = {'Origin': origin, 'Accept': 'application/json', **(headers or {})}
        body = None
        if method != 'GET':
            outgoing['Content-Type'] = 'application/json'
            outgoing['X-XSRF-TOKEN'] = urllib.parse.unquote(next((c.value for c in self.cookies if c.name == 'XSRF-TOKEN'), ''))
            body = json.dumps(data or {}).encode()
        try:
            response = self.opener.open(urllib.request.Request(origin + path, data=body, headers=outgoing, method=method), timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        raw = response.read()
        assert response.status == status, (method, path, response.status, status)
        assert rotation or not response.headers.get_all('Set-Cookie'), ('Unexpected session replacement', method, path)
        result = json.loads(raw) if 'application/json' in response.headers.get('Content-Type', '') else None
        observations.append({'method': method, 'path': path, 'status': response.status,
            'set_cookie': bool(response.headers.get_all('Set-Cookie'))})
        if path.startswith('/api/v1/admin/project-requests'):
            samples.append({'test': 'intake-display-real-https', 'method': method, 'path': path.split('?')[0],
                'status': response.status, 'json': True, 'body': result, 'body_length': len(raw),
                'headers': {k.lower(): response.headers.get_all(k) for k in ('Content-Type', 'Cache-Control', 'ETag', 'X-Request-ID') if response.headers.get_all(k)}})
        if result and isinstance(result.get('data'), dict) and 'etag' in result['data']:
            assert result['data']['etag'] == response.headers['ETag']
        return result
    def bootstrap(self):
        self.call('GET', '/sanctum/csrf-cookie', status=204, rotation=True)
    def login(self, prefix, staff=False):
        self.bootstrap()
        self.call('POST', '/api/v1/auth/login', {'email': settings[prefix + '_EMAIL'], 'password': settings[prefix + '_PASSWORD']}, status=202 if staff else 200, rotation=True)
        if staff:
            secret = settings[prefix + '_TOTP_SECRET']
            digest = hmac.new(base64.b32decode(secret + '=' * ((8 - len(secret) % 8) % 8)), struct.pack('>Q', int(time.time()) // 30), hashlib.sha1).digest()
            offset = digest[-1] & 15
            code = str((struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7fffffff) % 1000000).zfill(6)
            self.call('POST', '/api/v1/auth/mfa/challenge', {'code': code}, rotation=True)
        return self.call('GET', '/api/v1/identity/me')['data']

customer, staff, guest = Browser(), Browser(), Browser()
customer_identity = customer.login('HOLOUL_E2E_CUSTOMER')
selection = None
for category in customer.call('GET', '/api/v1/categories')['data']:
    children = customer.call('GET', '/api/v1/categories/' + category['id'] + '/subcategories')['data']
    if children:
        selection = {'category_id': category['id'], 'subcategory_id': children[0]['id']}
        break
assert selection, 'An active category/subcategory is required'
idea = {**selection, 'project_name': 'E2E F4 display ' + str(uuid.uuid4()), 'project_description': 'Synthetic local display verification; closed after the check.', 'budget_unknown': True}
created = customer.call('POST', '/api/v1/project-requests', idea, status=201)['data']
id_ = created['id']; remember(id_)
customer.call('POST', '/api/v1/project-requests/' + id_ + '/submissions', headers={'If-Match': created['etag'], 'Idempotency-Key': str(uuid.uuid4())}, status=201)
staff_identity = staff.login('HOLOUL_E2E_STAFF', staff=True)
extra = {'customer_id', 'project_name', 'customer_display_name', 'provenance', 'claimed', 'assigned_staff'}
def detail(id_):
    return staff.call('GET', '/api/v1/admin/project-requests/' + id_ + '?view=dashboard')['data']
def check_read(id_):
    path = '/api/v1/admin/project-requests/' + id_
    legacy = staff.call('GET', path)['data']; view = detail(id_)
    assert legacy == {k: v for k, v in view.items() if k not in extra}
    assert staff.call('GET', '/api/v1/admin/project-requests/by-reference/' + view['reference'] + '?view=dashboard')['data'] == view
    page = staff.call('GET', '/api/v1/admin/project-requests?view=dashboard&reference=' + view['reference'])['data']
    assert len(page) == 1
    for field in extra: assert page[0][field] == view[field]
    for suffix, additions in (('/history', {'actor'}), ('/assignments', {'previous_staff', 'assigned_staff', 'assigned_by_staff'})):
        default = staff.call('GET', path + suffix)['data']
        display = staff.call('GET', path + suffix + '?view=dashboard')['data']
        assert default == [{k: v for k, v in row.items() if k not in additions} for row in display]
    return view
first = check_read(id_)
assert first['customer_id'] != customer_identity['id'] and first['provenance'] == 'customer' and first['claimed'] is False
staff.call('GET', '/api/v1/admin/customers/' + first['customer_id'])
staff.call('GET', '/api/v1/admin/customers/' + customer_identity['id'], status=404)
staff.call('GET', '/api/v1/identity/staff', status=403)
# Guest intake runs in a separate browser while both authenticated sessions exist.
guest.bootstrap()
created = guest.call('POST', '/api/v1/guest/project-requests', status=201)['data']
guest_id = created['draft_id']; remember(guest_id)
submitted = guest.call('POST', '/api/v1/guest/project-requests/' + guest_id + '/submissions',
    {**idea, 'full_name': 'E2E Original Guest', 'email': settings['HOLOUL_E2E_CUSTOMER_EMAIL'], 'phone': '+12025550123'},
    headers={'If-Match': created['etag'], 'X-Intake-Capability': created['capability'], 'Idempotency-Key': str(uuid.uuid4())}, status=201)['data']
view = check_read(guest_id)
assert view['customer_id'] is None and view['provenance'] == 'guest' and view['claimed'] is False
before = staff.call('GET', '/api/v1/admin/project-requests/' + guest_id + '/history?view=dashboard')['data']
assert before[0]['actor'] == {'id': None, 'kind': 'guest', 'display_name': 'E2E Original Guest'}
customer.call('POST', '/api/v1/project-request-claims', {'token': submitted['claim_token']}, headers={'Idempotency-Key': str(uuid.uuid4())})
view = detail(guest_id)
assert view['customer_id'] == first['customer_id'] and view['claimed'] is True and view['provenance'] == 'guest'
assert before == staff.call('GET', '/api/v1/admin/project-requests/' + guest_id + '/history?view=dashboard')['data']
for id_ in owned:
    path = '/api/v1/admin/project-requests/' + id_
    view = detail(id_)
    for suffix, body in (('/assignments', {'assignee_id': staff_identity['id']}), ('/reviews', {}), ('/rejections', {'message': 'Synthetic local display check complete.'})):
        result = staff.call('POST', path + suffix, body, headers={'If-Match': view['etag']})['data']
        assert not extra.intersection(result), 'Command response changed'
        view = detail(id_)
    assert view['state'] == 'rejected'
    assert view['assigned_staff']['id'] == staff_identity['id']
    check_read(id_)
for browser in (customer, staff): browser.call('POST', '/api/v1/auth/logout', rotation=True)
args.samples.write_text(''.join(json.dumps(sample, ensure_ascii=False) + '\n' for sample in samples)); args.samples.chmod(0o600)
args.report.write_text(json.dumps({'passed': True, 'origin': origin, 'checks': len(observations), 'closed_synthetic_requests': owned,
    'ordinary_reads_and_guest_operations_reissued_cookies': False, 'observations': observations}, indent=2) + '\n')
print(json.dumps({'passed': True, 'checks': len(observations), 'closed_synthetic_requests': len(owned)}))
