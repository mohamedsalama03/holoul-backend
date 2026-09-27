# G1 — Final acceptance (2026-09-27)

**BACKEND FEATURE READY FOR WEBSITE INTEGRATION**

The external F1 blocker is resolved. The existing Dual Intake implementation and its unchanged OpenAPI candidate are accepted for Public Website integration. No new G1 functionality, website or Admin Dashboard changes were made. This is **not Production Ready**; B8 target-VPS performance certification remains pending.

## 1. Exact candidate identity

- Approved identity checkpoint: `9940acf6d9e16c7da3cd08b1a6028c875d5accd0` (`codex/candidate-identity-approved`).
- G1 implementation/review checkpoint: `7ec83d414783553d3be2dc5f4cb6e3920794c0fe` (`codex/candidate-g1-review`), still its **direct child**.
- Candidate tree: 739 files checked. All G1 implementation, tests, prior migrations and identity remediation match the previously completed gate. No unexpected implementation source changes or extra implementation files.
- The only later infrastructure delta is the approved F1-E2 Nginx configuration, its source-manifest hash and the new F1-E2 report. Nginx SHA-256 remains `8182dc8b9815c3c355b9d46e5f437e27bc933b715254a9e28f3c5a2b73b167dd`.
- Live backend image: `sha256:74f343fde5526ab1115512dcefbb1f53cbaf0603e5fba4a4692cfec9278a7bd4`, identical to the previous full G1 gate. **618 embedded implementation/test/tool files** matched the candidate; the runtime-mounted cache directory is excluded from this source check.
- All 14 inherited identity/concurrency source and test files in the approved preservation inventory remain byte-identical. No cookie/session/CSRF/MFA/password/limiter semantics changed.

## 2. Previously blocking F1-R2 regression

Approved frontend HEAD: `17cca09675ad42f7ae9965b5cb2bcd5950398a26`; parent: `3420d6c91503376c2039cc2c47ed304157dadaad`. Used its clean separate worktree `D:/customers/holoul frontend/dashboard-f1r2`, leaving the other frontend worktree untouched. A fresh `next build` passed, followed by production `next start` on loopback port 3001.

**6 passed / 0 failed / 0 skipped / 0 retries**, one real-backend project, existing timeout/retry settings. Synthetic identities were reset once immediately before the run using the existing guarded local fixture tool. The browser used `https://localhost:8443`, real backend/password/MFA/recovery/CSRF/session flows, installed Chrome, no mocks or intercepts. No frontend or backend implementation was edited to achieve this result.

The passing cases are:

- staff: password → MFA → current user → /admin → sign out
- invalid credentials are refused without revealing the account
- staff enrollment: set up MFA → recovery codes → current user; a recovery code signs in
- a customer is signed in by the API but denied the dashboard
- a session revoked from another sign-in loses the dashboard
- the API's CSP stays on the API; the dashboard gets its own

Raw JSON and exit record: `artifacts/g1-acceptance/frontend-f1.json` and `frontend-f1-exit.json`. The user-supplied earlier three 6/6 runs remain separate evidence and are not added to this run.

## 3. G1 focused regression and 4. inherited identity/security

| Final rerun | Tests | Assertions | Result |
|---|---:|---:|---|
|Unit + Architecture|153|42766|0 failures/errors/skips|
|Focused Feature (intake, documents, identity, taxonomy, contract, fixture safety)|333|6125|0 failures/errors/skips|
|Nested real PostgreSQL 40001 serialization regression|1|12|0 failures/errors/skips|
|**Total backend rerun**|**487**|**48903**|**Passed**|

Selected inherited suites (subsets of the total, not additional tests):

| Suite | Tests | Assertions |
|---|---:|---:|
|GuestIntakeHttpTest|39|448|
|GuestIntakeConstraintsTest|3|25|
|GuestIntakeUpgradeTest|2|271|
|GuestIntakeConcurrencyTest|8|102|
|GuestSessionIsolationTest|9|176|
|IntakeHttpTest|45|456|
|IntakeConcurrencyTest|4|104|
|DocumentHttpTest|24|419|
|DocumentConcurrencyTest|3|21|
|SessionLifecycleTest|18|830|
|IdentityHttpTest|34|304|
|SessionPruningTest|6|33|
|LocalE2EFixtureTest|4|66|
|ApiContractTest|2|1280|

Coverage includes authenticated profile-derived contact/ownership, guest required/normalized contacts, shared active taxonomy, exact USD/LYD/zero/unknown money, capability binding/expiry, cross-browser and cross-customer denial, PDF/DOCX authorization/quarantine, submission/idempotency, one-time claim and exact replay, expired/wrong-customer claims, immutable contact/author snapshots, and atomic document association.

All guest/session transition cases were inherited unchanged: another authenticated browser; stale authenticated work concurrent with public/guest work; login during guest draft creation; claim during rotation; customer login versus staff MFA permissions; logout/login with a draft capability; and retired-cookie bootstrap denial. The 18 inherited SessionLifecycle tests retain late-reader and delayed-logout protection. Real PostgreSQL workers exercised competing submissions/claims, customer A/B isolation, upload/submission races, profile-snapshot races and nested 40001 retry classification. No SQLite substitute or weakened race assertion was used.

Fresh migration and exact populated 29-to-30 upgrade/down/refusal tests ran only against `holoul_test` with the migration identity. The live database was not reset or migrated. HTTP document tests use deterministic storage/scanner adapters; inherited B4 regressions were included. This does not claim a new live-browser guest-upload E2E or production-storage certification.

The previous complete unchanged-source G1 gate remains valid evidence: **946 tests / 54,717 assertions**, Pint 570 files, PHPStan/Larastan level 10, Composer validation/audit/platform, development/runtime builds, exact upgrade, inspector checks and browser races. These are inherited results, not falsely reported as newly rerun here. F1-E2 quality evidence also remains intact.

## 5–8. Accepted authoritative contract

- OpenAPI: **3.1.1**; API version: **1.1.0-g1**; operations: **172**.
- Previous approved SHA-256: `1422e14ef0c18204c420080c33f0b597f39cce5093acf358aa15aa1ee980499b`.
- Accepted SHA-256: `ae8d0a904a0042b80f9adc1224dedf8677794e2d49ca5cdf41daeb5278780be8`.
- Verified directly from the candidate and final file. No OpenAPI reformatting or semantic regeneration; frontend contract copy untouched.
- The original **164 operation objects**, including authenticated request bodies and Admin operations, remain identical as complete operation objects. Five GuestIntake schemas are added. The shared `IntakeRevision` extends new guest history with nullable `submitted_by` and `guest_submission` provenance; existing authenticated values remain unchanged. Consumers displaying new guest records must handle that variant.

| Method/path | operationId |
|---|---|
|`POST /api/v1/guest/project-requests`|`guestIntakeCreate`|
|`POST /api/v1/guest/project-requests/{projectRequest}/documents`|`guestIntakeReserveDocument`|
|`GET /api/v1/guest/project-requests/{projectRequest}/documents/{document}`|`guestIntakeDocumentStatus`|
|`PUT /api/v1/guest/project-requests/{projectRequest}/documents/{document}/content`|`guestIntakeUploadDocument`|
|`POST /api/v1/guest/project-requests/{projectRequest}/submissions`|`guestIntakeSubmit`|
|`GET /api/v1/intake/categories`|`guestIntakeCategories`|
|`GET /api/v1/intake/categories/{category}/subcategories`|`guestIntakeSubcategories`|
|`POST /api/v1/project-request-claims`|`guestIntakeClaim`|

OpenAPI structural/source/route checks passed. The preserved full response corpus was revalidated unchanged. The final focused capture contains **1376 response records**, with successful samples for all eight G1 operations. Combined explicit historical + fresh response validation: **3608 validated responses**, no schema/status failures or missing required coverage. The sources remain separate; this does not represent a new full-application run.

## 9. Website integration handoff

[G1-WEBSITE-INTEGRATION.md](G1-WEBSITE-INTEGRATION.md) is the accepted handoff. It lists all operation IDs, request/response schemas, CSRF/authentication, Idempotency-Key, ETag/If-Match, capability/expiry, exact private upload and secure claim sequences, and safe error codes.

Authenticated customers never re-enter Name/Email/Phone in Submit Your Idea; the server reads their current profile and derives ownership. Guests require all three contact fields, including international phone. Both paths share canonical immutable request history and exact money/taxonomy rules. Reference and email equality never establish ownership. Documents remain PDF/DOCX, at most 10 MiB, privately quarantined/scanned with no public URL. G1 works with AI disabled; separate website AI assistance follows the core Dual Intake integration and is not implemented here.

## 10. Remaining production blockers

No remaining G1 backend acceptance blocker was found. Public Website integration is authorized but has **not** been implemented. This is not approval to deploy production. B8 production-performance certification on the target VPS remains pending; original targets and failed performance evidence remain. Local F1/F1-E2 timings cannot replace that certification.

## 11. Git/candidate status and preserved history

The existing candidate-history policy is retained: local named refs, isolated temporary Git index, no reset/staging of accumulated main work, no squash, push or PR. Original `main` remains `96445baded70ddd2e4d8b8617793c78a4a3e1816` and its staging index is untouched.

History: `codex/candidate-pre-g1` → `codex/candidate-identity-approved` → unchanged `codex/candidate-g1-review` → `codex/candidate-f1-e2-stable` → `codex/candidate-g1-accepted`. The F1-E2 delta is checkpointed separately; the acceptance child changes only documentation/safe evidence. The exact new checkpoint SHAs and tree are recorded in `artifacts/g1-acceptance/final-candidate.json` and the delivery receipt. `accepted-candidates.bundle` preserves all candidate refs and is verified before handoff.

All **712** files in the pre-G1 historical evidence inventory match. Of the separate 256-entry identity-era inventory, 255 match; the old development-server stdout already differed before this task (mtime 2026-09-26 22:40:48 UTC). That existing runtime log and its earlier hash inventory are both retained; no missing or changed B8 failed-performance artifact was hidden. Initial live-image verification included `bootstrap/cache/.gitignore`, hidden by the runtime cache mount; its diagnostic result is retained, and the final source check correctly excludes that non-executable placeholder. Neither observation is an implementation-source change.

Old blocked G1/F1 reports and failed attempts remain as historical evidence. The prior G1 verification/resume documents gain a current acceptance pointer without deleting their old findings. Raw synthetic response captures remain private and are not committed. No credentials or claim/capability values are included in the handoff.

**G1 DUAL PROJECT INTAKE COMPLETE — WEBSITE INTEGRATION AUTHORIZED**
