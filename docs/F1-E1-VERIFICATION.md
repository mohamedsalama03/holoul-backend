# HOLOUL F1-E1 — local integration verification

**Environment decision: LOCAL SAME-ORIGIN E2E READY.** The browser can reach the existing dashboard and real Laravel authentication through `https://localhost:8443`; synthetic identities and repeatable reset are ready.

**Frontend F1 acceptance is still BLOCKED.** Running the frontend's actual E2E suite exposed a pre-MFA `/identity/me` probe that invalidates its pending session. This report does not claim that F1 E2E passed. No frontend source or backend authentication behavior was changed to hide that failure. B8 remains unaccepted for production; target-VPS performance certification is still pending.

## Baseline and scope

- Backend baseline HEAD: `96445baded70ddd2e4d8b8617793c78a4a3e1816`; the existing uncommitted B8/P1/P2/P3 candidate was preserved before F1-E1. [Baseline inventory](../artifacts/f1-e1/baseline.json) and private local source archive remain available.
- Frontend repository: `D:\customers\holoul frontend\dashboard`; HEAD `b83358076c87d39df21232ee0088116abc6a8864`, clean tracked working tree before/after. Its existing Next.js process on host port 3001 was reused. Only ignored synthetic environment/test output and a content-preserving file timestamp for HMR were touched.
- No B9/F2, new business endpoint, API schema change, package addition, bearer token, auth bypass, production deployment, certificate generation/trust installation or performance rerun.
- [Setup/reset instructions](F1-E1-LOCAL-INTEGRATION.md) contain the exact commands and environment-key names. No credentials are in this report or tracked source.

## Changes in this enablement

| Files | Change |
|---|---|
|`docker/nginx/nginx.conf`|Local `/admin` proxy with original path, canonical trusted headers, HMR, frontend CSP separation, 41m dashboard-only body bound, and explicit local FastCGI Host/scheme validation.|
|`Dockerfile`|Physically remove `tools/local-e2e` from the production build. No additional dependencies.|
|`tools/local-e2e/Fixture.php`, `run.php`|Guarded CLI-only synthetic fixture; real enrollment, scoped reset and safe private output. No production command/HTTP route.|
|`scripts/local-e2e.py`|Repeatable local reset and ignored frontend environment export; no credential arguments/log output.|
|`scripts/verify-local-ingress.py`|Fixed-loopback routing, CSP, cookies, hostile headers and body-limit probes.|
|`scripts/verify-f1-e1.sh`|Independent targeted quality/security gate; no initialization/certificate or performance gate.|
|`tests/Feature/LocalE2EFixtureTest.php`|Environment denial, real authentication/enrollment/customer isolation, revocation/reset, collision and invalid-manifest regressions.|
|`docs/contracts/source-manifest.json`|Reviewed Nginx source-hash update only. OpenAPI bytes and all application/config/routes/migrations remain unchanged from the starting candidate.|
|`README.md`, `docs/F1-E1-*.md`|Local run/reset guide, evidence and explicit frontend blocker.|

The [incremental change inventory](../artifacts/f1-e1/changes.json) and [F1-only patch](../artifacts/f1-e1/f1-e1-only.patch) compare against the starting dirty candidate, not against B7 alone, so prior batch work is not mixed into the review patch.

## Exact edge behavior

`/admin` and `/admin/*` proxy to `http://host.docker.internal:3001` without removing `/admin`. `/api/v1/*` and `/sanctum/*` still use the original Laravel FastCGI path. Dashboard requests require HTTPS and exactly `localhost:8443`; provided Origin/Referer must match, and cross-site fetches fail. Origin is not rewritten. Canonical Host/forwarded host/proto/port and the immediate peer replace client-provided forwarded metadata; generic Forwarded/Proxy/prefix are cleared. HTTP/1.1 WebSocket upgrade and unbuffered streaming work.

The dashboard location's own header declaration prevents inheritance of the API CSP; Next.js's nonce CSP and security headers pass through unchanged. The API CSP remains exactly `default-src 'none'; frame-ancestors 'none'`. Only dashboard bodies allow 41m; the API stays at 12m and its application/document limits stay unchanged. Size probes announced Content-Length with Expect:100-continue and sent no upload body or business mutation.

During verification after changing the application from the previous production-mode local image to `APP_ENV=local`, a forged API Host initially returned 200. The installed Laravel middleware explicitly skips trusted hosts in local/testing mode. The final Nginx FastCGI location therefore denies hosts outside the explicit local scheme/authority list before Laravel. This was corrected at the local ingress boundary; no application middleware was weakened or edited. The final real ingress checks include the forged Host with a spoofed forwarded host and now pass.

## Measured verification

| Check | Result |
|---|---|
|All Unit + Architecture tests|152 tests (143 Unit + 9 Architecture), 40,126 assertions; zero errors/failures/skips.|
|Affected Feature/security tests|231 tests, 2,915 assertions; zero errors/failures/skips. Identity/MFA/recovery/concurrency, authorization, customer isolation, HTTP and contract checks included.|
|New fixture focus|4 tests, 66 assertions passed, repeated in the affected suite.|
|PHPStan/Larastan|Level 10 passed across application code and separately across the CLI fixture.|
|Pint|541 files passed.|
|Composer|Strict validation, platform requirements and locked audit passed; no advisories.|
|Docker|Development/runtime builds, Compose validation and PHP-FPM validation passed. Runtime image has no local fixture files and no PHPUnit.|
|Nginx|Final configuration passed `nginx -t` and reload; original certificate digest unchanged.|
|Real HTTPS ingress|45 checks passed, including 18 JS/CSS/font/icon resources, Laravel/Sanctum routing, cookie flags, bad Origin/Host/Referer, spoofed proxy metadata, HTTP rejection and body limits.|
|Actual Chrome rendering/HMR|19 resource responses, zero CSP violations, local origin retained, fonts/images loaded; HMR WebSocket exchanged frames and reacted to a content-preserving file touch.|
|Real Playwright HTTPS auth API transport|24 checks passed: enrolled staff, enrollment and 10 recovery codes, recovery login, customer/admin denial, password confirmation, session revocation, and reproduction of the pending-session probe issue. No HTTP interception or mocked identity.|
|Fixture safety|Missing explicit opt-in rejected before access to fixture data; production/staging/testing/wrong origin/DB/mail/debug profiles rejected by tests; release fixture files physically absent.|
|Ready state after all tests|Final reset leaves staff MFA enrolled with the private exported secret matching, enrollment staff with no pending/confirmed MFA, customer enabled, and zero active synthetic sessions.|
|Contract|All 164 operations and frozen OpenAPI bytes unchanged. Source/matrix consistency passed after reviewed Nginx hash refresh.|
|Network exposure|14 original services running; 13 with passing healthchecks, one updater without a healthcheck. Only existing Nginx loopback ports 8080/8443/8025 are published.|

See [machine summary](../artifacts/f1-e1/summary.json), [Unit/Architecture JUnit](../artifacts/f1-e1/unit-architecture.xml), [Feature JUnit](../artifacts/f1-e1/security-feature.xml), [quality log](../artifacts/f1-e1/security-quality.log), [ingress checks](../artifacts/f1-e1/ingress.json), [browser result](../artifacts/f1-e1/browser.json), [real auth checks](../artifacts/f1-e1/auth-network.json), and [final fixture state](../artifacts/f1-e1/fixture-ready.json). Response bodies, passwords, TOTP secrets, recovery codes and cookies are not in these summaries.

The full unaffected business-domain suite was not rerun for this local proxy/test-tooling change. The final FastCGI Host guard and the runner's unique-evidence-directory option were added after the PHP gate; application PHP and fixture PHP were unchanged. The final Nginx/live routing checks, source freeze and script syntax checks were rerun for those changes. No earlier result is represented as testing a later application-code change.

## Actual frontend E2E result and remaining blocker

Executed `npm run test:e2e` in the real frontend repository with the ignored synthetic environment, one Chrome worker and self-signed TLS accepted only on localhost. **1 failed; 5 skipped by the suite's serial failure behavior.** The staff test reached `/admin/login/verify?next=%2Fproposals` but could not finish MFA.

Independent selection of invalid credentials, customer access denial and CSP separation: **3 passed**. The isolated enrollment test: **1 failed** before showing the enrollment QR, with the same “Your sign-in timed out” message. These reruns are supplementary diagnostics, not a replacement green full-suite result.

Cause confirmed in existing frontend source: `src/components/auth/mfa-challenge.tsx` invokes `useRedirectIfSignedIn()`, as does the enrollment screen. That hook in `src/components/auth/sign-in-flow.ts` calls `checkSession()` and therefore `/identity/me` before MFA has completed. The backend's existing authenticated-session guard returns 401 and invalidates the pending session; the subsequent MFA command gets 401. The real HTTPS probe independently reproduces that sequence and successfully completes the same MFA/revocation flows when no authenticated-user probe interrupts the pending stage.

**Required F1 follow-up:** keep pending MFA flow separate from authenticated session probing; call `/identity/me` after successful MFA confirmation/challenge. Then reset the synthetic accounts and rerun the complete frontend command. Do not weaken backend authentication or suppress the failing test. Session-revocation UI coverage remains blocked by this same staff-login issue; real revocation API operation passed independently. True wall-clock expiry E2E was optional and was not enabled by shortening session lifetimes.

## Earlier attempts retained

The initial fixture tests had two test-only assumptions corrected (an authenticated read destroys a pending session; database timestamp formatting differs before refresh). Initial strict static analysis required type narrowing in the CLI fixture. The first ingress probe had a Python local-variable naming error, corrected before successful checks. The first browser probe treated the frontend's intentionally aborted `/identity/me` request during dev remount as a resource failure; final assertions still reject every asset failure, CSP violation, or unexpected network failure. Actual frontend failures remain unresolved and explicitly reported above. All attempt logs remain in ignored evidence; none was used as a passing result.

## Local runtime, Git and production status

The previous local application used `holoul-app:b8-runtime` in production mode; that immutable image tag remains `sha256:ba964a709c7faae3e0b341b16e79d006c8bc7b38dbf5a12128e14aa3d120a2a4`. The local app/queue/scheduler processes now use the development candidate `sha256:24e1096df9f6da58123dc5aa2582f39f25dfbbf5d895a57ccb0acb54d49b55e0` in explicit local-verification mode, with external AI disabled. The current candidate's pending `2026_09_22_000000_add_customer_directory_permission` migration was applied once through the existing migrator, enabling the already implemented P3 contract. No new migration or business implementation was authored in F1-E1.

The separately built production candidate `sha256:3715b37588a9095caf519d58be7df828d299f039d21bfafe75bf33f5109d0651` verified fixture exclusion; it was not deployed. The mounted final Nginx configuration is the authoritative live edge file. Certificates, volumes and previous B8/P1/P2/P3 evidence were retained; 61 prior P2/P3 artifact/report files were hash-verified. [Final inventory](../artifacts/f1-e1/integrity.json) and [Git status](../artifacts/f1-e1/git-status.json) identify the exact local candidate. No staged changes, branch, commit, tag, push or PR were created. The large existing dirty B8 candidate remains unaccepted.

**Remaining environment blockers: none on this local Docker Desktop/Windows/WSL setup. Remaining frontend blocker: pending-MFA current-user probing, as above. Production remains uncertified pending the target VPS; the prior failed performance threshold/result was neither rerun nor reinterpreted.**

F1-E1 SAME-ORIGIN E2E ENVIRONMENT READY — FRONTEND REAL-BACKEND E2E AUTHORIZED
