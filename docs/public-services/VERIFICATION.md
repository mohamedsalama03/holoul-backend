# PublicPortfolio + Contact — verification and local handoff

**BACKEND FEATURE READY FOR WEBSITE / DASHBOARD INTEGRATION.** Verified on 29 September 2026. This is an integration candidate, not Production Ready, frontend acceptance, a production deployment, or a user/data cutover. Target-VPS performance certification remains pending.

## Candidate identity and preserved history

- Application baseline: `e3957df723ea01a6005feaec90d8130a26c93b62`.
- Reviewed proposal R2 / development parent: `0f3be1e4c1ec31a6faed7a4d71cfbb4a02e3003d`.
- Implementation checkpoint: `94d48368a2a3722ed3d147e02b0875e266844d6e`.
- Runtime checkpoint: `7c9de8d5eaaf30fc4838445132b5679f495979cb` (fixes only the read-only taxonomy diagnostic helper bootstrap; no domain/API change).
- Branch: `codex/public-services-candidate`; checkout `/home/mohamed/projects/customers/holoul-api-reference`.
- Earlier B8/P1/P2/P3/F1-E1/G1/identity/staff/C1 evidence and Git history remain intact. No squash, production-release claim, remote push, or frontend contract update was performed.
- All 20 inherited session/guest/concurrency test files are byte-identical to the proposal parent; see `evidence/inherited-security-tests-preserved.json`.

## Contract

| Item | Result |
|---|---|
| Format / version | OpenAPI 3.1.1 / `1.4.0-public-services-candidate` |
| Previous SHA-256 | `fafbc8b62976788dae0cc98492add8bed014228ad42844d2c7498ca57d1a7f4e` |
| Final SHA-256 | `7739bd957665b285c3baea8a4883aa987d6d11dee3fb43bcc5ef1ff9fb0c8051` |
| Additions | 20 operations, 29 schemas, 1 parameter |
| Totals | 202 operations, 250 schemas |
| Compatibility | All 182 existing operation objects and every existing component object are unchanged |
| Real responses | 2778 validated; 175 operations observed, 151 success-covered, all 146 required success operations covered; zero schema/status failures or undocumented successes |
| Route drift | Final baked runtime: 202/202; no missing or undocumented API route |
| Source binding | All 510 reviewed source-manifest files match the final running image |
| Source-manifest SHA-256 | `7e87f346970d55767257ef09adc2dcd8ae41e42a17435d8217f126fdb362231d` |

The authority remains `docs/openapi.json`. `CHANGELOG.md` lists every new operation/schema. `response-examples.json` exports real synthetic responses for all 20 new operations plus 10 scenarios, including draft/rejected image, saved and repeated receipt, withdrawal and errors. No credentials, session cookies, MFA keys or recovery codes are in the handoff. Example image URLs were deliberately withdrawn during verification; they are not a permanent public demo.

## Verification results

| Gate | Evidence / result |
|---|---|
| Complete PHPUnit invocation | 1018 tests, 65110 assertions in 36m48.908s: 1017 passed; one historical Staff migration-fixture scope failure, resolved below |
| Final exact upgrade rerun | 2 tests / 220 assertions passed: historical G1→Staff and new 31→34 transition |
| New PublicServices tests within full run | 46 tests / 791 assertions, zero failures |
| Architecture within full run | 9 tests / 51245 assertions, zero failures |
| Session / concurrency classes within full run | 93 tests / 1827 assertions, zero failures; includes inherited PostgreSQL workers and guest/session race coverage |
| Real transport regression | 4 tests / 43 assertions passed, including Contact notification routing and inherited Documents queue isolation |
| Image processor | 5 Python tests passed: static formats, metadata/orientation/bounds, animation/disguise/truncation/trailer/oversize rejection, pixel bomb and real bounded socket worker |
| Real private storage / image adapter | 1 test / 12 assertions passed; also exercised through the live HTTPS workflow |
| PHPStan / Larastan | Level 10, no errors (`phpstan-final.txt`) |
| Pint | 660 files passed; repaired diagnostic helper also passed its targeted check |
| Composer | Strict validate passed; audit of lock: zero advisories and abandoned packages |
| OpenAPI validator | Positive/negative sentinels passed; 7 independent mutation-rejection tests passed |
| Fresh migration | 34 migrations on a separate PostgreSQL database, migrator identity |
| Exact shared local upgrade | Actual `holoul` 31→34 under `holoul_migrator`; all 12 pre-existing table fingerprints unchanged immediately across migration |
| Docker | Pinned runtime/development/processor builds passed; FPM/Nginx syntax and final service health passed |
| Vulnerability scans | Final runtime and processor: zero reported vulnerabilities against the downloaded scanner database; scanner warns that Alpine 3.24 is absent from its EOL lookup. This is a scan result, not security or production certification |
| Real HTTPS backend at 8443 | 42 checks passed: CSRF/login/MFA/read/logout, receipt retry and redaction, real reference-only Mailpit delivery, image processing/rejection, publication and withdrawal |
| Existing frontend regression | F1 authentication 6/6 and Overview 1/1 passed on real 8443; zero skips, retries or flaky results in the final run (69.065s) |

### Exact meaning of the PHPUnit result

The completed full invocation is retained as a **failed invocation with one failure**, not relabelled green. `StaffOnboardingUpgradeTest` previously ran every pending migration while asserting exactly 31 and unchanged permission rows. Once this additive batch existed, it applied 34 migrations and failed its count assertion. Its migrate calls now explicitly target the original Staff migration. The original 30-file baseline hashes, exact 31 count, data comparisons, repeatability and rollback assertions remain. The separate PublicServices upgrade test verifies all 34 migrations, historical data, only the approved new grants, and runtime privileges. Both tests passed the final rerun. No application source changed after the full suite, and no unresolved failure remains. See `phpunit-summary.json`, the original JUnit XML and the final two-test XML. No single all-green full invocation is claimed.

### Retained failed or unavailable checks

Earlier development evidence remains in the repository’s `docs/public-services/evidence` directory (the handoff ZIP contains selected sanitised proof): missing WebP shared libraries in the first processor build; initial static/style issues; empty-schema rollback guards that were too broad; canonical local-test DNS configuration mismatch; a real Contact queue dispatch defect; an invalid failed-operation test fixture missing completed_at; and an isolated HTTP rerun stopped by its real rate limit. Each was corrected and verified without relaxing authentication, throttling or concurrency guards. The first two full invocations were interrupted while these issues were repaired, and their logs remain.

After catalogue import, the first read-only inventory helper invocation failed because its Kernel alias was declared after use. The helper was corrected and verified both through stdin and from the final baked image. The successful import was not repeated as a destructive operation; its intentional idempotent repeat added zero rows.

The first frontend attempt skipped all seven tests because the existing Windows dashboard listener had stopped. It is recorded as skipped, not passed. Restarting the existing production build on loopback restored ingress; the final seven tests actually executed and passed. No frontend source, test, timeout, contract or security setting was changed.

Offline Swagger structural checks passed: local asset references, embedded contract equality, hashes and counts. **Visual preview was not performed:** the in-app browser rejected file:// under its protocol policy, and no alternate transport/browser workaround was attempted. The standalone files and ZIP are supplied for the owner to open locally.

## Final local runtime

The shared local origin is **https://localhost:8443**. Mailpit remains **http://localhost:8025**. The dashboard uses its existing production build on Windows **127.0.0.1:3001**, started without source edits; its HTML is reached through `/admin`. These are local services, not production endpoints.

| Image | Local Docker image ID |
|---|---|
| `holoul-app:public-services-runtime` | `sha256:576eac4e84397946f47004059d5a0512a08923cb757727fc8c97c94522909079` |
| `holoul-app:public-services-development` | `sha256:d020dc533c1cf94fb7b47ef44904eb094710e91542d8f268f3742148b84ae9c6` |
| `holoul-portfolio:public-services-candidate` | `sha256:2bfe69025432b3d87c0d5126bdb1eca0f99f9e2159da402bd3653169f1108abe` |

All six app/default queue/document queue/AI queue/notification queue/scheduler processes use the same final runtime image. The final snapshot records 14 healthy services. Debug is false, runtime identity is `holoul_app`, migration identity remains separate, schema count is 34, and Contact production-mail/dashboard flags and automatic redaction are false. New permissions are granted only to Super Admin and Administrator; existing staff grant policy and wire capabilities remain unchanged.

The 42-step HTTP run used the immediately preceding image `sha256:1fa85ff6d2af27bea93ecb98a646779b27377484eeb552818af4c68bed15f828`. The only subsequent executable-source change was the standalone read-only inventory helper, followed by coordinated image refresh. All 510 reviewed application/contract source files match the final image exactly; final route, configuration, health, baked-helper and seven browser regressions ran on the final image. This distinction is preserved in the raw runtime report.

Local Compose files, in order:

```text
/home/mohamed/projects/customers/holoul-api-reference/compose.yaml
/home/mohamed/projects/customers/holoul-api-reference/compose.public-local.yaml
```

Use project `holoul`; keep initialized volumes and the existing certificate. Never run migrate:fresh, reset volumes, or run an old checkout's default image against this shared local environment. The verification database/project remains separate. No target VPS was accessed.

Before migration, all six old application processes were stopped. A 21,536,171-byte authenticated encrypted PostgreSQL backup was created, with its random key stored separately outside Git. Decryption matched the original in-memory archive and pg_restore could read its catalogue. No plaintext backup was written to disk and no restore over live data was executed. This check is not a target-VPS restore/RPO/RTO certification. See the backup report; the bundle/key remain private and are not part of Swagger.

## Catalogue and cutover status

The approved catalogue was added locally: **12 parents / 49 children**. An immediate repeat added **0 / 0**. The local database now contains 66 parents and 102 children including historical/test rows; it is **not declared a clean launch-only catalogue**. All 16 B8 parents and 16 B8 children, their versions, active flags and draft/revision references match the pre-import inventory. There are still 16073 requests, 16073 drafts and 11541 immutable revisions. No B8 row was disabled, renamed, deleted or reparented.

The legacy source inventory found one published project and four images. The old source and its access key/session were not migrated or modified. Synthetic dry-run/import/replay/alias tests passed. Real source freeze, rights review, consistent export, import/publication and user cutover remain subject to the separate acceptance plan. See `TAXONOMY-AND-LEGACY-CUTOVER-AR.md`.

## Remaining acceptance boundaries

- Website/Dashboard must adopt this contract in their own commits and implement the new views; this batch does not perform that work. Their existing contract hashes and worktree changes remain unchanged (`frontend-preserved.json`).
- The full website/browser/proxy/CDN withdrawal window must be measured after integration. The local edge has no shared response cache; origin withdrawal was observed in 63.58ms across the three checked resources, measured after the successful unpublish response (not a commit-to-cache timing bound). This proves the tested origin behavior, not the promised aggregate 60-second website cache budget.
- Contact recipient is **info@holoul.ly**. Local mail is captured only in Mailpit. The authenticated dashboard message page and real-mail configuration must be accepted before production links/mail are enabled. No mailbox address grants account authority.
- **180-day retention is unapproved; automatic redaction/deletion stays disabled.** Manual authorised, audited redaction is available. Backup-retention exceptions and reapplication after restore require the documented owner policy.
- B8 deactivation and real portfolio transfer need reviewed cutover approval. No automatic launch cleanup was added.
- VPS load/performance and recovery certification remain pending. Historical failed B8 p95 evidence (662.590ms / 699.075ms) is retained and is not superseded by these local checks. Query plans against 2000 rolled-back synthetic portfolio rows are diagnostic evidence only.

No frontend feature, F2/another backend batch, production deployment, user conversion, automatic content-retention job or AI enablement was started.
