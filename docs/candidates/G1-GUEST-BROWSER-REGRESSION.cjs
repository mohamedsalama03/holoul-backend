const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const front = 'D:\\customers\\holoul frontend\\dashboard';
const {chromium} = require(path.join(front, 'node_modules/@playwright/test'));
process.loadEnvFile(path.join(front, '.env.e2e.local'));
const origin = 'https://localhost:8443';
const report = {origin, response_mocking: false, frontend_modified: false, checks: [], passed: false};
const output = path.join(__dirname, 'browser-guest.json');
let stage = 'launch';
async function request(page, method, endpoint, body, headers = {}) {
  const pending = page.waitForResponse(r => r.url() === origin + endpoint && r.request().method() === method);
  pending.catch(() => {});
  const data = await page.evaluate(async ({method, endpoint, body, headers}) => {
    const xsrf = document.cookie.split('; ').find(x => x.startsWith('XSRF-TOKEN='));
    const response = await fetch(endpoint, {method, credentials: 'same-origin', headers: {
      Accept: 'application/json', 'Content-Type': 'application/json',
      ...(xsrf ? {'X-XSRF-TOKEN': decodeURIComponent(xsrf.slice('XSRF-TOKEN='.length))} : {}), ...headers},
      ...(body === undefined ? {} : {body: JSON.stringify(body)})});
    return response.status === 204 ? null : await response.json();
  }, {method, endpoint, body, headers});
  const response = await pending;
  const cookieNames = (await response.headersArray()).filter(x => x.name.toLowerCase() === 'set-cookie').map(x => x.value.split('=', 1)[0]);
  return {status: response.status(), cookieNames, data};
}
async function check(page, method, endpoint, status, body, headers, allowCookies = false) {
  stage = method + ' ' + endpoint;
  const r = await request(page, method, endpoint, body, headers);
  assert.equal(r.status, status);
  if (!allowCookies) assert.deepEqual(r.cookieNames, []);
  report.checks.push({method, path: endpoint.replace(/\/[0-9a-f-]{36}/g, '/{id}'), status: r.status, cookie_names: r.cookieNames});
  return r.data;
}
async function openPage(page) {
  const probe = page.waitForResponse(r => new URL(r.url()).pathname === '/api/v1/identity/me');
  await page.goto(origin + '/admin/login', {waitUntil: 'load'});
  assert.equal((await probe).status(), 401);
}
const identityCookies = async context => (await context.cookies()).filter(c => ['__Host-holoul_session','XSRF-TOKEN'].includes(c.name)).map(c => [c.name, c.value]).sort();
(async () => {
  const browser = await chromium.launch({channel: 'chrome', headless: true});
  try {
    const one = await browser.newContext({ignoreHTTPSErrors: true});
    const two = await browser.newContext({ignoreHTTPSErrors: true});
    const customer = await one.newPage(); const guest = await two.newPage();
    await openPage(customer); await openPage(guest);
    const credentials = {email: process.env.HOLOUL_E2E_CUSTOMER_EMAIL, password: process.env.HOLOUL_E2E_CUSTOMER_PASSWORD};
    assert.ok(credentials.email && credentials.password);
    await check(customer, 'GET', '/sanctum/csrf-cookie', 204, undefined, {}, true);
    await check(customer, 'POST', '/api/v1/auth/login', 200, credentials, {}, true);
    const before = await identityCookies(one);
    await check(customer, 'GET', '/api/v1/intake/categories', 200);
    await check(customer, 'POST', '/api/v1/guest/project-requests', 403, {});
    assert.deepEqual(await identityCookies(one), before);
    await check(guest, 'GET', '/sanctum/csrf-cookie', 204, undefined, {}, true);
    const draft = (await check(guest, 'POST', '/api/v1/guest/project-requests', 201, {})).data;
    const retired = await two.cookies();
    await check(customer, 'GET', '/api/v1/identity/me', 200);
    assert.deepEqual(await identityCookies(one), before);
    await check(guest, 'POST', '/api/v1/auth/login', 200, credentials, {}, true);
    const current = await identityCookies(two);
    const attacker = await browser.newContext({ignoreHTTPSErrors: true});
    await attacker.addCookies(retired);
    const replay = await attacker.newPage(); await openPage(replay);
    await check(replay, 'GET', '/sanctum/csrf-cookie', 204, undefined, {}, true);
    const reservation = {filename: 'guest-session-regression.pdf', bytes: 20, sha256: 'a'.repeat(64)};
    const proof = {'If-Match': draft.etag, 'X-Intake-Capability': draft.capability, 'Idempotency-Key': crypto.randomUUID()};
    await check(replay, 'POST', '/api/v1/guest/project-requests/' + draft.draft_id + '/documents', 404, reservation, proof);
    await check(guest, 'GET', '/api/v1/identity/me', 200);
    assert.deepEqual(await identityCookies(two), current);
    await check(guest, 'POST', '/api/v1/auth/logout', 200, {});
    await check(guest, 'GET', '/sanctum/csrf-cookie', 204, undefined, {}, true);
    await check(guest, 'POST', '/api/v1/auth/login', 200, credentials, {}, true);
    await check(guest, 'POST', '/api/v1/guest/project-requests/' + draft.draft_id + '/documents', 403, reservation, proof);
    await check(customer, 'GET', '/api/v1/identity/me', 200);
    assert.deepEqual(await identityCookies(one), before);
    report.passed = true;
    report.retained_fixture = 'One empty anonymous draft, no contact, submission or uploaded document; normal G1 retention applies.';
    report.chrome = browser.version();
  } catch (error) {
    report.failed_stage = stage; report.error_type = error.constructor.name;
    if (stage === 'GET /sanctum/csrf-cookie') report.harness_error = error.message.split('\n')[0];
    if (Number.isInteger(error.actual)) report.observed_status = error.actual;
    process.exitCode = 1;
  } finally { fs.writeFileSync(output, JSON.stringify(report, null, 2)); await browser.close(); }
  console.log(JSON.stringify({passed: report.passed, checks: report.checks.length, failed_stage: report.failed_stage}));
})().catch(() => { console.error('Guest browser gate failed before safe reporting.'); process.exitCode = 1; });
