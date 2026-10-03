# Unified VPS candidate freeze — BLOCKED

3 October 2026. **UNIFIED BACKEND VPS CANDIDATE BLOCKED**. This is an accumulated local candidate checkpoint, not a production release. No VPS access, deployment, frontend modification, main merge, production tag or push was performed.

## Candidate identity and preserved history

The authoritative checkout is `/home/mohamed/projects/customers/holoul-staff-direct-create`. Original branch: `codex/staff-direct-create`; original HEAD and candidate parent: `9119a094da23cc23c93475be79d076c4c1f2de21`. Its committed API was `1.5.0-intake-display-candidate`; it is not the newer working-tree contract frozen below. The older VPS G1 reference remains `b6d3f27ee9fbde274b04a75c4d4ece35aa8e4de0` (API 1.1.0, 172 operations).

Before any Git mutation, 60 modified files, 34 untracked files, 172 ignored files and zero staged files were inventoried. An independent complete working-tree archive, manifests, binary patches, remotes and verified all-ref Git bundle are preserved privately at `/home/mohamed/.local/share/holoul-freezes/unified-20261003T183220Z`.

- Original source fingerprint: `09461761bb216e70f4a1d2886534a96e6b80ae2bd4493be08d151268b2df57ca`.
- Complete original manifest SHA-256: `ab94ebf7e95795a716ca84fd2eb34e7f2c38ba9b3d73c3367ebfc34e5fa09774`.
- Verified original archive SHA-256: `202f72f139b4f700090e751e7a9bfc2e0d0bd11070000636d87a91db38a82aed`.

The candidate branch is `release/unified-vps-candidate`. Its introducing commit is the one accumulated checkpoint containing this report, directly parented by `9119a09`. Obtain its exact identity with `git rev-parse HEAD` and `git rev-parse HEAD^`; the final delivery and external `final-candidate-identity.json` record those exact values. A document cannot embed its own commit hash. No historical feature commits were reconstructed or backdated.

Nine inherited session/concurrency guard and test files and 61 historical verification documents are byte-identical to the parent: see [preservation proof](unified-vps/inherited-preservation.json). Prior B8/P1/P2/P3/F1-E1 failures remain preserved. No inherited migration was edited.

## Classification, exclusions and secrets

[Complete classification](unified-vps/change-classification.json) lists every changed/untracked candidate file under A–J and every ignored exclusion. Accumulated application changes cover direct staff/username accounts, email prerequisites, portfolio editor authorization and Gemini idea/document assistance; no additional product feature was introduced by this freeze. The original large formatting diff in `files-async-reporting.json` was retained, not rewritten to conceal its history.

Local Compose overlays and `scripts/set-gemini-key.py` remain local-only source in Git. They are excluded from the production image and are not a production topology. Gemini fixtures and test identities are synthetic. The key helper accepts hidden interactive input, writes atomically outside the repository with mode 0600 and neither sends a request nor activates AI. Actual credentials, certificates, private environment files, E2E manifests, HTTP/session/token captures and raw runtime artifacts remain outside Git. Ignored material is preserved privately, not deleted.

[Secret scan summary](unified-vps/secret-scan-summary.json) covers selected candidate source and 1,431 unique blobs from all reachable existing history. Both scans initially reported zero secrets; final rescan status is recorded there. The actual local Gemini key was compared in memory against candidate/history and all 12,020 regular production-image files, with zero matches; its value was never emitted. Automated detection is supplemented by review of configuration templates, local overlays, the key helper and synthetic fixtures; it is not a claim that a heuristic can prove the absence of every possible secret.

## Exact contract and consumer compatibility

- OpenAPI: **3.1.1**.
- API: **1.8.1-gemini-documents-candidate**.
- Operations: **204**; schemas: **265**.
- SHA-256: **`3c93a09f08ce199cc1c2867ad846a1ca554fd03b6d934070178c5101646df410`**.

No contract bytes were changed during this freeze. [Machine-readable compatibility](unified-vps/frontend-compatibility.json) compares every consumed operation plus recursively referenced request/response components, methods/paths, statuses, required headers, authentication/security schemes, CSRF, If-Match, Idempotency-Key and enums. Source evidence is listed per operation. Dashboard calls were extracted from production TypeScript API calls plus explicit CSRF bootstrap; Website registry entries were checked against production usage, including server-rendered public content and image paths.

**Website: 36/36 compatible**, using the identical contract/hash. **Dashboard: 67/67 compatible**, against `1.7.0-portfolio-editor-candidate`, SHA-256 `ef0a849656234b937a1d76e7258835de5438bcb7bbd57a87f38b4ce011275761`. There are no missing or incompatible consumed operations or unresolved source calls. Dashboard has one annotation-only difference for `identityGetCurrentUser`, describing the approved customer email admission behavior; structural/security shapes match. Read-only native frontend contract/generated-artifact checks also passed. This is contract compatibility, not a new browser E2E or target-VPS certification.

Generation, source-manifest/endpoint-matrix equality, unique operation IDs, OpenAPI schema and actual-response checks are reported in [contract verification](unified-vps/contract-validation.json). The unchanged route/spec PHPUnit guard compares all registered API routes, allowing only the two health routes outside the public contract. Neither frontend checkout nor its contract copy was edited.

## Freeze-only changes and runtime provenance

1. Production packaging now uses an explicit Dockerfile allowlist. It excludes tests, E2E/performance/Gemini fixtures, local Compose overlays, raw artifacts, development dependencies, Git history and key helpers from every application runtime layer. The launch taxonomy catalog is retained for explicit operator use; no startup import of demo/customer/portfolio data was added.
2. Synthetic restore helpers are bound read-only only in `compose.restore.yaml`. The restore driver derives strict Host/Origin mappings for its isolated random loopback ports; it does not change or relax live ingress. Full restoration remains unverified below.
3. A new populated upgrade test checks hashes of all 30 migrations from the exact frozen G1 commit, upgrades to all 36 migrations, preserves historical rows/grants/ownership/revisions and repeats without changes. Historical fixture builders in five inherited upgrade suites use the existing pre-username test reader only while running the old schema. Their preservation and authorization assertions remain intact; no runtime fallback or migration edit was added.

4. Four inherited expectations were aligned with already-approved behavior: unverified customers can submit while email ownership remains false and staff access stays denied; the authorized identity carries the non-secret email-prerequisite boolean; Administrators can grant the portfolio-editor role; portfolio editors retain portfolio capabilities but cannot access Contact. Existing forbidden-route and last-Super-Admin checks remain intact.

Final application image: `holoul-app:unified-vps-candidate`.
Image ID: **`sha256:5ecf0a383ec5afb60abfd7c86fbbda33f9747c6cdab33d0a4da86ec78dc2072d`**.
It was built from pinned PHP/Composer dependencies, not from the previously running retained-runtime shortcut. [Image inspection](unified-vps/production-image-inspection.json) verified all 542 included source/runtime files against this candidate, 82 installed production packages, no development packages, no unexpected application files or sensitive ENV names, and the configured non-root `holoul` identity. A read-only core boot with AI disabled and no Gemini key passed as UID 1000 under the local verification profile; production policy unit tests are separately covered. The live 8443 stack was not switched to this image.

The first allowlist build omitted the launch taxonomy catalog; its failed inspection is retained privately and the final build corrects the omission. Earlier harness errors and failed checks are likewise retained rather than discarded.

## Quality results

[Machine-readable test results](unified-vps/phpunit-summary.json) retain the initial full run and every corrective run separately.

| Check | Result |
| --- | --- |
| Initial full Unit + Feature | 1,087 tests / 14,203 assertions; 8 failures, 7 errors, 0 skips. This failed result is preserved. |
| Historical + exact frozen G1 upgrades | 14 tests / 3,225 assertions; pass, including the new 30 → 36 populated/repeat migration case. |
| Corrective classes | 130 tests / 1,209 assertions; one added legacy-staff prerequisite assertion initially expected true incorrectly. |
| Corrected legacy-staff authority class | 20 tests / 83 assertions; pass. Unverified legacy staff truthfully retain prerequisite=false. |
| Latest result per class | 1,088 tests / 15,794 assertions represented, 0 remaining errors/failures/skips. This is composed coverage, **not a single repeated all-green full-suite invocation**. All 15 initially failing cases have passing class reruns. |
| Architecture | 9 tests / 52,363 assertions; pass. |
| PostgreSQL concurrency classes | 58 Feature cases / 756 assertions; pass. Wrapped-40001 classifier: 1 Unit test / 5 assertions; pass. |
| Inherited session/guest/public races | SessionLifecycle 18/830, GuestSessionIsolation 9/176, PublicSessionRace 4/60; all pass. MFA concurrency 2/11 also passes. |
| Staff | DirectStaff 19/551; StaffInvitations 20/389; staff onboarding concurrency 6/47; customer/staff email policy tests pass. |
| Gemini | Provider 30/152, workflow 5/45, document analysis 6/92; all pass with synthetic provider responses and no external key. |
| Parsers and images | 17 real PDF/DOCX parser tests and 5 isolated image processor tests pass; private versioned storage and portfolio roundtrip pass on the corrected isolated topology. |
| Contract | 3,562 actual responses, 179 observed operations, 156 with successful samples; all 148 required successes covered. No schema/status/header drift or undocumented successes. 16 Python validator/intake/manifest tests pass. |
| Pint | Final clean-source run: 684 files pass. |
| PHPStan/Larastan | Level 10 passes on the unchanged application source. |
| Composer | Strict validate, locked audit, install/platform requirements pass; zero reported advisories. |
| Production Docker | Fresh pinned build, allowlist/source comparison, non-root runtime and AI-disabled boot pass. |

Initial failure causes were separated: five historical schema fixture errors; four stale assertions reflecting approved email/portfolio changes; four strict-topology/readiness assertions caused by test-only DNS names; and two storage adapter errors caused by a missing isolated storage endpoint. Corrective verification used `postgres`/`redis` aliases and a new memory-backed private TLS storage instance with synthetic objects. No live object data or database was used. The complete original logs, unsuccessful setup attempts and intermediate assertion failure remain in the private evidence directory. Owned temporary test resources were removed.

All database integration, migration and concurrency checks use real PostgreSQL `holoul_test`, with separate runtime/migrator identities. No live `holoul` database was reset. Initial full-gate source is preserved separately from corrected test fixtures; focused retries do not erase the initial result or imply a single all-green final full run. External provider calls were disabled: enabled Gemini tests use synthetic HTTP responses, including idea assistance, document authorization, durable runs, apply/dismiss and failure cases. No production Gemini access was activated.

## Security, restore and capacity blockers

[Security findings](unified-vps/security-scan-summary.json): source including development dependencies and the final application image each have **zero HIGH/CRITICAL findings**. Trivy 0.74.0 was pinned to image digest `62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969`. Infrastructure scans found **four HIGH findings across three images**, with zero suppressions:

| Image | Finding | Installed → fixed package |
| --- | --- | --- |
| `holoul-inspector:b8-runtime` | CVE-2026-93990 | libexpat 2.8.4-r0 → 2.8.5-r0 |
| `holoul-scanner:b4-runtime` | CVE-2026-103111 | pcre2 10.48-r0 → 10.49-r0 |
| pinned Nginx 1.30.5 Alpine | Both findings above | Both packages above |

Mailpit, PostgreSQL, Redis, private storage and portfolio processor image scans passed. The affected images need rebuilt/patched pinned versions and repeat inspection/scans. They are not accepted exceptions.

[Backup/restore](unified-vps/backup-restore-summary.json) is **incomplete**. The candidate image passed a 131,328-byte encryption roundtrip and rejected all six corruption/key/context negative cases. The isolated drill was interrupted during source startup to prevent further host disk exhaustion; source/target database/object restoration was not certified. Its owned resources were cleaned and its failure evidence retained. Windows C had approximately 1.4 GB free; no broad prune, Docker reset or deletion of user data was used. A complete isolated restore retry requires sufficient local capacity.

The supplied target is Ubuntu 24.04.5, 2 vCPU, approximately 1.9 GiB RAM, 2 GiB swap, approximately 38 GiB usable disk, Docker 29.8.1 / Compose 5.5.1. **Safe production capacity is not established and blocks deployment.** The approved scanner has a 4 GiB container ceiling; ClamAV's [official requirements](https://docs.clamav.net/Introduction.html) recommend at least 3 GiB RAM for the scanner alone, with additional memory for other applications. Local idle scanner use of approximately 761 MiB is not its peak/signature-reload requirement or load certification. PostgreSQL, Redis, storage, required workers, inspector, processor, edge and both frontend runtimes also need resources. Swap does not certify safe latency or peak operation. No scanner, worker or authorization guarantee was reduced to fit.

The repository's localhost Compose overlays and `host.docker.internal` routing are local verification topology. PostgreSQL and Redis have no published public ports. Production still requires a reviewed origin/TLS plan for `https://holoul.com.ly` with `/` → Website, `/admin/*` → Dashboard, and `/api/v1/*` plus `/sanctum/*` → Laravel; adequate capacity and target performance certification remain pending. No VPS was accessed. The approximately 38 GiB target disk was not certified for image layers, data, scan signatures and retained backups.

## Required delivery checklist

| # | Requested evidence | Result / location |
| --- | --- | --- |
| 1 | Original branch / HEAD | `codex/staff-direct-create` / `9119a094da23cc23c93475be79d076c4c1f2de21` |
| 2 | Original fingerprint | `09461761bb216e70f4a1d2886534a96e6b80ae2bd4493be08d151268b2df57ca`; independent verified archive and bundle above |
| 3 | Candidate branch | `release/unified-vps-candidate` |
| 4 | Candidate HEAD + parent | Introducing commit of this report; exact SHA delivered with external final identity record; parent `9119a094da23cc23c93475be79d076c4c1f2de21` |
| 5 | Complete diff | 120 files changed, 20,435 insertions, 7,507 deletions; full per-file classification linked above; exact staged numstat preserved privately |
| 6 | Exclusions | Every ignored path and its exclusion reason in `change-classification.json`; no ignored/private material staged |
| 7 | Secret scan | Candidate/history zero findings; exact real Gemini key absent; final rescan recorded in secret summary |
| 8 | OpenAPI version | 3.1.1 / API 1.8.1-gemini-documents-candidate |
| 9 | OpenAPI hash | `3c93a09f08ce199cc1c2867ad846a1ca554fd03b6d934070178c5101646df410` |
| 10 | Operations / schemas | 204 / 265 |
| 11 | Website compatibility | 36/36 compatible; exact same contract hash |
| 12 | Dashboard compatibility | 67/67 compatible against its pinned 1.7.0 contract |
| 13 | Missing/different consumed operations | None structurally; one documented identity description delta only |
| 14 | Migrations | 36; fresh, populated exact frozen G1 upgrade and repeat pass; 14 historical/current upgrade tests pass |
| 15 | PHPUnit | Initial 1,087/14,203 failed; corrected class coverage 1,088/15,794 all passing, with separate run evidence and no claim of one clean full rerun |
| 16 | Architecture | 9 / 52,363 pass |
| 17 | PostgreSQL concurrency | 58 / 756 pass, plus nested-error classifier 1/5 |
| 18 | Identity/session | Inherited race guards unchanged; session, guest isolation, public race, MFA and revocation tests pass |
| 19 | Public Services/Portfolio | Contact restrictions, portfolio publication/withdrawal, cache/security, taxonomy and real storage/processor tests pass after fixture/topology corrections |
| 20 | Staff/authorization | Direct create, usernames, invitations, roles, last-Super-Admin and portfolio-editor grant boundaries pass |
| 21 | Gemini/AI | 41 Gemini tests pass with synthetic responses; disabled core boots without key; no production activation |
| 22 | PHPStan / Pint | Level 10 pass / final 684 files pass |
| 23 | Composer | Validate, audit and platform checks pass |
| 24 | Production image | `sha256:5ecf0a383ec5afb60abfd7c86fbbda33f9747c6cdab33d0a4da86ec78dc2072d`; 542 source files match; non-root; no dev dependencies |
| 25 | Vulnerability scans | Source/app image zero HIGH/CRITICAL; infrastructure four HIGH across inspector, scanner and Nginx; no suppressions |
| 26 | Backup/restore | Encryption/negative probes pass; full restore incomplete due host disk capacity; not certified |
| 27 | Resource/capacity | Target ~1.9 GiB RAM is not certified for required full topology; scanner official recommendation alone exceeds it; disk/performance also uncertified |
| 28 | Deployment blockers | Affected infrastructure images, incomplete restore, target capacity/topology/performance certification; exact candidate remains blocked |
| 29 | Final preservation | Separate verified final archive, manifest and history bundle; exact location/hash in external final identity record and delivery |
| 30 | Remote candidate | Queried absent; no remote SHA and no push attempted |

Final working-tree preservation, exact commit/parent, staged diff and the verified final history bundle are recorded in the external final identity record. The original private archive remains separate. Source manifests distinguish working-file bytes from Git's LF normalization; no application, migration or OpenAPI file required line-ending normalization.

`origin/release/unified-vps-candidate` was queried read-only and did not exist; no push was attempted because passing all required gates is an explicit prerequisite. No merge or production tag was created. Before any push or deployment review, resolve all recorded gate failures, rebuild/rescan affected infrastructure, complete restore verification, provide sufficient capacity and verify the final exact topology/candidate.

UNIFIED BACKEND VPS CANDIDATE BLOCKED — DEPLOYMENT NOT AUTHORIZED