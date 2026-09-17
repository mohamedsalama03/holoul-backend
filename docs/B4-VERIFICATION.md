# B4 verification evidence

## Scope and repository

B4 Private Documents only. Baseline and eventual commit parent: `a22b21a873ca43da9f9c28741d432ba350cf7fa4`; its parent is `8d7baa66da9f56f2b6cb26b09e0ad4268203fa14`. The baseline working tree was clean on `main`. All 12 approved B1–B3 migration files retain their original hashes. No B5 implementation is included.

The final B4 commit hash and clean working-tree status are reported after the complete gate and commit; a document cannot contain its own commit hash. The exact committed diff is reproducible with `git diff HEAD^ HEAD --stat`.

The batch changes **113 files, with 7,332 insertions and 66 deletions**: the Documents module and Intake attachment coordination, three additive migrations, eight routes, private storage/scanner/parser containers, dedicated durable document transport, bounded cleanup commands, nine PHP document test classes, the Python inspector suite, production smoke checks and four B4 evidence/design documents. Four S3 SDK dependency packages were added; existing locked package versions were retained. Shared changes are limited to required queue/request/header integration, the encryption interface typing correction and a real Redis-outage test correction.

## Added schema and API

- `2026_09_17_030000_create_document_tables.php`: Documents metadata, serialized customer quota, durable orphan records and reconciliation cursors.
- `2026_09_17_030100_create_intake_document_attachments.php`: typed one-slot draft/revision attachments, composite ownership constraints, immutable history, retained-document deletion guards and transaction-safe attachment creation markers. The marker is additive and NULL on old B3 revisions.
- `2026_09_17_030200_seed_document_permissions.php`: explicit document read/download grants for Super Admin, Project Manager, Business Analyst and Reviewer, retaining existing Intake scope.

There are 15 migrations and 76 route definitions after B4, including eight new document routes. Their full method/path contract is listed in [B4 implementation](B4-IMPLEMENTATION.md). No public document route or permanent/presigned file URL is introduced.

## Complete acceptance gate

The complete acceptance run, `bash scripts/verify.sh`, **exited 0 on 2026-09-17 at 10:31 UTC**, ending with `All B1, B2, B3 and B4 verification gates passed.` Its combined output is retained in ignored `artifacts/b4-complete-gate.log`. This included the inherited B1+B2+B3 gate plus all B4 tests, the isolated built-image Python suite, exact release-image production HTTP smoke and current source/image scans. Earlier failed and focused runs are described separately below.

The final rerun's PHP results are **513 Unit/Feature tests, 3,236 assertions**, plus **9 architecture tests, 20,831 assertions**: **522 PHP tests and 24,067 assertions**. The B4 subset is 83 tests and 914 assertions:

| B4 PHP class | Tests | Assertions |
| --- | ---: | ---: |
| DocumentAdaptersTest | 6 | 48 |
| DocumentConcurrencyTest | 3 | 15 |
| DocumentConstraintsTest | 15 | 35 |
| DocumentHttpTest | 24 | 371 |
| DocumentLifecycleTest | 19 | 94 |
| DocumentQueueTransportTest | 2 | 23 |
| DocumentReconciliationTest | 8 | 40 |
| ExpireIntakeUploadsTest | 5 | 42 |
| MigrationUpgradeTest | 1 | 246 |

The built-image Python inspector suite passed **10 tests in 1.831 seconds**. Pint passed **299 files**, PHPStan/Larastan level 10 reported no errors, Composer strict validation/platform checks/install passed, and `composer audit --locked` reported no vulnerability advisories. Fresh migration and two no-op repeats passed, followed by the exact B3 upgrade test above. Route/config cache checks passed.

Docker/Compose validation and development/production builds passed. Trivy **0.74.0** passed the source secret scan with no reported secret findings, and **all eight actual runtime images had zero HIGH/CRITICAL findings**: application, Mailpit, inspector, PostgreSQL, Redis, ClamAV, SeaweedFS and Nginx. No findings or files were added to an ignore list. The vulnerability database was version 2, updated **2026-09-17 07:06:17 UTC**, downloaded **09:37:50 UTC**, with next update **2026-09-18 07:06:17 UTC**. Scanner metadata warnings and their applicability assessment remain documented under residual risks; zero is the requested severity-filtered result, not a claim that every possible vulnerability is excluded.

## Production and fixture evidence

The exact application image tested was `sha256:09ace347b3f6f9da7e04ebbc5a02aa4ca2bc8bc6ef01ff9fa27c8d3737f71170`. The app, default queue, document queue and scheduler all used that image and reported production mode, debug false and no testing dependencies. The scheduler → Redis → worker → PostgreSQL probe passed, along with the inherited B2/B3 HTTPS checks. The ingress burst retained its existing behavior: 100 requests, 57 rate limited. Scanner and inspector both had Docker network mode `none`.

Observed runtime versions were SeaweedFS **4.47** (`c50733600`), ClamAV **1.5.4**, loaded signature database **28126** dated **2026-09-17 06:24:16 UTC**, and QPDF **12.4.1**. Exact upstream pins and resource limits are in [storage evidence](B4-STORAGE-INSPECTION.md).

`runtime-documents-http.json` passed every production document check. `runtime-documents-evidence.json` reported two Available documents (one clean PDF and one clean DOCX), one real `malware_detected` rejection, three succeeded scan operations, two immutable revision attachments and four audited download authorizations. Download bytes matched their fixtures, the required private/no-store, attachment, nosniff and sandbox headers were present, foreign customers and unassigned staff were denied, and amendment preserved the original PDF attachment.

`runtime-documents-cleanup.json` reported one deleted unreferenced document, zero pending unreferenced documents and two retained revision attachments. `runtime-documents-account-cleanup.json` reported four disabled users, zero remaining roles/sessions and zero nonterminal requests; fixture taxonomy was deactivated through the inherited cleanup. Two terminal requests, their three revision snapshots, append-only audit/history and three document metadata records remain deliberately retained. The harmless EICAR object was removed by the real deletion worker.

Across all B4 smoke attempts, the final read-only synthetic-fixture inventory found **13 metadata records: eight Available retained historical documents and five Deleted records**, with eight immutable revision attachments. Earlier successful-history fixtures are included in those eight; history triggers were never disabled to erase verification evidence. No fixture contains real customer content. These local ignored artifacts preserve the exact run outputs without committing credentials or raw document bytes.

## Security and concurrency evidence

The tests exercise generated immutable keys, exact-version SHA-256 verification, idempotent reservation/finalization, unsupported/mismatched/oversized files, sanitized traversal/control-character filenames, and quarantine denial. Real S3 verifies conditional writes, exact-version retrieval/deletion and checksums. Bootstrap verifies versioning, AES256, effective private ACLs and anonymous exact-version read/list denial. The provider's unsupported AWS PublicAccessBlock API is documented with its verified ownership/ACL substitute in [storage evidence](B4-STORAGE-INSPECTION.md).

Real ClamAV processes clean files and EICAR. The built-image parser processes valid PDF/DOCX and rejects compressed-object JavaScript, encryption, attachments/actions, macros, external relationships/entities, traversal, malformed files, archive expansion, renamed executables and polyglots. A crafted valid PDF/ZIP accepted by QPDF alone is explicitly rejected by the complete inspector. Scanner/parser containers have no network or application credentials; all exchanges and processing have size/time/resource limits.

Current authorization is checked before and after upload/download I/O. Tests change the persisted identity or parent version while the storage boundary is active and prove bytes/finalization are denied. A/B customer checks, nested-parent checks, all seven staff roles, permission revocation and draft visibility are covered. Download grants reject changed actor, expired TTL and stale version; safe response headers and exact content are checked. Grant issuance and download start have distinct append-only audit events.

PostgreSQL peer-connection tests prove customer quota serialization, deletion-versus-attachment locking and actual submission-versus-deletion retention. Domain tests exercise duplicate delivery, expired/stale fences, scan-versus-deletion, scanner outage/retry, physical deletion success followed by database/audit failure, explicit retries after exhaustion, abandoned reservations, late PUTs and orphan grace. Real Redis proves the separate document transport and canonical identifier-only failed-job replay. No production audit/history trigger is weakened for application behavior.

The expiry coordinator locks parent then document, releases only aged unfinished draft slots, advances the parent version and records service-attributed audits. Submitted attachments and exact historical object versions remain retained. Persistent cursors prevent old referenced rows from starving cleanup. Cleanup and scan attempts are bounded; exhausted deletion retries require an explicit audited operator command.

## Failures found and corrected before acceptance

Early checks were retained as failures, not counted as passes. They exposed a stale Redis test connection, a final-class mock incompatible with the audit action, an inherited encryption-interface typing mismatch, and two adapter typing issues. Tests now actually recreate the Redis manager for outage checks; audit failure uses a real conditional PostgreSQL failure; recovery encryption uses the same nonserialized wire format through the declared interface.

Storage/inspection checks found the provider's unsupported PublicAccessBlock operation, insufficient initial local storage volumes, SDK XML size values arriving as strings, an overstrict DOCX `.rels` suffix check, and a minimal PDF fixture missing resources. These were corrected and the real-service checks rerun. Review also corrected nested-savepoint history markers, malformed UUID lookup behavior, removed-reservation replay, separate download-start auditing, abandoned referenced-upload cleanup, bounded deletion recovery and reservation Location headers. No failed scanner was replaced with a mock or disabled.

The first complete gate passed the PHP, architecture, parser and inherited production checks, then stopped at B4 synthetic taxonomy setup: the smoke fixture omitted B3's required `display_order`. Both fixture bodies were corrected without changing production validation. That failed run is retained in `artifacts/b4-complete-gate-first-failed.log`; fixture cleanup succeeded with all four accounts disabled and no remaining roles or sessions.

The focused production retry then caught the edge replacing the application's private download cache policy with `no-store` alone. The shared authenticated-response middleware and Nginx now explicitly emit `private, no-store`, retaining the inherited no-cache boundary. Strict production download-header assertions remain enabled; PHP coverage also checks the sandbox and pragma headers. The failed fixture's unreferenced object was deleted and its accounts disabled before retrying.

The next retry passed HTTP behavior but correctly failed the database evidence check: prefixing EICAR with a fake PDF header caused structural rejection rather than the required antivirus detection. The fixture now preserves the exact harmless EICAR bytes inside a DOCX ZIP entry. The evidence gate still requires `malware_detected`, not merely any rejected state; no scanner rule or assertion was relaxed.

A subsequent complete rerun stopped during Compose startup when the successful one-shot storage bootstrap was treated as an exited service. Explicit `service_completed_successfully` dependencies now order the app, document worker and scheduler after bootstrap. This removes the startup race without ignoring its exit code or weakening service health checks; the failed run is retained in `artifacts/b4-complete-gate-bootstrap-failed.log`.

The next complete run passed every functional/production check but stopped at the current vulnerability database's inspector-image findings: pip-vendored MessagePack 1.1.2 (`GHSA-6v7p-g79w-8964`) and setuptools 70.3.0-derived code (`CVE-2025-47273`). Diagnostic scans of the remaining images also found SeaweedFS's gRPC dependency (`CVE-2026-84445`). Those failures remain recorded in `artifacts/b4-complete-gate-inspector-security-failed.log` and `artifacts/b4-remaining-holoul-storage-security.log`. The final complete run passed with the corrected images, without ignores or metadata-only suppression.

The inspector was corrected by uninstalling the entire unused pip distribution and removing the optional ensurepip module/bundled wheel. The affected executable vendored code is absent, not just its inventory metadata. A filesystem probe found no remaining installer/vendor/wheel payload; all ten built-image parser tests and the dedicated vulnerability scan passed. Python and QPDF versions were retained.

SeaweedFS was rebuilt from the same pinned 4.47 source and Go 1.26.8 toolchain with the authenticated upstream gRPC security fix. Its version remains `4.47 c50733600`; the actual compiled module inventory confirms the fixed code. The dedicated rebuilt-image scan passed with zero HIGH/CRITICAL findings for both OS packages and the executable. The exact source/compiler/module hashes and seven supporting dependency upgrades are recorded in [storage evidence](B4-STORAGE-INSPECTION.md). The first cold Go build required substantial source/dependency download and compilation time; later builds reuse the verified caches. No runtime service was replaced until the rebuilt image passed its scan.

## Operational limits and residual risks

The initial format policy is intentionally conservative; complex legitimate PDF/DOCX features can be rejected. A clean verdict does not guarantee safety in every external viewer. Current scan signatures are required; an unavailable or stale scanner leaves documents unavailable. Downloaded files cannot be recalled from a client that already received them.

Local Compose proves the private service integration, TLS verification, encryption behavior and isolation used by this gate. Production region, retention/erasure policy, version-aware backup/restore, backup expiry, certificate/secret rotation, independent audit archive and capacity/RPO/RTO remain the previously documented operational decisions before real customer data. The local self-signed certificate and synthetic fixtures are not production credentials. Historically referenced rejected/quarantined bytes remain unavailable and retained until an approved retention workflow handles them.

The security logs retain Trivy metadata warnings. For PostgreSQL, `CVE-2026-80256` lacked vulnerability details; the HIGH/CRITICAL-filtered zero result does not by itself resolve an unknown-severity record. The [curl advisory](https://curl.se/docs/CVE-2026-80256.html) rates the issue Medium and limits it to Windows wcurl, outside this Linux container's stated platform scope. This is an applicability assessment, not a claim that installed libcurl is patched or that the CVE was rejected. Formal CVE record status could not be independently retrieved. Trivy's generic “CVE may be rejected” text is not such evidence. Its [missing-details handling](https://raw.githubusercontent.com/aquasecurity/trivy/v0.74.0/pkg/vulnerability/vulnerability.go) and the original warnings remain reviewable.

Trivy 0.74's embedded EOL table also omits Alpine 3.24; [Alpine's published support schedule](https://alpinelinux.org/releases/) lists support through 2028-06-01. The logs confirm the actual Alpine 3.24 vulnerability package scan ran. No finding, severity or warning was suppressed.

B5 has not started.
