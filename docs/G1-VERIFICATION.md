> **Current status (2026-09-27): BACKEND FEATURE READY FOR WEBSITE INTEGRATION.** The external F1 blocker has passed final verification. See [G1-FINAL-ACCEPTANCE.md](G1-FINAL-ACCEPTANCE.md) and the [accepted website handoff](G1-WEBSITE-INTEGRATION.md). The earlier report below is preserved as historical evidence, including its then-blocked classification. Production performance remains pending the target VPS.

---

# HOLOUL G1 — dual project intake verification

**G1 BLOCKED — required frontend F1 regression has not passed.** Backend implementation and its full security/migration/contract gate pass, but G1 acceptance requires F1 6/6. Website integration has not started. This is **not Production Ready**. B8 production performance certification remains pending the target VPS.

## Exact candidate history and scope

- Original main: `96445baded70ddd2e4d8b8617793c78a4a3e1816`; main and the staging index remain unchanged.
- Pre-G1 accumulated B8/P1/P2/P3/F1-E1 candidate: `55b3b79eb205721ac74c2481a92aeff1ae06db25` (`codex/candidate-pre-g1`).
- **Exact resumed G1 baseline: `9940acf6d9e16c7da3cd08b1a6028c875d5accd0`** (`codex/candidate-identity-approved`). This preserves the explicitly approved identity/session/40001 remediation, permanent tests and report independently.
- Final G1 checkpoint: `codex/candidate-g1-review`, a direct child of that identity checkpoint. Exact resulting SHA and tree are recorded in `artifacts/g1-resume/final-candidate.json`; the isolated diff is `g1-from-approved-identity.patch`, with a Git recovery bundle alongside it. No push or PR is created.

See `G1-RESUME-REVIEW.md` for the implemented/incomplete/unsafe/redundant source assessment made before further G1 work. The pre-G1 candidate snapshot contains the original 693 source files. Historical B8/P1/P2/P3/F1 evidence, including failed performance, remains intact: **712 files verified unchanged**. No failed history is squashed. Private raw test evidence is not committed; safe evidence manifests/results accompany the candidate.

Only G1 was implemented. No website frontend, frontend contract copy, F2, new backend feature, paid AI provider or production deployment is included. The frontend HEAD `3420d6c91503376c2039cc2c47ed304157dadaad` is unchanged. All 255 still-present source files outside the three externally changed E2E paths match the approved identity snapshot, including all application and contract files. During this backend gate, `e2e/real/auth.spec.ts` and `e2e/real/overview.spec.ts` changed externally (recorded mtime 21:51:33 UTC), `e2e/support/api-calls.ts` disappeared, and `e2e/support/sign-in-page.ts` appeared. This task made no such edits and did not restore or overwrite them. The current 258-file frontend snapshot was frozen before the final F1 run and verified unchanged afterward; test sources and hashes are preserved in `artifacts/g1-resume/frontend-test-source/` and `frontend-g1-frozen.json`. The approved backend identity tests and independent Chrome race harness remain byte-for-byte unchanged.

## Final verification

Final image-based gate: `bash scripts/verify-g1.sh resume-release-2`, exit 0. Pinned Docker PHP 8.4.25/Composer; real PostgreSQL only. Test DB `holoul_test` uses the dedicated migrator role; runtime remains `holoul_app`. Implementation/test files are embedded in the image, with only reviewed docs and private evidence mounted.

| Gate | Evidence/result |
|---|---|
|Unit + full Feature|937 tests / 12309 assertions; zero failures/errors/skips|
|Architecture|9 tests / 42408 assertions; zero failures/errors/skips|
|**Full PHPUnit total**|**946 tests / 54717 assertions**; zero failures/errors/skips|
|Focused authenticated + guest intake|48 tests / 624 assertions; final guest/authenticated HTTP and session run, not added to full count|
|Guest HTTP / constraints / exact upgrade / domain races / session isolation|39 / 3 / 2 / 8 / 9 tests, respectively; included in full suite|
|Inherited identity/session race tests|18 tests preserved byte-for-byte, including 72 late readers and delayed logout; nested PostgreSQL 40001 unit/integration regressions retained|
|Pint / PHPStan-Larastan|570 files clean; level 10, no errors|
|Composer|Strict validate, locked audit and platform requirements pass; no advisories|
|Migrations|Fresh, repeated no-op and exact 29-to-30 populated upgrade; all 29 old migration hashes unchanged; unused downgrade and used-history refusal verified|
|OpenAPI / route drift|172 structural operations; 2232 validated responses across 145 observed operations; 121 success-covered operations, all 116 required; all eight G1 operations success-covered. Four negative-validator tests and route/source drift checks pass|
|Docker|Development/runtime builds, FPM, Nginx, dev-fixture exclusion, production-mode cache/debug/AI checks pass; 174 registered routes|
|Image/source equivalence|434 implementation files match both images; dependencies remain locked|
|Secret scan|0 source findings|
|Private document inspector|17 tests pass, alongside full inherited B4 document tests|
|Real same-origin identity races|6 iterations, 18 held Overview responses, 6 stale 401 responses without replacement cookies; final CSRF mutation 200|
|Real guest browser|Separate authenticated/guest Chrome contexts; stateless taxonomy, guest-mode rejection, guest draft without replacement cookies, retired-cookie bootstrap denial and logout/login checks pass. One empty synthetic draft retained; no contact, submission or upload created.|
|Frontend F1 — **BLOCKING**|Final: **0 passed / 1 failed / 5 serially skipped**, exit 1. First attempt: 4 passed / 1 failed / 1 skipped. Required 6/6 not met. Frozen current frontend/tests, no edits by this backend task.|

Machine-readable summary: `artifacts/g1-resume/verification-summary.json`; full logs/JUnit/captured contract evidence: `artifacts/g1/resume-release-2/`. Synthetic authentication/contract response captures and the local backup are private. Failed exploratory runs remain separate from accepted evidence and are not added to passing totals.

Development image: `sha256:74f343fde5526ab1115512dcefbb1f53cbaf0603e5fba4a4692cfec9278a7bd4`. Runtime image: `sha256:79727592b752b37249c3f78f5c93af3123dfae14a8c2bd5c93ae2464504d6fa6`.

## Candidate authoritative OpenAPI delta — acceptance pending F1

Previous approved pre-G1/identity contract SHA-256: `1422e14ef0c18204c420080c33f0b597f39cce5093acf358aa15aa1ee980499b`.

Candidate G1 contract SHA-256: `ae8d0a904a0042b80f9adc1224dedf8677794e2d49ca5cdf41daeb5278780be8`.

The authoritative backend contract is updated within this candidate so migration/routes/response validation can run. It is not an accepted contract release while F1 is blocked. Operations: **164 → 172**. All original 164 operation objects (including Admin Dashboard operations and request bodies) remain unchanged. Five schemas added: `GuestIntakeClaimInput`, `GuestIntakeClaimResponse`, `GuestIntakeConfirmationResponse`, `GuestIntakeDraftResponse`, `GuestIntakeSubmissionInput`. `IntakeRevision` adds nullable `submitted_by` and the `guest_submission` provenance variant; existing authenticated revision values remain unchanged. Consumers displaying newly introduced guest history must handle that new variant. The frontend contract copy has not been modified. The exact eight new operations appear below; machine-readable comparison is `artifacts/g1-resume/openapi-delta.json`.

## Schema, ownership and immutable history

One additive migration, `2026_09_26_000000_extend_intake_for_guests.php`, extends the 29-migration pre-G1 candidate to 30 migrations. None of those 29 prior migration files is edited; the upgrade test verifies their exact baseline SHA-256 values.

| Change | Enforced behavior |
|---|---|
|`project_requests.guest_origin`|Immutable origin flag, false for every existing row. Only guest-origin requests can start with NULL customer/user. No fake customer. Unclaimed states cannot enter commercial/project phases.|
|`intake_guest_access`|One canonical parent, hashed draft capability and browser binding, short expiry, final-submission key/input receipt, hashed claim token and claim expiry. Unique browser/final-key pair prevents cross-draft key reuse.|
|`intake_guest_claims`|Append-only claim receipt. Unique request, token and customer-user/key. Composite customer/user FK remains. A database-stamped transaction ID permits the sole unclaimed-to-owned transition.|
|Root/draft/document mutation guards|Existing owned identities remain immutable. A claim must update root, mutable draft and all associated document authorization ownership in one transaction; a deferred constraint rejects partial commits.|
|Guest revision author/contact|Original guest revision has `guest_submission` provenance and NULL original author/customer. Later claim/profile changes never rewrite it. Customer amendments create normal subsequent immutable revisions.|
|Document origin and attachment FKs|Only a canonical unclaimed guest parent permits newly inserted nullable ownership. Every new child must match its current parent owner; historic NULL rows remain unchanged after claim. New unconditional parent/document relationships close the nullable-composite-FK gap. Stored key/version/checksum, original uploader and historical attachment rows remain immutable.|
|Indexes|Guest document reservation identity/parent, abandoned draft selection, claim customer, and receipt uniqueness support bounded queries/serialization.|

An unused G1 extension can downgrade while restoring the exact old ownership/nullability guards. Any guest-origin request, including an empty draft or already claimed request, makes downgrade fail before deleting anything. Once used, recover with a reviewed forward migration or restore the verified pre-G1 database and application together. No destructive history rewrite is offered.

The exact upgrade test populates pre-G1 authenticated requests, immutable revisions and a quarantined document, snapshots every existing table/column value, upgrades, compares them unchanged, checks repeat migration, and verifies unused-extension downgrade/re-upgrade. A separate test proves downgrade refuses retained guest history atomically. These are synthetic candidate-schema tests, not a production-data rehearsal.

## API and workflow

OpenAPI 3.1.1, version `1.1.0-g1`, adds eight operations. All original 164 operation objects, including their request bodies, remain unchanged. The shared revision schema adds nullable `submitted_by` and `guest_submission` provenance for the new case; existing customer revisions keep their prior values.

| Method | New `/api/v1` path |
|---|---|
|GET|`/intake/categories`|
|GET|`/intake/categories/{category}/subcategories`|
|POST|`/guest/project-requests`|
|POST|`/guest/project-requests/{projectRequest}/documents`|
|PUT|`/guest/project-requests/{projectRequest}/documents/{document}/content`|
|GET|`/guest/project-requests/{projectRequest}/documents/{document}`|
|POST|`/guest/project-requests/{projectRequest}/submissions`|
|POST|`/project-request-claims`|

Authenticated submission reads and locks current verified profile contact server-side. Forged contact/customer fields remain rejected. Incomplete required profile data reports the explicit safe `profile_complete_required` validation field; it does not switch to anonymous submission.

Guest fields remain in the page until final submission; an empty canonical draft supplies optional-upload identity. A random 256-bit capability is returned once, persisted as a hash, bound to the browser session and valid for 30 minutes. Final submission uses the same taxonomy, exact-money parser, canonical revision writer and document attachment snapshot as the authenticated path. The same normalized input/key/original precondition can replay its receipt within that capability lifetime. Different key/body conflicts; independent intentional ideas are not deduplicated by email.

Every email receives the same account/sign-in/verification/claim continuation. No account lookup, automatic association, fake customer or guest private-request reader exists. Claim requires both a currently authenticated enabled verified matching customer and an independent 72-hour single-purpose token. Persistence is hashed. Exact same-owner/same-key retries replay the receipt without another association; expired unconsumed claims, wrong identities, different keys and terminal parents fail generically. Reference and email alone authorize nothing.

## Documents, abuse controls and audit

Guest PDF/DOCX attachments reuse the existing B4 generated private quarantine keys, size/checksum checks, bounded inspection, malware scanning and durable PostgreSQL scan/delete intent. Maximum size is 10 MiB with one draft slot and a 10-minute upload reservation. Capability/session/expiry/ETag/attachment checks run before storage I/O and again before finalization. No guest download exists, including after scanning. Claim changes authorization ownership without copying or uploading the file again; Quarantined files still cannot download. Original nullable attachment ownership/history remains intact.

The G1 HTTP document cases use an in-memory object store and deterministic scanner/inspector doubles to exercise authorization, streaming, quarantine, claim and immutable attachment history. Existing B4 document regressions and the separate 17-test inspector suite are also run. This is not a claim of a new live-browser guest upload E2E run or production storage certification.

PostgreSQL serializes the anonymous storage cap (500 MiB, 100 active documents, 50 pending uploads). Claims acquire the existing customer quota lock and enforce 1 GiB/1000 documents; a quota rejection rolls back the entire claim and its completion audit. Expired, unclaimed draft attachments older than 24 hours are retired through the existing scheduled expiry/deletion pipeline. Submitted history is excluded.

Guest JSON is bounded to 128 KiB. Public taxonomy GETs are stateless with exact-origin and 60/IP/minute limits. Guest mutations require explicit CSRF bootstrap and retain exact-origin, capability/session binding, safe errors/request IDs, UUIDv7 and quoted parent ETags, while never persisting or emitting authentication session state. Dedicated Redis limits fail closed: create 5/IP/session/hour plus 100/hour globally; submit 15/IP/session/hour; claim 10/IP/session/minute; other guest operations 60/IP/session/minute. Redis is not authoritative for ownership, history, receipts, work intent or file quotas.

Audit includes guest draft creation, document attachment, guest/authenticated submission, claim issuance/attempt/completion/expiry/rejection and throttling. Rejected claims are audited outside the rolled-back business transaction; completion commits atomically. Metadata excludes contacts, descriptions, document contents, capabilities and claim tokens. Existing safe HTTP telemetry records status/family/correlation, not bodies. No external email to a guest address or identity-specific notification event is fabricated before claim; canonical intake intent is retained.

No anonymous AI operation is approved or enabled. Guest submission works with AI disabled. After ownership is established, existing B7 source binding, explicit suggestion application, privacy/cost controls and Available-document checks remain. Unclaimed staff can read/review/reject within existing permissions; information requests, Discovery, Proposals, conversion and private AI sources require claim first.

## Inherited identity and G1 transition security

Every approved identity implementation file, SessionLifecycleTest, its worker, WrappedConcurrencyErrors unit test and ReportingHttpTest remain byte-for-byte equal to the approved checkpoint. The architecture test only adds the new G1 schema/routes and exact stateless public-GET assertions; existing identity assertions remain.

G1 session isolation tests cover a guest browser while another authenticated browser exists; rejection of guest mode in the authenticated browser; stateless public taxonomy with absent/current/stale cookies; stale authenticated requests concurrent with guest/public work; login while guest work is delayed; claim before and after concurrent session rotation; explicit claim after customer login; denied staff claim before/after MFA; logout/CSRF bootstrap/login while an old draft capability exists. Independent HTTP kernel processes and PostgreSQL advisory barriers provide real concurrency. Guest responses do not save or emit authentication state; claim uses the inherited locked authorization transaction and ordinary responses do not reissue cookies.

A draft capability binds the session ID and its server-side CSRF generation; it expires on rotation and cannot be revived by bootstrapping a retired cookie. Keep unsent form fields locally and use authenticated intake after login; do not silently transfer anonymous attachments. A submitted request's independent claim token survives login/logout subject to its original expiry, verified matching customer and one-time receipt rules. Successful staff MFA does not make a staff identity a customer claimant. These transitions add no new identity feature.

## Concurrency evidence

Eight G1 race cases launch two independent PHP/PostgreSQL processes. `pg_stat_activity` confirms both wait on the coordinated lock barrier; backend PID assertions prove separate connections. No timing-only in-memory simulation substitutes for PostgreSQL locking.

| Race | Valid committed outcome |
|---|---|
|Same draft, same submit key|Both callers receive the same result; one revision, intent and submission audit.|
|Same draft, different submit keys|One succeeds, one conflicts; one revision.|
|Submit vs document finalization|Submit succeeds only after complete Quarantined attachment, or conflicts and safely retries; no incomplete revision attachment.|
|Same claim, same key|One receipt/association; stable replay.|
|Same claim, different keys|One association; other request generically rejected.|
|Two authenticated customers attempt association|Only the verified email-matching identity can claim even if both know the token. Claim is the only association command; direct reassignment is separately blocked by SQL guards.|
|Claim vs customer withdrawal|Claim commits; withdrawal using the pre-claim authority/version receives 404 or 412. No lost claim/history.|
|Profile change vs authenticated submit|Snapshot is a coherent old or new profile tuple under identity/customer locking, never mixed name/phone.|

## Earlier attempts and corrections

- Initial strict analysis identified type narrowing/annotations; Pint and PHPStan corrections were made before final gates.
- Initial HTTP tests exposed draft persistence before taxonomy relationship validation, which could produce a database error instead of safe 422. Shared submission now validates the active pair before any revision/draft persistence.
- Test limiter namespaces initially reused the cached Redis manager configuration, causing false 429 results across repeated runs; tests now rebuild that manager per unique namespace. The actual production limits/fail-closed behavior were not relaxed, and shared Redis was not flushed.
- Race/constraint harness corrections use a persisted identity barrier for pre-claim withdrawal and catch commit-time PDO exceptions. The taxonomy fixture advances its required optimistic version.
- Historical B5/B6/B7/B8 upgrade tests now explicitly target their original pre-G1 migration boundary while preserving their prior assertions. G1 has a separate exact 29-to-30 upgrade test; the 29 old migration hashes are unchanged.
- The first broad run was stopped when teardown hit an unconditional G1 downgrade refusal. A focused reproduction confirmed it. Migration rollback now restores the old constraints only while the extension is unused and explicitly refuses any guest history. Existing tests were not weakened to suppress this failure.
- Docker's first dependency build was slow while downloading Alpine GCC/G++ and Composer packages; it subsequently completed with the original pinned Dockerfile. No package version/pin or security check was bypassed.
- The runtime verification helper's expected route count was updated from 166 to 174 and independently syntax/Pint/startup-checked. The helper-only change does not alter application behavior.
- Review identified an additional database edge case: new child rows must match a guest-origin request's current customer after claim, while already recorded NULL historical rows must be preserved. The parent guard was tightened and a direct runtime-role test now rejects NULL ownership in a new draft/draft attachment/revision attachment after claim. The preceding broad run was stopped; the final complete gate restarts from the newly built fixed image, avoiding mixed-source acceptance.
- A source-freeze check correctly rejected the changed migration hash until the reviewed manifest was explicitly regenerated. Earlier image-equivalence checking identified the old unused embedded Nginx file in the reused F1 test runtime. The final full gate uses the rebuilt G1 image instead; both source and dependencies are fixed before the run.

- On resume, the initial eight-case session run failed two assertions proving unnecessary public/guest session persistence. Public taxonomy was made stateless and guest work marks the existing request-local session disposition as discard. No approved identity implementation or inherited race assertion was changed.
- An exploratory whole-workspace Pint check included 17 historical PHP diagnostics under ignored `artifacts/` and failed on those archived files. They were deliberately preserved unchanged. The actual final image excludes evidence via the existing Docker context and passes all 570 application/test PHP files. PHPStan also passed separately and within the final image.
- During resumed full gate attempt 1, review found that a draft bound only to the cookie ID could revive after explicit CSRF bootstrap of a retired cookie. A new regression reproduced HTTP 201 instead of 404. The run was stopped (exit 137, logs retained); G1 now hashes the session ID and server-side CSRF generation together. The final focused HTTP/session and entire image gate were rerun on this corrected source. The first red-test launch had a malformed read-only mount and was corrected without changing the test.
- The extra real guest-browser harness initially opened the API JSON document, whose unchanged `default-src none` policy correctly blocked browser fetches. Two zero-check attempts are retained. The harness now uses the existing HTML login surface and passes 16 real checks; no CSP, application, frontend or response was weakened or mocked.
- The first inherited Chrome race run completed four successful iterations, then an Overview response barrier was not reached within its unchanged 20-second limit. The attempt and HTTP summary are retained: all 26 reporting responses were 200, with no backend exceptions. The entire six-iteration harness was rerun unchanged after resetting only synthetic identities; no timeout, response mock, frontend code or security assertion was relaxed. This records intermittent local development/browser readiness, not a passed first attempt.
- A second unchanged identity-browser attempt completed three revoke iterations, then timed out waiting for the login-page identity probe during logout/login. Its partial result is retained and is not accepted. The first F1 run passed four tests, then stayed on the verification URL although MFA and identity/me both returned 200; the last test was serially skipped. That failed run and safe HTTP evidence are retained. The local Next development process was restarted with identical arguments before rerunning both complete browser gates; this task changed no frontend source, timeout or security assertion. The separately observed external E2E helper changes are disclosed above; all three resumed F1 attempts use that same current version.
- The second resumed F1 invocation stopped on the first test while waiting for the MFA route, with the remaining five serially skipped. Its JSON, exit code and safe backend HTTP sequence are retained; no login POST reached the backend in that attempt. A third full invocation reproduced the first-test MFA-route timeout (0 passed, 1 failed, 5 serially skipped). No timeout or assertion was relaxed and no further rerun is counted as acceptance. These are not passes; the current frontend tests are frozen and unchanged by this backend task.
- The earlier full G1 run with 907 tests and two original-ownership SQLSTATE failures is retained. The nullable-only parent guard correction now passes the complete final suite; original non-NULL composite foreign-key assertions remain.

## Local activation and remaining release boundary

Only after the complete migration/contract gate passed, a private custom-format backup of the local `holoul` database was saved and its archive catalog verified. Backup SHA-256: `18d9ba1019f08a7cfbbffd90f696b4748660f13cc783406c82a1849f5d401523`. This local recovery point is not a production backup/restore certification.

The verified G1 migration was applied using the separate migrator identity. Local app, queue, scheduler, document, AI and notification workers now use the same verified development image. Live checks confirm `APP_ENV=local`, debug off, 30 migrations, guest schema present, database sessions and runtime role `holoul_app`. Real same-origin/browser/F1 regressions ran against this candidate, not the pre-G1 image. The real local administrator account was not reset; only the existing fixture-owned synthetic identities were reset for browser testing.

The Ubuntu target VPS (2 CPU, 8 GB RAM, 1 TB disk) remains unavailable for performance certification at the estimated 150 customers, 100 Projects and 20 concurrent users. The failed P1 p95 values **662.590 ms and 699.075 ms**, and original targets **p95 <300 ms / p99 <1000 ms / zero unexpected HTTP errors**, remain unchanged. No production readiness, TLS/email/offsite recovery/PITR, operational ownership or host-hardening certification is claimed.

Unclaimed submitted contact/history and empty draft rows are retained. G1 introduces no destructive history-erasure policy. Anonymous storage/rate limits are deliberate launch bounds. Lost/expired unconsumed claim tokens have no public recovery shortcut. Guest document HTTP cases use the deterministic private-store/scanner/inspector fixtures plus inherited B4 regressions; no live-browser guest upload or website workflow acceptance is claimed. Website capability/claim handling and rendering of guest revisions remain integration work.

**Final classification: G1 BLOCKED.** The remaining mandatory gate is frontend F1 6/6 on the real same-origin candidate. Its current first-test failure is at `e2e/real/auth.spec.ts:69`, awaiting `/admin/login/verify` after clicking Sign in. The independent inherited Chrome harness completed all six real security-rotation scenarios. No backend authentication regression is established by the UI failure, but passing backend tests do not substitute for F1. Frontend readiness/test synchronization needs separate review; this task did not edit website or dashboard implementation/tests, revert external work, start F2, or weaken identity security.

The candidate is reviewable and recoverable independently of the approved identity parent. G1 is not complete, not approved for website integration, and not Production Ready. No success stop-condition statement is issued.
