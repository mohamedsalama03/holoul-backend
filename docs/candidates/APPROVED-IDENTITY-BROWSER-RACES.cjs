'use strict';
// Real Chrome + unchanged dashboard + local HTTPS. No mocked response or application hook.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const frontend = 'D:\\customers\\holoul frontend\\dashboard';
const { chromium } = require(path.join(frontend, 'node_modules/@playwright/test'));
process.loadEnvFile(path.join(frontend, '.env.e2e.local'));
const origin = 'https://localhost:8443';
assert.equal(new URL(process.env.HOLOUL_E2E_ADMIN_URL).origin, origin);
const output = path.join(__dirname, process.env.RACE_REPORT || 'browser-races.json');
const result = { started_at: new Date().toISOString(), origin, backend: 'real', response_mocking: false, frontend_modified: false, iterations: [], passed: false };
const usedSteps = new Set();
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
let stage = 'launch';

function totp(secret, step) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = 0, value = 0;
  const bytes = [];
  for (const char of secret.replace(/[\s=-]/g, '').toUpperCase()) {
    const index = alphabet.indexOf(char);
    assert.ok(index >= 0);
    value = (value << 5) | index;
    bits += 5;
    if (bits >= 8) { bytes.push((value >>> (bits - 8)) & 255); bits -= 8; }
  }
  const message = Buffer.alloc(8);
  message.writeBigUInt64BE(BigInt(step));
  const hash = crypto.createHmac('sha1', Buffer.from(bytes)).update(message).digest();
  return String((hash.readUInt32BE(hash[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
}
async function login(page) {
  stage = 'login page';
  const probe = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/identity/me');
  const navigation = await page.goto(origin + '/admin/login', { waitUntil: 'load' });
  assert.equal(navigation.status(), 200);
  assert.equal((await probe).status(), 401);
  stage = 'login form fields';
  await page.getByLabel('Email', { exact: true }).fill(process.env.HOLOUL_E2E_STAFF_EMAIL);
  await page.getByLabel('Password', { exact: true }).fill(process.env.HOLOUL_E2E_STAFF_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  stage = 'first-factor response';
  await page.waitForURL(/\/admin\/login\/verify/, { timeout: 20000 });
  stage = 'MFA form';
  let step = Math.floor(Date.now() / 30000);
  while (usedSteps.has(step)) { await pause(1000); step = Math.floor(Date.now() / 30000); }
  usedSteps.add(step);
  await page.getByLabel('Authentication code').fill(totp(process.env.HOLOUL_E2E_STAFF_TOTP_SECRET, step));
  await page.getByRole('button', { name: 'Verify', exact: true }).click();
  stage = 'MFA completion response';
  await page.waitForURL(origin + '/admin', { timeout: 20000 });
  assert.equal(await me(page), 200);
}
async function me(page) {
  return page.evaluate(async () => (await fetch('/api/v1/identity/me', { credentials: 'same-origin', headers: { Accept: 'application/json' } })).status);
}
async function post(page, url, body) {
  return page.evaluate(async ({ url, body }) => {
    const token = decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.slice(11) || '');
    return (await fetch(url, { method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token }, body: JSON.stringify(body) })).status;
  }, { url, body });
}
const stateNames = new Set(['__Host-holoul_session', 'XSRF-TOKEN']);
function responseCookieNames(headers = []) {
  return headers.filter(h => h.name.toLowerCase() === 'set-cookie').flatMap(h => h.value.split('\n').map(v => v.slice(0, v.indexOf('='))));
}
async function jar(context) {
  const cookies = await context.cookies(origin);
  return Object.fromEntries(cookies.filter(c => stateNames.has(c.name)).map(c => [c.name, c.value]));
}
function sameJar(a, b) { return [...stateNames].every(name => a[name] === b[name]); }
async function holdOverview(context) {
  // Three actual dashboard tabs share the browser session and load concurrently.
  const gates = await Promise.all(Array.from({ length: 3 }, async () => {
    const page = await context.newPage();
    const cdp = await context.newCDPSession(page);
    const pending = [];
    let holding = true;
    cdp.on('Fetch.requestPaused', async event => {
      if (holding) pending.push(event);
      else await cdp.send('Fetch.continueResponse', { requestId: event.requestId }).catch(() => {});
    });
    await cdp.send('Fetch.enable', { patterns: [{ urlPattern: origin + '/api/v1/admin/reports/*', requestStage: 'Response' }] });
    return { page, cdp, pending, release: async () => {
      holding = false;
      await Promise.all(pending.map(event => cdp.send('Fetch.continueResponse', { requestId: event.requestId })));
      await cdp.send('Fetch.disable');
    } };
  }));
  stage = 'concurrent Overview navigation';
  await Promise.all(gates.map(gate => gate.page.goto(origin + '/admin', { waitUntil: 'domcontentloaded', timeout: 20000 })));
  stage = 'concurrent Overview response barrier';
  const deadline = Date.now() + 20000;
  while (gates.some(gate => !gate.pending.length) && Date.now() < deadline) await pause(50);
  assert.ok(gates.every(gate => gate.pending.length >= 1), 'Every real Overview must reach a response barrier.');
  const details = () => gates.flatMap(g => g.pending.map(event => ({ path: new URL(event.request.url).pathname,
    status: event.responseStatusCode, cookie_names: responseCookieNames(event.responseHeaders) })));
  return { gates, get details() { return details(); }, release: async () => {
    await Promise.all(gates.map(g => g.release()));
    await pause(300);
  }, close: async () => { await Promise.all(gates.map(g => g.page.close())); } };
}
async function staleRead(page, oldJar) {
  // Send real old browser cookies. Restore S2 before releasing the unmodified
  // stale response; do not rely on overriding the forbidden Cookie header.
  const context = page.context();
  const current = (await context.cookies(origin)).filter(c => stateNames.has(c.name));
  const cdp = await context.newCDPSession(page);
  let names = [], status, interceptionError;
  cdp.on('Fetch.requestPaused', async event => {
    try {
      names = responseCookieNames(event.responseHeaders);
      status = event.responseStatusCode;
      await context.addCookies(current);
      await cdp.send('Fetch.continueResponse', { requestId: event.requestId });
    } catch (error) { interceptionError = error; }
  });
  await cdp.send('Fetch.enable', { patterns: [{ urlPattern: origin + '/api/v1/identity/me', requestStage: 'Response' }] });
  await context.addCookies(current.map(cookie => ({ ...cookie, value: oldJar[cookie.name] })));
  assert.equal(await me(page), 401);
  await cdp.send('Fetch.disable');
  await cdp.detach();
  assert.ok(!interceptionError, 'Stale response barrier must complete.');
  assert.equal(status, 401);
  assert.deepEqual(names, []);
  return { status, cookie_names: names, actual_old_cookie_jar_sent: true };
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  result.chrome_version = browser.version();
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  result.auth_responses = [];
  page.on('response', response => {
    const pathname = new URL(response.url()).pathname;
    if (pathname.startsWith('/api/v1/auth/') || pathname === '/api/v1/identity/me' || pathname === '/sanctum/csrf-cookie') result.auth_responses.push({ path: pathname, status: response.status() });
  });
  try {
    stage = 'staff sign-in';
    await login(page);
    for (const transition of ['revoke', 'revoke', 'revoke', 'logout-login', 'logout-login', 'logout-login']) {
      stage = transition + ' iteration ' + (result.iterations.length + 1);
      const oldJar = await jar(context);
      const held = await holdOverview(context);
      result.current_attempt = { transition, held: held.details };
      try {
        assert.ok(held.details.length >= 3);
        assert.ok(held.details.every(x => x.status === 200 && x.cookie_names.length === 0));
        if (transition === 'revoke') {
          assert.equal(await post(page, '/api/v1/auth/password/confirm', { password: process.env.HOLOUL_E2E_STAFF_PASSWORD }), 200);
          assert.equal(await post(page, '/api/v1/identity/sessions/revoke-others', {}), 200);
        } else {
          const logoutResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/auth/logout');
          assert.equal(await post(page, '/api/v1/auth/logout', {}), 200);
          const logoutCookies = responseCookieNames(await (await logoutResponse).headersArray());
          assert.deepEqual(logoutCookies, []);
          await page.evaluate(async () => { await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }); });
          await login(page);
        }
        const current = await jar(context);
        assert.ok(typeof current['__Host-holoul_session'] === 'string' && typeof current['XSRF-TOKEN'] === 'string');
        assert.ok(current['__Host-holoul_session'] !== oldJar['__Host-holoul_session'], 'Successful transition must rotate.');
        stage = transition + ' late response delivery';
        assert.ok(held.details.every(x => x.status === 200 && x.cookie_names.length === 0));
        await held.release();
        assert.ok(sameJar(await jar(context), current), 'Late successful Overview response changed browser state.');
        assert.equal(await me(page), 200);
        await held.close();
        stage = transition + ' stale-cookie request';
        const stale = await staleRead(page, oldJar);
        assert.ok(sameJar(await jar(context), current), 'Stale failure changed browser state.');
        assert.equal(await me(page), 200);
        result.iterations.push({ transition, concurrent_overview_responses: held.details, stale, current_session_status: 200, session_and_csrf_unchanged_by_late_responses: true });
        delete result.current_attempt;
        fs.writeFileSync(output, JSON.stringify(result, null, 2));
        console.log(JSON.stringify({ iteration: result.iterations.length, transition, held_responses: held.details.length, survived: true }));
      } finally { await held.close(); }
    }
    stage = 'final CSRF mutation';
    assert.equal(await post(page, '/api/v1/auth/password/confirm', { password: process.env.HOLOUL_E2E_STAFF_PASSWORD }), 200);
    result.final_csrf_mutation = 200;
    result.passed = true;
    result.completed_at = new Date().toISOString();
    fs.writeFileSync(output, JSON.stringify(result, null, 2));
  } catch (error) {
    result.failed_stage = stage;
    result.page_path = new URL(page.url()).pathname;
    result.form_state = await page.locator('form').evaluateAll(forms => forms.map(form => ({ busy: form.getAttribute('aria-busy'), fields: [...form.querySelectorAll('input')].map(input => ({ name: input.name, filled: input.value.length > 0, invalid: input.getAttribute('aria-invalid') })) }))).catch(() => []);
    result.error_type = error.constructor.name;
    if (stage.startsWith('concurrent Overview')) result.harness_error = error.message;
    if (Number.isInteger(error.actual)) result.observed_status = error.actual;
    // No DOM dumps, trace, screenshots, headers, passwords, TOTP secret or session values.
    fs.writeFileSync(output, JSON.stringify(result, null, 2));
    console.error('Browser race gate failed at ' + stage + ' (' + error.constructor.name + ').');
    process.exitCode = 1;
  } finally { await browser.close(); }
})().catch(() => { console.error('Browser race runner failed before safe reporting.'); process.exitCode = 1; });
