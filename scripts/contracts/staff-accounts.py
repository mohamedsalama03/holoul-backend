#!/usr/bin/env python3
"""Build the additive direct-staff contract fragment from reviewed existing primitives."""
import copy
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = json.loads((ROOT / 'docs/openapi.json').read_text())
ref = lambda name: {'$ref': '#/components/schemas/' + name}
schemas = {}
schemas['StaffAccountCreateInput'] = copy.deepcopy(spec['components']['schemas']['StaffInvitationInput'])
properties = schemas['StaffAccountCreateInput']['properties']
properties['username'] = {'type': 'string', 'minLength': 3, 'maxLength': 40, 'pattern': '^[a-zA-Z][a-zA-Z0-9._-]{2,39}$', 'description': 'Trimmed and lowercase-normalized. Unique, immutable staff login name. No @.'}
properties['password'] = {'type': 'string', 'minLength': 12, 'maxLength': 128, 'writeOnly': True, 'description': 'Must contain lowercase and uppercase letters and a number. Never returned, logged or emailed.'}
properties['password_confirmation'] = {'type': 'string', 'minLength': 12, 'maxLength': 128, 'writeOnly': True, 'description': 'Must match password.'}
schemas['StaffAccountCreateInput']['required'] += ['username', 'password', 'password_confirmation']
schemas['StaffAccountRecord'] = {'type': 'object', 'additionalProperties': False, 'required': ['id', 'username', 'full_name', 'email'], 'properties': {
    'id': {'type': 'string', 'format': 'uuid'}, 'username': {'type': 'string', 'pattern': '^[a-z][a-z0-9._-]{2,39}$'},
    'full_name': {'type': 'string', 'minLength': 2, 'maxLength': 160}, 'email': {'type': 'string', 'format': 'email'}}}
schemas['StaffAccountResponse'] = {'type': 'object', 'additionalProperties': False, 'required': ['data'], 'properties': {'data': ref('StaffAccountRecord')}}
schemas['StaffAccountErrorEnvelope'] = copy.deepcopy(spec['components']['schemas']['StaffErrorEnvelope'])
schemas['StaffAccountErrorEnvelope']['properties']['error']['properties']['reason']['enum'] += ['USERNAME_UNAVAILABLE', 'ACCOUNT_UNAVAILABLE']
schemas['UsernameLoginInput'] = {'type': 'object', 'additionalProperties': False, 'required': ['username', 'password'], 'properties': {
    'username': properties['username'], 'password': {'type': 'string', 'maxLength': 128, 'writeOnly': True}}}
create = copy.deepcopy(spec['paths']['/api/v1/identity/staff/invitations']['post'])
create.update(operationId='identityCreateStaffAccount', summary='Create a staff account with username and password',
    description='Completed MFA and recent password confirmation (300 seconds), existing staff read/manage permissions and unchanged role grant policy. Super Admin may grant any staff role; Administrator may grant Support and Portfolio Editor. The current staff.invitations.manage capability and assignable_roles describe the same grant authority. Creates credentials, roles, audit and a keyed idempotency receipt atomically. No auto-login or acting-session rotation. Admin-created staff can sign in immediately without email verification, including existing direct accounts. No verification email or token is generated on creation. Email ownership remains truthfully unverified until independently proven; recovery email is retained for password reset. MFA enrollment and subsequent challenges remain mandatory. Administrator-provisioned staff satisfy staff email admission prerequisites across capabilities, reporting, audit, public-content management and AI work; email_verified remains factual and false until proven. This does not grant any extra role permissions, bypass MFA or relax customer and legacy unverified-account controls. No invitation is issued. Existing or pending-invitation emails are rejected; no implicit conversion or revocation. Same actor/key/input replays the same account ID without duplicate accounts or audit; changing input (including password) returns 409. New reasons: USERNAME_UNAVAILABLE and ACCOUNT_UNAVAILABLE. Rate limit: 20 attempts per actor/hour. Existing staff reads keep their exact response shape; onboarding_pending includes new accounts awaiting MFA. Creation replay returns account identity only; read authorization separately for current roles.',
    **{'x-source': ['routes/identity.php', 'app/Modules/Identity/Staff/StaffController.php', 'app/Modules/Identity/Staff/CreateStaff.php'], 'x-frontend-feature': 'Direct staff accounts'})
create['requestBody']['content']['application/json']['schema'] = ref('StaffAccountCreateInput')
create['responses']['201']['description'] = 'Account created, or idempotent replay. Never returns credentials.'
create['responses']['201']['headers'].pop('ETag', None)
create['responses']['201']['content']['application/json']['schema'] = ref('StaffAccountResponse')
for code, response in create['responses'].items():
    if not code.startswith('2'):
        response['content']['application/json']['schema'] = ref('StaffAccountErrorEnvelope')
login = copy.deepcopy(spec['paths']['/api/v1/auth/login']['post'])
login.update(operationId='identityLoginWithUsername', summary='Sign in staff using their username',
    description='Same CSRF, session rotation and MFA behavior as email login. Case-insensitive, trimmed username. Both aliases share the email account throttle (5 attempts/minute) and IP limit. Missing, disabled or wrong credentials return generic 401. Email verification is not a prerequisite for admin-created staff using either username or email login. Both existing and newly created direct accounts proceed to mandatory MFA without falsifying email ownership. Successful first factor returns 202 mfa_enrollment or mfa_challenge. Legacy email login is preserved.',
    **{'x-source': ['routes/identity.php', 'app/Modules/Identity/Http/Controllers/AuthController.php', 'app/Modules/Identity/Actions/Authentication.php'], 'x-frontend-feature': 'Direct staff sign-in'})
login['requestBody']['content']['application/json']['schema'] = ref('UsernameLoginInput')
login['responses'].pop('200', None)
login['x-validation-fields'] = ['username', 'password']
fragment = {'paths': {'/api/v1/identity/staff': {'post': create}, '/api/v1/auth/username-login': {'post': login}}, 'components': {'schemas': schemas}}
(ROOT / 'docs/contracts/staff-accounts.json').write_text(json.dumps(fragment, indent=2, ensure_ascii=False) + '\n')
