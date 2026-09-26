# HOLOUL — identity session rotation race remediation

**Implementation and required verification completed on 2026-09-26. Awaiting security review; G1 remains paused. No production release is claimed.**

## Baseline and scope

Backend HEAD remains `96445baded70ddd2e4d8b8617793c78a4a3e1816` on `main`. The checkout already contained the uncommitted B8/P1/P2/P3/F1-E1 candidate and unfinished G1. Its 720 source files were inventoried before this remediation in `artifacts/identity-session-race/baseline.json` and archived privately. No reset, staging, commit, branch, PR, migration, dependency update, frontend implementation or production deployment is included.

To avoid activating paused G1, the tested local runtime is assembled from the preserved **pre-G1 candidate** plus `identity-only.patch`. `runtime-context.json` inventories that exact source: 29 migrations, 164 API operations, no guest routes or guest migration. Remediation application changes also remain in the main checkout for review. The G1 source/unfinished evidence is retained separately; it is not accepted or resumed by these checks.

The frontend is the user's existing `D:\customers\holoul frontend\dashboard` candidate, HEAD `3420d6c91503376c2039cc2c47ed304157dadaad`, already dirty with Overview work and E2E changes. Its 258 source files were separately inventoried. Synthetic test credentials stay in the existing ignored environment. No real administrator credentials, cookies, session IDs, TOTP secret or recovery codes belong in this report or its public evidence.

## Exact cause and framework lifecycle

The pinned framework is **Laravel 13.32.0**, source `cdd8b33c246719acdd118c705ce8c7ab5ef48a96`. The relevant installed code was read, not inferred from another Laravel release:

1. `EncryptCookies` decrypts/validates the incoming cookie. `StartSession::getSession()` assigns that ID to the database-backed encrypted store.
2. `StartSession::handleStatefulRequest()` loads the session. A missing payload becomes an empty store; `Store::start()` creates an in-memory CSRF token when necessary.
3. `SessionSecurity::authenticated()` checks the enabled principal, identity-session record/HMAC, credential generation, absolute/idle bounds and completed staff MFA. Previously its failure branch called the same `invalidate()` used for explicit logout.
4. `Store::invalidate()` flushes attributes and calls `migrate(true)`: destroy the old database session and generate a new random ID. The previous rejection path also regenerated CSRF state.
5. Laravel's routing pipeline renders the authentication exception into the safe 401 response inside the session middleware. `StrictCsrf` previously inherited unconditional XSRF cookie emission; `StartSession` unconditionally added the session cookie and saved the new anonymous store.
6. The late failure therefore carried S3, which could replace an already delivered S2. There was a second variant: an earlier validated **200 read** also reissued its unchanged S1 cookie and old CSRF token when delivered late.

Framework references: [StartSession](https://github.com/laravel/framework/blob/cdd8b33c246719acdd118c705ce8c7ab5ef48a96/src/Illuminate/Session/Middleware/StartSession.php), [Store](https://github.com/laravel/framework/blob/cdd8b33c246719acdd118c705ce8c7ab5ef48a96/src/Illuminate/Session/Store.php), [DatabaseSessionHandler](https://github.com/laravel/framework/blob/cdd8b33c246719acdd118c705ce8c7ab5ef48a96/src/Illuminate/Session/DatabaseSessionHandler.php), [PreventRequestForgery](https://github.com/laravel/framework/blob/cdd8b33c246719acdd118c705ce8c7ab5ef48a96/src/Illuminate/Foundation/Http/Middleware/PreventRequestForgery.php). Laravel supports [application middleware replacement](https://laravel.com/framework/docs/13.x/middleware); no vendor file was patched.

## Call-site classification

| Class | Call sites | Disposition |
|---|---|---|
| A — explicit authenticated security action | Password change, revoke-others, recovery-code regeneration invoke `revokeAll()` and then `completeLogin()` | Existing generation changes, revocation, locking and new-ID rotation remain. |
| B — successful authentication/rotation | Customer login; staff first-factor `beginStaffLogin()`; MFA challenge, enrollment confirmation and recovery via `completeLogin()` | Successful transitions still destroy/rotate IDs and regenerate CSRF. New cookies are issued. `SessionGuard::login()` retains its own fixation rotation too. |
| C — explicit logout | `Authentication::logout()` → `SessionSecurity::invalidate()` | Audit, server revocation, flush, ID regeneration and token regeneration remain. The disposable anonymous result is not saved or emitted. No clearing cookie can arrive late and erase a later login. |
| D — stale/revoked/missing/expired/disabled/hash-invalid incoming authentication | `SessionSecurity::authenticated()` → new `reject()`; early `WithAuthorizedIdentity` authentication failures | Terminal safe 401. Rejection does not call login/logout or regenerate an anonymous session. No session/XSRF cookie or replacement persistence. |
| E — security failures and security exit | CSRF/Origin failures; bad password/MFA; stale MFA/generation; failed authentication transaction; MFA disable | Error responses cannot persist or issue session state. MFA disable uses the same explicit no-cookie invalidation as logout. Existing audit semantics remain. |
| Intentional anonymous initialization | Existing GET `/sanctum/csrf-cookie` | Fresh browser bootstrap still creates the normal secure session/XSRF pair. An existing valid cookie need not be reissued; a matching readable CSRF cookie is not reissued either. Missing/mismatched CSRF state is repaired intentionally. |

Password reset and staff authorization changes keep their existing generation/revocation behavior without an automatic login. The old sessions subsequently fail safely. No lifetime, bearer-token, verification, MFA, CSRF token matching, Origin, authorization or rate-limit requirement was weakened.

## Fix and changed files

| File | Change |
|---|---|
|`app/Infrastructure/Http/SessionResponse.php`|Request-local response disposition; permits persistence only on successful, non-discarded paths. Cookies are reserved for intentional bootstrap/new IDs. A shared object survives Laravel FormRequest's copied attribute bag. It is never client supplied or stored in a session.|
|`app/Infrastructure/Http/StartSecureSession.php`|Extends the pinned framework middleware through its protected lifecycle methods, preserving load, garbage collection, current-URL handling and save. Saves before issuing a permitted cookie. Ordinary reads never reissue their unchanged ID.|
|`app/Infrastructure/Database/WrappedConcurrencyErrors.php`, `app/Providers/AppServiceProvider.php`|Preserve SQLSTATE detection through Laravel nested-transaction wrappers using the framework concurrency-detector contract. Existing transaction attempts, locks and isolation remain unchanged.|
|`tests/Unit/WrappedConcurrencyErrorsTest.php`, `tests/Feature/Reporting/ReportingHttpTest.php`|Check narrow wrapper handling and force a real PostgreSQL serialization conflict on an independent connection; prove the entire authorized report snapshot is retried.|
|`bootstrap/app.php`|Selects the application session middleware in the existing first-party group.|
|`app/Modules/Identity/Http/StrictCsrf.php`|Uses the protected cookie-emission hook to suppress stale/error/ordinary-read XSRF cookies. Token verification and exact Origin rules are unchanged.|
|`app/Modules/Identity/Security/SessionSecurity.php`|Separates passive rejection from explicit invalidation. Reject destroys invalid authenticated/pending state, preserves a legitimate anonymous CSRF bootstrap, flushes rejected local data and forgets request guards without a login/logout audit event. Logout marks its disposable state as non-persistent.|
|`app/Modules/Identity/Mfa/MfaActions.php`|MFA disable uses the shared explicit invalidation path.|
|`tests/Feature/Identity/SessionLifecycleTest.php`, `tests/Fixtures/session-lifecycle-worker.php`|Actual HTTP-kernel requests, encrypted cookies, CSRF, real PostgreSQL storage and independent process races.|
|`tests/Feature/Identity/IdentityHttpTest.php`|Two existing logout→login scenarios explicitly call the real CSRF initialization endpoint before the next login. Their password-literal, MFA/recovery, expiry and original status assertions are preserved; two new bootstrap assertions are added.|
|`tests/Feature/Identity/SessionPruningTest.php`|Keeps the zero-Redis pruning assertion isolated from optional slow-query telemetry, including migration teardown. Adds a forced slow-query/Redis-outage check proving pruning still completes.|
|`tests/Architecture/FoundationArchitectureTest.php`|Requires the application subclass on every protected route; all existing route/auth/Origin/CSRF checks remain.|
|`docs/contracts/source-manifest.json`|Refreshes reviewed implementation hashes; API bytes and route/schema shapes are unchanged.|

This uses framework session and CSRF extension points, not a header-string deletion hack. The routing pipeline's error response still returns through the middleware; both cookie and save decisions therefore see the final error status. The database driver's existing-row write uses UPDATE rather than insert-on-missing: a read that loaded S1 before its deletion cannot recreate that authenticated row when it later saves. Independent races verify this property against the pinned driver. Framework upgrades must rerun these lifecycle tests.

Logout intentionally leaves an inert browser cookie until the next explicit bootstrap/login. Its server authority is already destroyed. The normal CSRF endpoint can prepare an anonymous login attempt, and the next successful authentication still rotates the ID. This avoids an order-sensitive logout clearing/replacement cookie. Anonymous bootstrap is an explicit state-initialization request, distinct from a failed authenticated request.

## Observable response policy

| Request/result | Session cookie | XSRF cookie | Persistence |
|---|---|---|---|
|Invalid/stale authentication, 401; CSRF/Origin/security error|None|None|No replacement save. Invalid authenticated/pending state is destroyed when rejected.|
|Anonymous current-user probe, 401|None|None|Existing anonymous CSRF row is preserved, not renewed; no new row is saved.|
|Ordinary successful authenticated read or non-rotating command|None|None|Existing session may be updated; a deleted loaded row is not recreated by the database handler.|
|Successful login, pending-staff transition, MFA completion or authenticated rotation|Fresh rotated ID|Fresh token|New session saved; old authority stays revoked.|
|Explicit logout or MFA disable, 200|None, including no expiration cookie|None|Server invalidation remains; disposable anonymous result is discarded.|
|Fresh explicit CSRF bootstrap, 204|Initial ID|Initial token|Intentional anonymous state saved.|
|Explicit CSRF bootstrap with an existing cookie, 204|None|Only when missing/mismatched|Repairs intentional bootstrap; matching state produces no cookie.|

Session storage/cookie maximum stays seven days; customer absolute lifetime is seven days and staff absolute lifetime is twelve hours. Removing read-time cookie refresh therefore does not shorten an authenticated session below its existing absolute limit. Persona idle enforcement remains in PostgreSQL identity metadata. API payload/status shapes, OpenAPI bytes, routes, migrations, dependencies and session/identity configuration did not change.

## Verification evidence

The initial focused run passed **16 tests / 733 assertions**. The final suite expands to eight transition/barrier combinations, each run three times with three independent HTTP workers (72 late reads), plus an independently delayed logout response. The parent holds a PostgreSQL advisory barrier, observes all workers waiting in `pg_stat_activity`, performs the real transition, then releases them. Distinct backend PIDs prove separate database connections. This is not an in-memory session or timing-only race.

| Race | Focused evidence |
|---|---|
|Revoke-others vs reads|3 rounds blocked after session load/before authentication, plus 3 rounds blocked after a successful read. S2 remains usable, old reads have no state cookies, S1 cannot authenticate or resurrect.|
|Logout → login vs old reads|Same two timings, 3 rounds each. Real CSRF bootstrap and new login complete before old responses are applied.|
|MFA completion|3 pending-P1 rounds, each with three old requests released after the real TOTP completion. S2 survives; P1 cannot authenticate. Three additional rounds delay a successful CSRF bootstrap response from P1 until after MFA completes; a matching old CSRF token is not reissued.|
|Password change|Both timings, 3 rounds each. New password/session and CSRF mutation remain usable.|
|Delayed logout response itself|Logout executes in a worker and its response waits; new bootstrap/login succeeds first. The late logout response emits neither a replacement nor an expiring state cookie.|

The new rejection cases cover missing session storage, deleted identity receipt, changed generation, disabled user, idle expiry, absolute expiry and hash mismatch. They assert no replacement cookies, no increase in anonymous session rows, and no misleading login/logout audit. Fresh anonymous CSRF, a passive anonymous current-user 401 followed by login, repeat CSRF bootstrap with unchanged cookies, login ID rotation and bad-password-without-session-loss are explicitly covered. Every race checks a subsequent CSRF-protected mutation as well as the current-user read.

The same two sentinel tests against the unchanged old image fail on actual session/XSRF cookie emission (`baseline-red-2.xml`). Initial local attempts and their corrections are preserved: the expiry fixture had to obey the existing session-shape constraint; worker telemetry was separated onto stderr so its private result pipe remains valid JSON; a request-local scalar discard flag had to become a shared object because FormRequest copies attributes. No inherited assertion or security requirement was removed.

The first complete affected-feature run executed 258 tests and had exactly two failures: immediate new-login attempts in inherited tests had depended on the anonymous cookies formerly issued by logout. Their setup now uses the real CSRF endpoint between logout and login, matching the existing frontend's explicit post-logout refresh. All original assertions remain. The complete gate was rerun from rebuilt images; the failed run is retained rather than reported as passed.

The second complete gate resolved both setup failures, but failed the zero-Redis pruning assertion when optional slow-query telemetry ran during migration teardown. A deterministic diagnostic (zero slow-query threshold) recorded the exact `OperationsServiceProvider → MetricRecorder → Redis` stack. The original zero-call assertion is retained with telemetry excluded from that dependency test; an additional forced-telemetry Redis-outage test checks that pruning still succeeds.

A later CSRF sentinel also reproduced unconditional XSRF emission on repeat bootstrap. Cookie emission now checks the decrypted incoming XSRF token, and passive anonymous identity probing retains its valid CSRF row without saving or issuing cookies. PHPStan rejected a redundant string check; the type-correct check is retained. All unsuccessful attempts remain in private evidence.

Final focused verification passed **24 tests / 863 assertions**: 18 session-lifecycle cases with 830 assertions, plus six pruning cases with 33 assertions. Pint and PHPStan level 10 passed on the final application/test changes. The rebuilt development and runtime images each match all 417 implementation-source manifest entries; the isolated source has zero secret-scan findings.

The first rebuilt cookie-remediation candidate passed **152 unit/architecture tests (40,346 assertions)** and **261 affected feature tests (4,034 assertions)**, with no failures, errors or skips. Composer strict validation/platform/audit, Pint, PHPStan 10, FPM, nginx, production-mode local smoke and both image builds passed.

### Additional integration finding: nested serialization retry

Real Chrome then exposed an existing PostgreSQL `40001` during concurrent authorized Overview reads. This is a serialization conflict, although Laravel reports it as `DeadlockException`. PostgreSQL logs identify the locking read of `identity_sessions`; no session values are included in review evidence. `ReportingApi` starts a repeatable-read snapshot, then `WithAuthorizedIdentity` enters a nested transaction. A concurrent session touch commits after the snapshot was taken. Laravel wraps the resulting PDO exception in `DeadlockException`, while its default detector recognizes `40001` only on a PDO exception. The outer transaction therefore did not perform its already-configured second attempt.

`WrappedConcurrencyErrors`, bound through Laravel's supported `ConcurrencyErrorDetector` contract, unwraps **only the framework's nested-transaction exception**, then delegates classification to the pinned framework detector. No endpoint workaround, retry-count increase, authorization change, session-touch suppression, lock relaxation, isolation reduction or vendor patch was added. Framework boundaries: [nested transaction handling](https://github.com/laravel/framework/blob/cdd8b33c246719acdd118c705ce8c7ab5ef48a96/src/Illuminate/Database/Concerns/ManagesTransactions.php) and [default concurrency detector](https://github.com/laravel/framework/blob/cdd8b33c246719acdd118c705ce8c7ab5ef48a96/src/Illuminate/Database/ConcurrencyErrorDetector.php). A deterministic real-PostgreSQL test fails with 500 on the prior image and passes with 200 on this extension, proving exactly two outer attempts and zero leaked transaction depth. A unit test also rejects unrelated wrappers and constraint failures as retry candidates. These focused checks passed **2 tests / 17 assertions**; the complete gate was rerun with all Reporting tests added.

### Browser harness/environment attempts retained

The initial Chrome attempts found the Next.js development server stopped (503). Starting its existing installed runtime required no source/package changes; an immediate pre-ready attempt also returned 503. A subsequent harness attempt entered form data before client hydration; waiting for the existing anonymous current-user probe before filling resolved that harness timing. Three revoke iterations passed before an incomplete run, and another run exposed the actual serialization failure above. The harness closes completed auxiliary pages before deliberately installing old cookies for a stale-request probe, so unrelated focus revalidation cannot borrow its temporary test cookie jar. Actual concurrent Overview requests still run together, and real server responses are delayed/released without mocked bodies or headers. The stale probe uses the actual browser cookie store, since [Playwright documents that Cookie-header overrides are ignored](https://playwright.dev/docs/api/class-route#route-continue). No values are printed or stored in its report.

### Final backend gate

The final `release-2` images passed **153 Unit/Architecture tests / 40,443 assertions** and **287 affected Feature tests / 4,429 assertions**: **440 tests / 44,872 assertions**, zero failures, errors or skips; the command exited 0. The feature filter includes all Identity, authorization/customer isolation, HTTP foundation, local fixture, API contract, admin integration and Reporting tests, plus other matching security regressions. Login/logout, enrollment/challenge/recovery, password confirmation/change/reset, session revocation/generation, Origin/CSRF, tenancy/staff grants, report snapshot consistency and concurrent source authorization are included.

Composer strict validation, platform checks and locked audit passed (no vulnerability advisories); Pint checked 547 files; PHPStan/Larastan level 10 passed. Fresh PostgreSQL migrations and a second no-op migration pass succeeded under the dedicated test/migration identity. Runtime/development Docker builds, FPM and nginx checks, production-mode local smoke (debug false, cached private configuration, AI disabled), runtime exclusion of development fixtures/PHPUnit, and zero secret findings all passed. Both final images match **418** implementation-source manifest entries. The final live application image is the verified development digest; local state still has 29 migrations, database sessions, runtime role `holoul_app`, debug false, and no G1 guest schema.

### Real same-origin Chrome race

On **Chrome 153.0.8010.53**, `https://localhost:8443`, the final-image run passed **six consecutive iterations**: three password-confirmation/revoke-others iterations and three logout/immediate-login iterations. Each loaded three actual Overview tabs concurrently and held their genuine dashboard responses until after S2 arrived. All **18 deliberately delayed report responses** were 200 with no state cookies. In every iteration, a real request sent with the captured old cookie jar returned **401 without Set-Cookie**, and after that response was delivered the new session and CSRF values were unchanged, `/identity/me` returned 200, and the final CSRF-protected password confirmation returned 200. The three logout responses themselves contained no cookie or expiration cookie. Actual UI password/TOTP login was used, with natural TOTP-step waiting to preserve replay protection.

The run exited 0. Independent application telemetry over its exact timestamps reports **44 reporting responses, all 200, with no request-failure exceptions**. Browser context cookies stayed only in memory; the evidence stores statuses, endpoint paths, cookie names and booleans. CDP only delayed/released server responses, without fulfilling mock responses or changing their headers/bodies. The dashboard source was unchanged. This separate concurrent test does not depend on the pre-existing F1 E2E `settled()` waits.

### Frontend F1 result and prior attempt

The unchanged F1 real-backend suite passed **6/6**, **0 skipped**, **0 unexpected**, **0 flaky/retried tests**, exit **0**, in **51.70 seconds**, starting **2026-09-26 21:09:43 UTC**. It covers staff password/MFA/current user/logout, indistinguishable invalid credentials, MFA enrollment and one-use recovery, customer dashboard denial, cross-session revocation and separate API/dashboard CSP. It used installed Chrome, real HTTPS ingress and real synthetic-account passwords/TOTP/recovery codes. Screenshots, video and traces stayed disabled. The fixture was reset again afterward; real administrator credentials were not touched.

**The first F1 invocation did not pass:** two passed, one failed, and three were skipped by serial-suite stop behavior. Its recovery-code flow remained on the verification screen at the navigation assertion, while recovery and the following current-user request both returned 200; the page showed a pending navigation, not an authentication-error alert. No backend or frontend source/test changes, longer timeout, conditional skip or retry setting were introduced between invocations. A fresh synthetic-fixture reset and a complete unchanged rerun passed all six. The earlier failure remains in `frontend-f1-attempt-1.json`, and this is retained as an intermittent development-UI navigation observation, not represented as an entirely clean history.

All **258 inventoried frontend source files** match the pre-remediation baseline, including its pre-existing E2E changes. Backend HEAD and frontend HEAD are unchanged; no commit or staging occurred.

## Review artifacts and Git state

- Backend: `main`, HEAD `96445baded70ddd2e4d8b8617793c78a4a3e1816`; **72 modified/deleted tracked paths and 145 untracked paths** in the already-dirty candidate; **0 staged paths**. The full status is in `verification-summary.json`; it includes preserved pre-existing B8/F1/G1 work, not just this remediation.
- Frontend: HEAD `3420d6c91503376c2039cc2c47ed304157dadaad`; its 258 source hashes remain identical to baseline. The existing Next.js dev server was started from installed dependencies; no frontend package/source change was made.
- Review-only delta: `artifacts/identity-session-race/identity-only.patch` (15 application/test paths relative to the dirty remediation baseline). This report, the implementation source manifest, and the paused G1 report header provide accompanying documentation.
- Final development/app image: `sha256:5d6dc868d0e4b558ee2ae9769f39d7f075b0522799b736b3e434b0faf506de77`.
- Final production-runtime image, smoke-tested locally: `sha256:aaa1b2a63e6d34ef67e6a5c865cf9c2ff25a4b0fef5b7097c2029c3b08369b55`.
- The app container uses the first digest. Existing queue/scheduler containers remain on their prior F1 image; this local HTTP remediation validation did not roll out a production stack.

| Evidence under `artifacts/identity-session-race/` | Result |
|---|---|
|`baseline-red-2.xml`|Old image fails the two cookie-emission sentinels, as expected.|
|`focused-final-2.xml`|24 focused tests, 863 assertions, including 72 concurrent late reads and a separately delayed logout.|
|`serialization-red.xml`, `serialization-green.xml`|Real nested-serialization reproduction: prior 500, corrected 200; 2 focused tests / 17 assertions on the extension.|
|`release-2/quality.log`, `release-2/unit-architecture.xml`, `release-2/security-feature.xml`, `release-2/gate-exit.json`|440 tests / 44,872 assertions, exit 0; all final backend gates passed.|
|`release-2/*-source-proof.json`, `release-2/secret-scan.json`|418 implementation hashes match each final image; zero secret findings.|
|`browser-races.json`, `browser-http-summary.json`|Six real Chrome races; 18 held responses; six stale 401s without cookies; 44 report responses all 200.|
|`frontend-f1.json`, `frontend-f1-exit.json`|Final unchanged F1 run 6/6, no skips, exit 0.|
|`runtime-context.json`, `source-preservation.json`, `live-before.json`, `live-after.json`|Exact pre-G1 runtime source; contract/routes/schema/dependencies/security limits unchanged; no guest schema activated.|
|`verification-summary.json`|Machine-checked aggregate, source preservation, Git snapshot and image identities.|

Earlier failed attempts and the first local activation are retained separately. The evidence directory is private; it must not be published wholesale because some browser failure-context files can contain synthetic enrollment material. The report and summarized JSON do not contain credential or cookie values.

## Boundaries and review

No source of truth moved to Redis, no frontend request serialization was added, and no production configuration/debug/provider was enabled. Synthetic PostgreSQL and local browser verification do not certify target-VPS performance or production rollout. Existing B8 production blockers remain unchanged. The first F1 navigation failure described above remains a development-UI stability observation despite the subsequent unchanged 6/6 pass. Intentional successful authentication/bootstrap operations remain state writers; the invariant proved here concerns stale failures and late non-rotating responses, including logout ordering. Framework upgrades must revalidate both protected middleware hooks and nested-concurrency classification.

The active G1 feature stays paused and its full acceptance remains incomplete. This remediation must be reviewed before G1 or another feature resumes. Any subsequent Laravel middleware/session-driver upgrade must preserve the documented cookie/save policy and rerun the real races.
