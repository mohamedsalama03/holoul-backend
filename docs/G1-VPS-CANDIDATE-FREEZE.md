# G1 VPS deployment candidate freeze — 28 September 2026

**DEPLOYMENT CANDIDATE READY FOR VPS**

This is a verified Git candidate for separately authorized deployment preparation. It is **not Production Ready**. No VPS access, deployment, public activation, production tag, main merge or frontend work was performed. Target-VPS capacity/performance certification remains pending.

## Candidate identity and history

- Original branch / HEAD: `main` / `96445baded70ddd2e4d8b8617793c78a4a3e1816` (B7).
- Dedicated candidate branch: `release/g1-vps-candidate`; remote destination: `origin/release/g1-vps-candidate`.
- Direct parent: `acd04c1dcf8d9ec0a66bdf88fe061fe6b7f340f6` (reviewed G1 final acceptance).
- The candidate commit is the HEAD containing this report. Its exact full SHA, verified remote SHA, final status and complete per-file diff are recorded in the separately delivered push receipt; a commit cannot embed its own hash.
- B7 is an ancestor. Preserve the existing real review chain: `55b3b79` accumulated B8/P1/P2/P3/F1-E1 checkpoint, `9940acf` approved identity race/concurrency remediation, `7ec83d4` G1 implementation with then-blocked frontend evidence, `91f191a` F1-E2 integration support, and `acd04c1` final G1 acceptance. Add this freeze on top. No reconstruction, rewrite or squashing of those boundaries.
- `codex/staff-invitations` and its later contract are outside this freeze. No unrelated feature was merged.

## 20 requested evidence items

| Item | Result |
| --- | --- |
| 1. Original HEAD | `96445baded70ddd2e4d8b8617793c78a4a3e1816` |
| 2. Candidate branch | `release/g1-vps-candidate` |
| 3. Candidate HEAD | Exact full immutable SHA in the final push receipt and branch HEAD; not a production tag. |
| 4. Parent | `acd04c1dcf8d9ec0a66bdf88fe061fe6b7f340f6`; B7 ancestry verified before commit. |
| 5. Complete diff summary | Full `B7..candidate` and `parent..candidate` per-file name/status, numstat and stat are delivered with the receipt. Source inventory and original-change classification are retained below. |
| 6. Excluded files | All 17,293 original ignored entries stay outside the commit. Private `.env`, generated credentials/certificates/keys and raw captures are I; local `artifacts/**` H/I; vendor, caches, compiled/generated output J. Test source is retained as F. |
| 7. Secret scan | Candidate and 993 reachable historical blobs scanned; zero Trivy secret findings. Six custom-pattern hits reviewed as non-secret variable/name/test sentinels. A final complete public-source scan is required before staging; its exact exit/hash is in the receipt. |
| 8. OpenAPI | 3.1.1 / API 1.1.0-g1 / 172 operations; unchanged SHA-256 `ae8d0a904a0042b80f9adc1224dedf8677794e2d49ca5cdf41daeb5278780be8`. |
| 9. Migrations | 30 fresh migrations; exact populated 27-migration B7 → 30 upgrade and repeat pass (1 test, 410 assertions); inherited upgrade tests retained. |
| 10. PHPUnit | **938 tests / 12,718 assertions**, zero failures/errors/skips. |
| 11. Architecture | **9 tests / 42,408 assertions**, zero failures/errors/skips. |
| 12. Concurrency | 47 tests / 651 assertions in named real PostgreSQL concurrency suites; plus session lifecycle and guest rotation tests below. |
| 13. Static analysis / style | PHPStan/Larastan level 10: zero errors. Pint: 571 files PASS. |
| 14. Composer | Strict validate, platform requirements and locked dependency audit PASS; zero advisories. |
| 15. Docker production build | Final development/runtime builds, patched inspector/Nginx builds, Compose validation and FPM config PASS; exact image IDs in verification JSON. |
| 16. Runtime exclusions | PASS: app uid 1000; no tests/tools/local fixtures/credentials/dev dependencies or compiler/Composer. Only `scripts/backup-seal.php` retained as an operator primitive. |
| 17. Backup / restore | Third complete isolated drill PASS: 667 rows, 3 private available documents, exact rows/sequences/object versions/checksums, runtime grants and MFA/key recovery. All drill resources removed. |
| 18. Unresolved risks | VPS capacity/performance and actual production topology/TLS/providers/recovery remain deployment gates. See separate configuration review. |
| 19. Final working trees | Original main source/index preserved; candidate must be clean after commit. Exact statuses are in final receipt. One ignored local fixture credential file changed during verification; it is excluded and not written by this freeze. |
| 20. Remote branch | Explicit push of `release/g1-vps-candidate` only, followed by SHA equality check; receipt is the proof of successful publication. No main push or tag. |

## Source review and change boundary

Before Git mutation, record 582 tracked files, 71 modified tracked files, 155 untracked files, no staged files, and 17,293 ignored entries. A complete 18,030-entry fingerprint (paths, hashes, sizes, modes, classes) and an all-ref Git bundle are retained privately. Fingerprint SHA-256: `b84306aa0d568a62849fa2a3d07d75447565517c7caeeae4732014eeb78f151a`.

The public per-file review is [G1-VPS-SOURCE-REVIEW.json](candidates/G1-VPS-SOURCE-REVIEW.json): 757 source files before the final reports, classified A=387, B=52, C=30, D=12, E=1, F=172, G=103. Each of the original 226 modified/untracked files is accounted for. Generated freeze reports/evidence are category G. No H/I/J entry is staged. Full ignored filenames/raw evidence remain private because generated paths and captures can contain fixture identifiers and secrets.

All 737 original trackable files remained unchanged in the original working tree. A later rehash matched 18,029/18,030 entries; the only observed difference was ignored `artifacts/local-e2e-private/frontend.env`. No historical B8 performance evidence changed. See [preservation evidence](candidates/G1-VPS-ORIGINAL-PRESERVATION.json). The freeze preserves the original main checkout and creates a separate candidate worktree.

All 556 inherited application, bootstrap, config, route, migration and test PHP/JSON/Python files checked against G1 acceptance are byte-identical; [hash proof](candidates/G1-VPS-INHERITED-SOURCE-PRESERVATION.json). No business or identity implementation was changed. Preserve permanently the identity rotation/late-cookie tests, CSRF lifecycle, wrapped 40001 classification, guest ownership/claim/session tests and private document tests.

The new freeze delta is limited to:

- Production packaging excludes verification/fixture source while preserving it in Git/development. Explicit local verification and synthetic restore overlays mount drivers read-only when needed.
- Nginx and inspector enforce security-fixed libexpat >=2.8.5-r0. Actual tested versions are 2.8.5-r0. No security findings were suppressed.
- Isolated restore uses its generated exact Host/Origin ports, an exact allowed health-check Host, explicit ingress negative checks and private failed-command diagnostics. Strict production/source ingress maps are not loosened.
- One B7 → current upgrade regression test (410 assertions) verifies exact baseline migration hashes, populated data, additive grants, guest ownership defaults and repeatability.
- Contract source provenance acknowledges the pre-existing local website upstream in the original worktree. OpenAPI bytes and frontend contract copy are unchanged.
- Pending website/team/taxonomy response/proposal documents and 16 accepted candidate evidence files are retained as documentation. No launch taxonomy import or feature activation occurs.

## Executed verification

| Group (overlaps possible; already included in 938) | Tests | Assertions | Result |
| --- | ---: | ---: | --- |
| G1 guest intake and isolation | 61 | 1022 | PASS |
| Authenticated intake HTTP | 45 | 456 | PASS |
| Identity/session security | 108 | 1541 | PASS |
| Real PostgreSQL concurrency suites | 47 | 651 | PASS |
| Document security | 105 | 1111 | PASS |
| Reporting and admin | 37 | 674 | PASS |
| B7 exact candidate upgrade | 1 | 410 | PASS |

SessionLifecycleTest independently accounts for **18 tests / 830 assertions**, including eight real late-request rotation cases. GuestSessionIsolationTest has **9 / 176**, GuestIntakeConcurrencyTest **8 / 102**, and WrappedConcurrencyErrorsTest **1 / 5**. These cover stale/revoked reads, ordinary reads without replacement cookies, login/MFA rotations, logout/new-login races, CSRF bootstrap, guest workflows alongside authenticated sessions, claim rotation/replay and wrapped PostgreSQL serialization failures. Full per-file counts are in [verification JSON](candidates/G1-VPS-VERIFICATION.json).

PostgreSQL is used for all integration tests, with runtime and migrator identities separated. The exact B7 manifest has 27 migrations; fresh current schema has 30. No inherited migration was edited. The full test runner also repeated migrations and compiled/cleared configuration successfully.

Contract validation: **2,232 actual captured responses**, 145 observed operations, 121 operations with successful samples; all 116 required successful operation samples present. Not every operation has a successful sample: the coverage limitation is explicitly retained. Zero validation failures or undocumented successes. Four negative-validator Python tests pass. Runtime route/spec comparison is **172/172**, zero missing/undocumented operations; previous and current contract hashes match, zero operations/schemas added or changed by this freeze.

The full PHP gate ran on the exact unchanged application/migration/test sources, including the new upgrade test. Five infrastructure files changed after that image was built, plus the new Nginx Dockerfile. Those changes were independently verified by the final image rebuilds, **17 inspector Python tests**, patched-image security scans, production exclusion checks and the full isolated HTTPS restore drill. The source comparison is recorded in verification JSON; the earlier image is not misrepresented as the final image.

Final application runtime image: `sha256:7779029b006749f600a982588c6619bc9fad7cfbe4f5530d864b7ba4ac4c3f8b`. Patched inspector: `sha256:e3a0cbbb324ab1dc4112401530f0602c479536a2c09552a84ac02899bcc5a534`. Patched Nginx: `sha256:8ab487964b06eb09143daa34bf65e625e74b90d13655b3059d9215c39d3885bf`. These are local immutable image IDs, not registry distribution digests; production must build/pin and verify its actual deployment artifacts.

Eight final runtime/service image scans have zero HIGH/CRITICAL findings using pinned Trivy 0.74.0 and the database fetched for this run. Inspector/Nginx initial scans failed on CVE-2026-93990 (libexpat 2.8.4-r0); retain those reports and the corrected 2.8.5-r0 rescans. Composer audit includes the locked development dependencies. Secret review is [documented separately](candidates/G1-VPS-SECRET-REVIEW.json); no raw credentials, cookies, CSRF, TOTP, recovery/claim/capability values or fixture records are published.

## Recovery evidence and honest failure history

The successful local synthetic drill verifies 76 tables / 667 rows, four sequences, 1,260 constraints, 148 triggers, runtime grants, three private documents, two encrypted MFA credentials and separate application/storage key recovery. Ten encrypted archives use libsodium XChaCha20-Poly1305 secretstream. Six corruption/context/key negative cases fail safely. Backup took 51,668 ms; restore 69,451 ms; complete drill 477,421 ms. Transaction high-water protection prevents historical IDs recurring. Wrong Host (400), wrong Origin (403), and forged forwarding metadata with correct origin (204) checks pass. Refused ingress responses do not emit cookies. Full customer/staff-MFA/project-delivery synthetic HTTPS flow passes. All isolated containers/networks/volumes were removed.

The first recovery attempt failed because the strict original ingress map expected the standard local port instead of the isolated random port. The second failed because its internal health probe used the internal Host after the map was corrected. Neither failure was suppressed: preserve both reports and exact corrective source. The third complete run passed. An initial route-counting harness also missed the bare `/api/v1` operation; the corrected count includes it without any route/contract change.

Historical B8 performance failures and B8/P1/P2/P3/F1-E1/F1-E2/G1 evidence stay in their existing history and private artifacts. This freeze does not reclassify those results, rerun target performance, or claim new frontend F1 E2E success. Existing GitHub Actions is broader than this freeze and retains its 45-minute timeout/performance scope; remote CI is not claimed passed.

## Deployment limits

Read [G1-VPS-DEPLOYMENT-CONFIGURATION.md](G1-VPS-DEPLOYMENT-CONFIGURATION.md) before preparing production. The owner-supplied target is Ubuntu 24.04, two vCPUs, approximately 2 GiB RAM plus 2 GiB swap and 40 GB disk. It was not accessed or measured. Local service memory caps sum to about 9.938 GiB, including a 4 GiB scanner cap; these are limits, not measured usage or minimum requirements, and cannot certify fit on the target.

Local Compose uses loopback/local certificates/Mailpit and a local-verification profile; it is not the public production topology. Production requires separately reviewed same-origin routing, TLS, secret provisioning, protected database/storage/Redis/SMTP, worker resource budgets, backups/key escrow and measured performance. Preserve production validation, scanning, queue timing/leases and security isolation. Resize if correct operation cannot fit; do not relax guarantees.

No production-provider restore, PITR, off-site escrow or target capacity certification is claimed. No production tag or VPS deployment is part of this task.
