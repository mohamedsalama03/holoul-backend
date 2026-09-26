# HOLOUL B8-P3 — verification and integration decision

**API classification: FRONTEND INTEGRATION READY** for the three authorized B8-P3 gaps. Staff customer directory/detail and scoped summaries, contextual eligible-assignee/team reads, and safe current-session capabilities are implemented and verified. No remaining API gap was identified within that authorized scope. This is not a claim of approved visual-design parity or completed Next.js screens.

**B8 remains NOT PRODUCTION READY. Production performance certification is pending the unavailable target VPS.** No WSL performance benchmark/tuning, weaker SLO, external AI enablement, production deployment, commit or new business domain was introduced.

## Delivered contract

See [integration guide](B8-P3-ADMIN-INTEGRATION.md), [OpenAPI](openapi.json), and [current endpoint matrix](API-ENDPOINT-MATRIX.md). OpenAPI 3.1.1, candidate `1.0.0-b8-p3`, has **164 operations, 140 paths and 199 schemas**. Its SHA-256 is `1422e14ef0c18204c420080c33f0b597f39cce5093acf358aa15aa1ee980499b`.

Six bounded GET operations were added: directory, detail, per-customer request/Project summaries, request eligible-assignees and Project eligible-staff. Current-user GET/PATCH responses add roles, capabilities and session requirements without raw permissions or authentication material. The new directory permission is narrowly seeded; existing assignment/membership scopes and final command authorization remain authoritative. No customer edit/CRM or unrestricted staff directory was added.

## Final gate results

The complete independent contract/quality runner exited **0**; [exit evidence](../artifacts/p3-final2/gate-exit.json), [machine summary](../artifacts/p3-final2/summary.json), [quality log](../artifacts/p3-final2/quality.log). All application/integration tests used PostgreSQL `holoul_test` in the pinned Docker PHP/Composer runtime, with separate runtime/migration identities.

| Gate | Measured result | Evidence |
|---|---|---|
|New API/authorization + route contract focus|13 tests, 1,500 assertions; pass|[Focused JUnit](../artifacts/p3-focused-verified/focused.xml)|
|Strict pagination-input regression|1 test, 44 assertions; pass|[Input JUnit](../artifacts/p3-input-final/input.xml)|
|Complete Unit/Feature suite|851 tests, 10,308 assertions; zero errors/failures/skips|[JUnit](../artifacts/p3-final2/holoul-unit-feature.xml)|
|Architecture|9 tests, 39,773 assertions; zero errors/failures/skips|[JUnit](../artifacts/p3-final2/holoul-architecture.xml)|
|PHPStan/Larastan|Level 10, no errors|Quality log|
|Pint|538 files passed; final runtime helper separately passed|Quality log and [helper check](../artifacts/p3-runtime-final/pint-helper.log)|
|Composer|Strict validate, install/platform checks, locked audit passed; no advisories|Quality log|
|PostgreSQL|Fresh migration, repeated idempotent migrations, upgrade/constraint/security regressions passed|Quality log/full suites|
|OpenAPI/route/source drift|All 164 frontend operations matched; schema, deterministic assembly and source checks passed|[Contract validation](../artifacts/p3-final2/contract-validation.log)|
|Actual response validation|1,676 responses; 132 operations observed; 110 operations with successes|[Report](../artifacts/p3-final2/contract-report.json)|
|Coverage floor|108 required successful operations, including all six additions; no missing samples|Contract report|
|Validator negative regressions|4 tests passed, plus two internal error-schema sentinels|[Log](../artifacts/p3-final2/contract-negative-tests.log)|
|Document inspector|17 tests passed|[Log](../artifacts/p3-final2/inspector-tests.log)|
|Docker|Development/production-candidate/validator builds, Compose/FPM/nginx checks passed|Runner logs; [final candidate rebuild](../artifacts/p3-runtime-final/build.log)|
|Disposable production-mode startup|166 cached routes, private configuration cache, debug off, AI/external provider off|[Safe evidence](../artifacts/p3-runtime-final/ai-disabled.json)|

Focused tests are repeated within the complete suite; do not add their counts to the full-suite total. Earlier failing/interrupted attempts below are retained as separate evidence.

## Authorization, concurrency and query evidence

The new tests cover customer exclusion from admin APIs, Customer A/B isolation, unprivileged Support/Administrator exclusion, assigned-request and Project-member visibility, scoped counts and summaries, literal search/filter/pagination validation, disabled/ineligible candidates, forged resource context, missing manager membership, terminal parents, membership duplication prevention, and valid command execution after selection.

They exercise eligible lookup followed by candidate disable/permission revocation: the existing assignment/membership commands reject stale eligibility with 422 and no relationship mutation. Picker and command permission requirements now share definitions. A real independent PostgreSQL process also attempts session revocation during current-user projection: it waits for the identity lock, then invalidates subsequent reads with 401. Authorized role changes invalidate the old session; a new session receives the new role. Live permission removal disappears from capabilities and prevents API access; forged capability headers/body fields do not grant authority. Customer verification and expired recent-password confirmation update the projection safely.

Query-count evidence at page sizes 1 and 50:

| Endpoint | SELECTs, limit 1 | SELECTs, limit 50 | Total queries at each size |
|---|---:|---:|---|
|`/api/v1/admin/customers`|11|11|[14, 13]|
|`/api/v1/admin/project-requests/{id}/eligible-assignees`|12|12|[13, 13]|
|`/api/v1/admin/projects/{id}/eligible-staff?role=contributor`|14|14|[15, 15]|

[Raw count summary](../artifacts/p3-final2/p3-query-counts.jsonl) contains no contact/security values. SELECT counts include authorization reads and remain constant as page size grows; session last-activity writes may vary at timestamp boundaries. Overall queries stay below the asserted bound of 35. Result pages are limited to 50; grouped counts and SQL eligibility checks avoid per-row reads. No new index or Redis business cache was added. These tests use synthetic fixtures and do not certify launch throughput/capacity on the VPS.

## Contract coverage limits

All 164 operations have static route/spec coverage. **54 operations lack a captured successful response** in this run; the [contract report](../artifacts/p3-final2/contract-report.json) lists them explicitly. Representative runtime response validation is not proof of every request combination, business state or deployment configuration. Existing Feature/Authorization/security/concurrency tests remain and pass; they were not replaced by schemas.

The validator checks response status/body/schema, safe declared headers, parent ETags, binary media, bodyless 204, canonical error code/status and request-ID correlation. Reviewed implementation hashes detect source changes but require human review to judge semantics. All six new endpoints and the current-user response have successful captured responses. Private raw samples can include synthetic MFA/recovery values; they remain ignored artifacts and must not be published or uploaded with the documentation.

## Earlier attempts, corrections and provenance

- The first static analysis found one array-return annotation mismatch; it was corrected and final level-10 analysis passed.
- The first expanded focused runs exposed test assumptions, not authorization failures: total query counts include time-dependent session writes, and MFA setup had already advanced the authentication generation. Tests now compare constant SELECT counts plus bounded total queries and compare revocation against the actual prior generation. The focused and full runs pass.
- The first full run was intentionally stopped (exit 137) to tighten pagination input: unsupported signed/decimal/array forms now fail 422 instead of risking a default-size fallback. [Interruption record](../artifacts/p3-final/interruption.json); no pass is claimed for that run. The corrected source passed a dedicated regression and the complete replacement run.
- The production runtime helper's expected registered-route count was updated from 160 to 166. This verification-helper-only update occurred after the full PHP gate image was built; the final candidate was rebuilt, its helper passed syntax/Pint checks and its actual private-cache startup passed. Application code and schemas did not change during the successful full gate.
- The initial disposable runtime invocation inherited local APP_ENV and was correctly rejected by the production-only helper. Retrying with explicit `HOLOUL_APP_ENV=production` passed. This did not replace the running application or execute the helper's mutating async mode.

No complete production security scan, public TLS certification, external-provider test, live deployment, offsite recovery rehearsal or hosted CI run is claimed by this batch. The production candidate was exercised only in a disposable local container without published service ports; the existing P1 stack remains running.

## Git, images and preserved evidence

The approved B1–B7 baseline remains `96445baded70ddd2e4d8b8617793c78a4a3e1816`. All B8/P1/P2/P3 work remains an **unaccepted, uncommitted candidate**. No commit/branch/tag/push/PR was created. [Historical-evidence check](../artifacts/p3-final2/historical-evidence-check.json) verifies the P2 report/evidence files and preservation patch/archive were not changed. Original B8/P1 evidence remains in place.

| Image | Immutable local image ID |
|---|---|
|`holoul-app:b8-runtime`|`sha256:ba964a709c7faae3e0b341b16e79d006c8bc7b38dbf5a12128e14aa3d120a2a4`|
|`holoul-app:b8-p3-development`|`sha256:a8b009162b1d975e7ae40868ccd12c26f5412c727b7e9f826571241bfebacb6b`|
|`holoul-app:b8-p3-candidate`|`sha256:b9996e12929ef6ea76d64045d8ce14cabd9538c0d9bb3e33dbfc453afaafb8a8`|
|`holoul-contracts:b8-p3`|`sha256:dd33af1c2d1c759a45c29348daf5d4d334586832e08b5c259fa79471f5bba216`|

The [Git snapshot](../artifacts/p3-final2/git-snapshot.json), [integrity inventory](../artifacts/p3-final2/integrity.json), and [candidate preservation manifest](../artifacts/p3-preservation/manifest.json) record the final reviewable state. The local preservation copy includes the tracked patch against approved B7 and nonignored untracked source/docs; it excludes ignored runtime secrets, dependencies and raw captures. This is not an offsite backup or an accepted release. A later checkpoint should be explicitly named candidate integration work with production performance pending; frontend work should pin this reviewed OpenAPI hash.

## Remaining production blockers

The target Ubuntu VPS (2 CPU, 8 GB RAM, 1 TB disk; approximately 150 customers, 100 Projects, 20 concurrent users) remains unavailable. P1's final p95 values **662.590 ms and 699.075 ms** remain failures against the unchanged **p95 <300 ms / p99 <1,000 ms / zero unexpected errors** gate. No P3 benchmark or tuning was performed. The authenticated HTTPS wave shape and correctness requirements remain unchanged.

Target-host commissioning, measured capacity/performance, production TLS/email, monitoring owners, private-service hardening, backups/PITR/restore, secrets/retention decisions and release approval remain governed by [the B8 launch checklist](B8-LAUNCH-CHECKLIST.md). API integration readiness does not close those production gates.

B8-P3 ADMIN INTEGRATION GAPS COMPLETE — PRODUCTION PERFORMANCE CERTIFICATION PENDING VPS
