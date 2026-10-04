# Unified backend VPS candidate — final remediation

4 October 2026. **Git candidate release gates: READY.** This is a backend candidate freeze, not a Production Ready release. Target-VPS capacity/performance certification and deployment approval remain separate gates. No VPS access/deployment, frontend change, main merge or production tag was performed.

## Identity, scope and preserved evidence

Branch: `release/unified-vps-candidate`. The remediation commit containing this report is a direct child of `41199408a4eea79e8e84430666728671e0b2c3c5`, whose parent is `9119a094da23cc23c93475be79d076c4c1f2de21`. Neither checkpoint was rewritten. The final delivery receipt records the new commit SHA, its parent, a fresh remote SHA and clean working-tree state after pushing. Those values cannot be embedded self-referentially in this commit.

The previous [BLOCKED freeze report](UNIFIED-VPS-FREEZE.md), its initial failed full PHPUnit run, corrective runs, failed infrastructure scans, interrupted restore and B8/P1/P2/P3/F1 performance evidence remain unchanged. Before changes, the clean 4119940 checkout was independently archived with its Git bundle and manifests. Source fingerprint `6fd5dc1f8046d7b0ed1d49d56e24bff760c1947e1bb57aa129b1ff0cbd52c96a` and complete-manifest digest `75a256b8b233e88b1e6fdb46abf91a47471522a2fddf36afbfb0ef1204b8e095` matched the prior receipt.

Only release infrastructure, the isolated restore helper and this evidence changed. Application code, configuration, routes, migrations and inherited tests are unchanged. [Preservation proof](unified-vps-final/inherited-preservation.json) checks the nine security guard/test files and 61 historical evidence files against 9119a09. No product functionality or authorization policy was added or weakened.

Private raw evidence, including failed attempts and protected response captures, is held at `/home/mohamed/.local/share/holoul-freezes/unified-20261004T104020Z`. Credentials and token-bearing captures are excluded from Git. Sanitized evidence is linked below.

## Remediation

- Inspector: pinned `libexpat=2.8.5-r0` for CVE-2026-93990.
- Scanner: pinned `pcre2=10.49-r0` for CVE-2026-103111.
- Nginx: retained the reviewed digest-pinned base and added an explicit Dockerfile pinning both fixed package versions. Compose selects the rebuilt candidate images.
- Isolated restore: health checks now send the exact generated Host/port accepted by the strict ingress guard. The development document initializer is mounted read-only only for the local restore bootstrap; it remains excluded from the production image. No live ingress trust rule was relaxed.

The first new restore attempt failed because that development initializer was absent from the production allowlist; its result remains preserved. A subsequent isolated test-environment startup failed when Compose treated a successfully completed bootstrap as a service to keep running. The generated test plan now explicitly depends on successful bootstrap completion. No PHPUnit invocation occurred in that failed setup. A preliminary processor command targeted a nonexistent test path (exit 2, no tests ran); the corrected invocation passed all five existing tests. [Helper attempt evidence](unified-vps-final/helper-test-attempts.json). The original scanner build also encountered a transient WSL credential-helper connection failure before its successful retry. [Attempt evidence](unified-vps-final/preserved-remediation-attempts.json).

## Complete verification

One final invocation ran `vendor/bin/phpunit --testsuite Unit,Feature,Architecture --testdox --colors=never --log-junit /verification-artifacts/complete.xml` from start to finish: **1097 tests, 68154 assertions, 0 failures, 0 errors, 0 skips; exit 0; wall duration 2564.517 seconds**. This is not an aggregation of corrective reruns.

The invocation used a fresh, uniquely named isolated PostgreSQL/Redis/storage/scanner/inspector/portfolio-processing environment and the rebuilt development image. PostgreSQL retained separate runtime and migrator identities. The real local application/database was not reset or switched. [Machine results and per-class counts](unified-vps-final/backend-gates.json). The following groups overlap and are subsets of that one invocation:

| Gate | Result |
|---|---|
| Architecture | PASS — 9 tests / 52363 assertions |
| PostgreSQL concurrency | PASS — 58 tests / 757 assertions |
| Wrapped PostgreSQL concurrency classification | PASS — 1 tests / 5 assertions |
| Migration upgrades | PASS — 14 tests / 3225 assertions |
| Exact populated frozen G1 upgrade | PASS — 1 tests / 212 assertions |
| Identity and guest/public session races | PASS — 33 tests / 1077 assertions |
| Guest intake | PASS — 61 tests / 1022 assertions |
| Documents | PASS — 105 tests / 1111 assertions |
| Public Services and Portfolio | PASS — 49 tests / 1029 assertions |
| Staff and authorization | PASS — 93 tests / 1600 assertions |
| Gemini synthetic workflows | PASS — 41 tests / 289 assertions |
| Route/spec contract | PASS — 2 tests / 1504 assertions |
| PHPStan/Larastan | PASS, level 10, zero errors |
| Pint | PASS, 684 PHP files |
| Composer | Strict validation, platform requirements and locked audit PASS; no vulnerability advisories |
| Fresh migrations | All 36 applied; two immediate repeats report no outstanding migrations |
| Exact upgrade | Populated frozen G1 `b6d3f27ee9fbde274b04a75c4d4ece35aa8e4de0`, 30 original migrations, upgraded to 36 and repeated; rows/history/guards/grants preserved |
| Independent parser/processor tests | 17 inspector tests and 5 portfolio processor tests PASS |
| OpenAPI/response validation | PASS; generated contract, route/spec drift, captured responses and 16 Python contract regressions |

## Contract and consumers

OpenAPI **3.1.1**; API **1.8.1-gemini-documents-candidate**; **204 operations**, **265 schemas**. SHA-256 remained **`3c93a09f08ce199cc1c2867ad846a1ca554fd03b6d934070178c5101646df410`** throughout remediation. No contract bytes changed.

[Consumer compatibility](unified-vps-final/frontend-compatibility.json): Website **36/36** and Dashboard **67/67** consumed operations remain compatible, with no missing or incompatible operation. Checks read actual production call sites and recursively compare referenced schemas, statuses, parameters, required headers, security, CSRF, Idempotency-Key and If-Match. The Dashboard keeps its existing 1.7.0 contract copy; its current-user description annotation differs but its consumed semantics remain compatible. Both repositories’ native contract checks also passed. Neither frontend repository was edited.

[Captured backend response validation](unified-vps-final/contract-validation.json) provides actual response counts and operation coverage from this complete run. Raw synthetic session/claim/MFA values stay private.

## Runtime images and supply-chain gates

Every intended runtime image passed with **0 HIGH / 0 CRITICAL**, without suppressions. Mailpit is included conservatively in this local-topology inventory and is not an authorization to expose a production mail sandbox. [Image scan evidence](unified-vps-final/runtime-images.json).

| Image | Exact local image ID / manifest-index digest | HIGH / CRITICAL |
|---|---|---|
| `axllent/mailpit:v1.31.1@sha256:98b916bd3c8d61f7633a52d3ea2f58d00620cb01ca57ab59edde68c347a95365` | `sha256:98b916bd3c8d61f7633a52d3ea2f58d00620cb01ca57ab59edde68c347a95365` | 0 / 0 |
| `holoul-app:unified-final-candidate` | `sha256:b7a293375e2d18b82f99e91dcaf7092288bf99421a599371b5f1401c8cfde662` | 0 / 0 |
| `holoul-inspector:unified-vps-candidate` | `sha256:da438de7fd2fac4e75f0bf944559cbd0c20b25366150dbd1a62cc160b8b90af4` | 0 / 0 |
| `holoul-nginx:unified-vps-candidate` | `sha256:f5afcc4a35693d057b20e96b289f4b1b9339f191a564e45aadd6cd9ad9ee822b` | 0 / 0 |
| `holoul-portfolio:public-services-candidate` | `sha256:2bfe69025432b3d87c0d5126bdb1eca0f99f9e2159da402bd3653169f1108abe` | 0 / 0 |
| `holoul-postgres:b1-runtime` | `sha256:9d3c94996cdef82a3b22fe62099ec173893ab2ae48d0a6aa40624a74c3a2fdcf` | 0 / 0 |
| `holoul-redis:b1-runtime` | `sha256:db87e0459a6fcd6368f28396005d4b96f9983b05dff742e0d5e01209f536fb30` | 0 / 0 |
| `holoul-scanner:unified-vps-candidate` | `sha256:0c8611032258255d89f4407831244806e894268450707d3be1d0ff3391be2fe5` | 0 / 0 |
| `holoul-storage:b4-runtime` | `sha256:d83a6b2fb64881f3a0377260bf484ec16c50891fab9da988e0e68c0a10874b8b` | 0 / 0 |

Tool: Trivy **0.74.0**, pinned image `aquasec/trivy@sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969`. Database schema 2, updated `2026-10-03T14:28:08.788288516Z`, downloaded `2026-10-03T18:58:03.466176425Z`, next update `2026-10-04T14:28:08.788288198Z`. Its digest and installed package versions are recorded in [package provenance](unified-vps-final/packages-and-scanner-db.json). The tool confirmed the existing database was within its update window; this is not represented as a newly downloaded database.

[Production image inspection](unified-vps-final/production-image.json) confirms UID 1000/non-root, 82 production packages, no development dependencies, all 542 expected application-source files matching, no unexpected application files, and no tests, local E2E/performance/Gemini fixtures, Compose overlays, key helper or Git history. The exact known local Gemini key was absent from all 12,020 scanned regular files. An additional all-severity image secret scan passed.

Final rebuilding changed the build-attestation wrapper/index digest. The executable platform manifest remained `sha256:c170660652e784d6ffb1d50f4bb0eb39061f21c11e8f2de3ccbfd2f40199d1b0`, configuration digest remained `sha256:d19af5d6faaebd19e043ca3d2dd5b2209645879180096751d6a9dfec725e83d2`, and every root-filesystem layer remained identical to the image used for the successful restore. The final wrapper was independently rescanned, source-inspected and booted again.

The final application boots in production mode with the explicit local-verification profile, AI disabled, sandbox driver and an empty Gemini key, with networking disabled during the additional boot check. Core operation requires no Gemini key. Synthetic Gemini workflows passed in PHPUnit; no real Google request or production AI activation was performed.

## Secret and history gates

[Final candidate/history scanning](unified-vps-final/secret-scan-final.json) found zero actual secrets in 1179 candidate files and 1551 reachable historical blobs. Source dependency scanning including development dependencies also passed. The actual local provider key was compared in memory and was not printed or matched. Known SHA-256 evidence digests are identifiers, not credentials. Configuration/bootstrap code and fixtures were reviewed as generated secrets or synthetic data; no live APP_KEY, database/Redis/SMTP credential, TLS/private key, session/CSRF/TOTP/recovery or guest-capability value was selected for publication.

Post-commit history verification and a fresh remote read are mandatory before the final delivery receipt declares push completion. Automated secret detection is complemented by the source allowlist and manual review; it is not a proof that heuristics detect every possible secret.

## Complete isolated backup and restore

PASS — 92 PostgreSQL tables / 698 rows, 5 sequences, 1477 constraints and 163 triggers compared; runtime/migrator privileges preserved. All row values, object versions and checksums matched. 3 private documents (1833 bytes) and 2 MFA credentials recovered with the application/storage keys. Ten authenticated encrypted archives were restored; ciphertext corruption, truncation, trailing bytes, bad headers, wrong context and wrong key were rejected. Transaction IDs were advanced past the source high-water mark so historical IDs cannot recur.

Backup: 50386 ms; restore: 70098 ms; complete drill including startup/workflow/cleanup: 433616 ms. Both generated projects and their owned temporary resources were removed. [Full restore evidence](unified-vps-final/backup-restore.json), [test resource cleanup](unified-vps-final/owned-resource-cleanup.json).

Disk was measured before work and again before restore: approximately 9.6 GB initially and 10.2 GB before the restore sequence. The planning requirement was 7 GiB total (4 GiB restore allowance + 1 GiB build/scans + 2 GiB protected system reserve); the immediate restore threshold was 6 GiB. This is a local synthetic-drill planning allowance, not production backup sizing. No broad Docker prune or unrelated deletion occurred. [Capacity preflight](unified-vps-final/capacity-preflight.json).

The scope is quiesced PostgreSQL logical backup plus a cold private-object-store copy and separate authenticated key archives. It does not certify production-provider recovery, PITR, an offsite key escrow process or live online backups.

## Updated target VPS and remaining deployment gates

The user-supplied verified target is **Ubuntu 24.04.5 LTS**, kernel **6.8.0-142-generic**, **2 vCPU**, **3.8 GiB RAM / MemTotal 4,009,872 kB (4 GB class)**, **2 GiB swap**, **38 GiB root disk / approximately 34 GiB free**, Docker **29.8.1** and Compose **5.5.1**. The old 1.9 GiB hardware blocker is superseded by this snapshot; the historical report is retained unchanged.

No candidate source/release blocker remains after the gates above pass. Actual full-stack capacity/performance certification on that VPS remains a controlled deployment gate, including the scanner and every required worker. No security control, worker or scanner limit was weakened to fit the target. Hardware specifications and local functional checks alone do not establish production capacity. Deployment, traffic conversion, production Gemini activation, main merge and production tagging remain unauthorized by this freeze.

## Delivery receipt

The authorized publication target is only `origin/release/unified-vps-candidate`. The final receipt must record (1) this remediation commit SHA and parent, (2) a fresh matching remote SHA, (3) clean tracked/untracked working-tree state and (4) the same immutable contract digest. The receipt and chat delivery supply these post-commit values without rewriting this evidence commit.

UNIFIED BACKEND VPS CANDIDATE FROZEN — WEBSITE + DASHBOARD COMPATIBILITY VERIFIED
