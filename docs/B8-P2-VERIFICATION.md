# HOLOUL B8-P2 — verification evidence

Review date: 2026-09-21. **API classification: FRONTEND INTEGRATION BLOCKED for the complete requested dashboard scope.** The missing staff customer directory and staff/eligible-assignee lookup, absent current-user capability metadata and unavailable approved visual design are detailed in [the final review](B8-P2-REVIEW.md). Existing documented journeys have the evidence below; the classification does not mean every existing API is unusable.

**B8 remains NOT PRODUCTION READY. Production performance certification is pending the unavailable target VPS.** This phase performed contract review and regression verification, with no new performance run/tuning, altered SLO, external AI enablement, production deployment or commit.

## Scope and provenance

The approved B1–B7 baseline remains `96445baded70ddd2e4d8b8617793c78a4a3e1816` on `main`, parent `bf738302657f8da1bca911058186a24e87b7ea37`. The current B8/P1/P2 candidate remains uncommitted. Prior B8/P1 evidence is retained.

Only two application corrections were made in P2: taxonomy validation identifies the public `cursor` field, and an empty filtered validation-field map remains a JSON object. Their regression tests pass. Comparing the existing P1 runtime and the final P2 candidate across 375 PHP files in application/routes/config/bootstrap (excluding generated cache) identifies exactly those two changed paths; see [product delta](../artifacts/p2-product-delta.json). This comparison does not imply that the larger pre-existing B8 changes are approved.

Tests used the pinned Docker PHP/Composer runtime and disposable PostgreSQL test database. No host PHP, SQLite substitution, frontend tooling or application dependency was added. The verification-only Python validator has pinned versions and wheel hashes and runs without network access. Production/runtime and migration identities remain separate.

## Final measured checks

| Check | Result | Evidence / qualification |
|---|---|---|
|Composer strict validation, install/platform checks and locked audit|PASS; no security advisories|[First quality log](../artifacts/p2-review1/quality.log). Application dependency manifests did not change afterward.|
|Pint|PASS, 528 files|[Final log](../artifacts/p2-final/pint.log); includes the final HTTP capture trait and added projection test. First full pass covered 527 files.|
|PHPStan/Larastan level 10|PASS, no errors|[First quality log](../artifacts/p2-review1/quality.log). Its configured application/bootstrap/config/routes scope did not change afterward; test sources are outside the existing configured scope.|
|PostgreSQL migrations|PASS|Fresh migration plus two idempotent migration passes, database guard, final fresh migration and configuration/route cache checks in the first quality log.|
|Full Unit/Feature suite|PASS: 839 tests, 9,878 assertions; zero errors/failures/skips|[JUnit](../artifacts/p2-review1/holoul-unit-feature.xml), console elapsed 24:59.116.|
|Architecture suite|PASS: 9 tests, 38,799 assertions; zero errors/failures/skips|[JUnit](../artifacts/p2-review1/holoul-architecture.xml), console elapsed 0.686 seconds.|
|Fresh HTTP regression and capture|PASS: 231 tests, 4,478 assertions; zero errors/failures/skips|[JUnit](../artifacts/p2-final/http-contract-tests.xml), [log](../artifacts/p2-final/http-contract-tests.log), elapsed 11:31.753. Includes one additional populated commercial/project projection test (111 assertions).|
|OpenAPI validation|PASS, OpenAPI 3.1.1|[Validation log](../artifacts/p2-final/openapi-validation.log); 134 paths, 158 operations, 188 schemas.|
|Route/spec consistency|PASS, all 158 frontend operations|`ApiContractTest` compares the actual registered method/path inventory, excluding the two health routes and implicit HEAD. CSRF initialization is the documented root exception.|
|Deterministic spec/matrix and reviewed source manifest|PASS|[Assembly check](../artifacts/p2-final/assembly-check.log). Unreviewed source drift requires explicit review and regeneration; CI never refreshes the manifest automatically.|
|Actual response contract validation|PASS, 1,535 responses|[Report](../artifacts/p2-final/contract-report.json), [log](../artifacts/p2-final/contract-validation.log). 125 operations observed, 102 with success responses; no schema/status failures, undocumented successes or missing required success samples.|
|Validator rejection tests|PASS, 4 tests, 12.911 seconds|[Log](../artifacts/p2-final/contract-negative-tests.log). Rejects leaked extra success fields, missing ETag, a body in 204 and HTML in place of a binary download. Two additional internal error-schema sentinels reject leakage and missing request correlation.|
|Document inspector regression|PASS, 17 tests, 3.007 seconds|[Log](../artifacts/p2-final/inspector-tests.log).|
|Docker development/candidate builds|PASS|[Development](../artifacts/p2-final/development-build.log), [candidate runtime](../artifacts/p2-final/runtime-build.log); identities below. Candidate was not deployed.|
|Compose/FPM/nginx configuration|PASS|Compose configuration validation, [FPM](../artifacts/p2-final/fpm-check.log), [nginx](../artifacts/p2-final/nginx-check.log).|
|Existing runtime observation|14 running, 13 healthy|[Services](../artifacts/p2-final/runtime-services.json); signature updater has no healthcheck. [Readiness](../artifacts/p2-final/readiness.json) returned ready through the internal edge HTTP path. This is not fresh public TLS certification.|
|Existing runtime AI/debug settings|PASS: AI off, no external provider, debug off|[Safe configuration evidence](../artifacts/p2-final/runtime-ai-disabled.json), all six PHP processes. Existing P1 runtime remains in place.|

The 231-test HTTP pass repeats 230 tests from the full suite and adds one new test. It is not 231 additional unique tests. The two stages therefore cover 840 distinct Unit/Feature tests, plus nine architecture tests. Existing authorization, customer-isolation, concurrency and business Feature tests remain in place; schema tests do not replace them.

## Earlier attempts and recovery

The first full PHP quality stage completed successfully, including both PHPUnit suites and the migration/cache checks. Its outer shell wrapper subsequently exited 1 with a `quality.log: Permission denied` redirection error. The wrapper had been edited while running; that is recorded as an execution caveat, not a proven sole cause. [The failed wrapper record](../artifacts/p2-review1/wrapper-exit.json) is retained. **A successful end-to-end run of that first wrapper is not claimed.**

The first response capture also preceded the strengthened Location/body-length/binary-header checks. Validating that older capture with the stronger checker failed because the capture lacked required metadata. Those failed/preflight reports remain in `artifacts`; they are not the final accepted response evidence. A download Cache-Control schema was corrected to the actual header ordering without changing application behavior.

Recovery used stable, independent stages: final image/configuration checks, fresh execution of the 20 selected HTTP test classes with the complete recorder, full validation of their new response capture, validator rejection tests, inspector regression and final Pint/source checks. The successful results above are attributed to those stages, not retroactively to the failed wrapper. [The HTTP selection](../artifacts/p2-final/http-test-selection.json) and [exit record](../artifacts/p2-final/http-exit.json) document the recovery selection/result.

`scripts/verify-contracts.sh <unique-run-name>` now contains the standalone repeatable P2 gate without performance acceptance or deployment. The ordinary `scripts/verify.sh` also invokes contract checks before its unchanged performance gate. Neither a hosted CI pass nor new branch-protection configuration is claimed.

## Contract coverage and its limits

The [OpenAPI specification](openapi.json), [all-operation matrix](B8-P2-ENDPOINT-MATRIX.md), [integration guide](B8-P2-FRONTEND-INTEGRATION.md), three domain fragments and reviewed source manifest form the freeze package.

Specification SHA-256: `405fce2bb7077eb95b6ed529a5f155ccadb4ee2eec1946709b211f98fd39b3d7`.

The validator checks local-only references, OpenAPI structure, JSON Schema formats, exact route membership, operation metadata, success schemas/statuses/declared safe headers, exact binary MIME, bodyless 204, canonical error fields and status/code/request-ID correlation. A reviewed inventory freezes the current minimum **102 operations with successful response samples**, so silently removing their test coverage fails validation.

All 158 operations have static route/spec coverage. Runtime evidence observes 125 operations: 102 with successes and 23 only with errors. Another 33 operations are not observed by this response capture. Thus **56 operations lack a successful captured response**. This is representative contract verification, not exhaustive runtime coverage of all routes, state transitions, request combinations or deployment configurations. Request/query contracts were reviewed against validators and existing request-validation tests; source hashes signal changes but do not prove semantic equivalence.

Status counts in the final capture:

| HTTP status | Responses | HTTP status | Responses |
|---|---:|---|---:|
|200|535|201|96|
|202|143|204|255|
|400|2|401|43|
|403|160|404|97|
|405|4|409|31|
|412|10|413|3|
|415|5|422|126|
|428|9|429|6|
|500|3|503|7|

The machine-readable [contract report](../artifacts/p2-final/contract-report.json) lists every successful operation and all 56 without a successful sample. It contains no response values. Raw response captures are private ignored synthetic-test evidence; they can include synthetic MFA/recovery material and must not be published or included in shared documentation/CI uploads. Request bodies, authentication cookies and authorization headers are never captured.

## Image identities and artifact integrity

| Image | Immutable local image ID |
|---|---|
|Unchanged running P1 runtime `holoul-app:b8-runtime`|`sha256:ba964a709c7faae3e0b341b16e79d006c8bc7b38dbf5a12128e14aa3d120a2a4`|
|Final P2 development `holoul-app:b8-p2-development`|`sha256:cd7d710a8d52005dafbb060a348df8dcf8b3973daa3e11177eccd926c8a9604a`|
|Final P2 candidate `holoul-app:b8-p2-candidate`|`sha256:9affc0c2526a02d5dc04f2f083f1edba5684ccc51f7c246c9ecde9145ade0f5c`|
|Verification-only validator `holoul-contracts:b8-p2`|`sha256:978401473245f5ad792a821dc37dd3e685d1dc600d9bd6f4f14edc96a1f38202`|

See [image evidence](../artifacts/p2-final/image-identities.json). Python/OpenAPI/JSON Schema dependency versions and wheel hashes are pinned in `docker/contracts/requirements.lock`; application dependency versions remain in the existing Composer lock. Final evidence hashes, Git snapshot and candidate preservation metadata are recorded separately under ignored local artifacts. No private runtime environment file is part of the source preservation archive.

The [final machine summary](../artifacts/p2-final/summary.json), [Git snapshot](../artifacts/p2-final/git-snapshot.json), [evidence/source hash inventory](../artifacts/p2-final/integrity.json) and [preservation manifest](../artifacts/p2-preservation/manifest.json) record the delivered state. Preservation contains the complete tracked candidate patch against approved HEAD and an archive of nonignored untracked source/documents; it excludes runtime secrets, dependencies and private response captures. It is a local recovery copy, not a commit, release or independent offsite backup.

## Remaining gates

The API scope gaps are in [the final review](B8-P2-REVIEW.md#6-frontendbackend-gaps-and-readiness-decision). No new business feature was implemented to hide those gaps. The actual approved dashboard design must be supplied or located before full design parity can be assessed.

The prior strict performance gate remains failed: P1 final p95 results were **662.590 ms and 699.075 ms**, against the unchanged **p95 <300 ms, p99 <1,000 ms, zero unexpected errors** requirement for 480 authenticated HTTPS requests in 24 synchronized waves of 20, five seconds apart. There was no P2 performance retest and no reinterpretation as a pass.

The unavailable target is the Ubuntu VPS with 2 CPU, 8 GB RAM and 1 TB storage, with approximately 150 customers, 100 Projects and 20 concurrent users. Production commissioning, target-host capacity/performance/security/recovery evidence, public TLS, email, monitoring ownership, backups/PITR and operational approvals remain subject to [the B8 launch checklist](B8-LAUNCH-CHECKLIST.md). No new full container vulnerability scan, target recovery drill or public TLS validation is claimed by this P2 contract gate.

B8-P2 BACKEND CONTRACT REVIEW COMPLETE — PRODUCTION PERFORMANCE CERTIFICATION PENDING VPS
