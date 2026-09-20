# B6 verification evidence

## Scope, baseline and repository

B6 Projects and Delivery Lifecycle only. The clean starting HEAD on `main` and
intended single-commit parent is `cbcde0a583fd230ccf11e6817cb56d82f56428a5` (approved
B5). Its parent is `5c4e753cdc05736d28cdb8d3b843d8202eb9a175`. All **19** approved
B1–B5 migration files retain their exact SHA-256 hashes. Composer files, pinned
dependencies and infrastructure security fixes are unchanged. No B7, AI, expanded
Notifications, Reporting, billing, time tracking or generic task system is included.

The batch changes **74 files, 5,571 insertions and 33 deletions**. The tracked report
cannot contain its own commit hash. The final full HEAD, parent, exact diff and
post-commit clean-tree result are supplied in `artifacts/b6-release.json`, the
Windows delivery copy and the final task response. `git show --format=fuller --stat
HEAD` reproduces the committed batch. There is one B6 commit after the required
checks; no history is rewritten to manufacture verification results.

## Schema and routes

Five additive migrations extend 19 migrations to **24**:

- `2026_09_20_050000_extend_intake_for_project_conversion.php`: Converted terminal
  state, exact accepted-decision identity and proposal/request coherence.
- `2026_09_20_050100_create_projects.php`: ten Project/team/evidence/confirmation/
  state/milestone/update/activity tables, reference sequence, ownership, immutable
  baseline and deferred history/conversion integrity constraints.
- `2026_09_20_050200_create_project_documents.php`: two typed pending/retained
  Project attachment tables, exact B4 ownership and bounded expiry cursor.
- `2026_09_20_050300_create_project_command_keys.php`: append-only 72-hour command
  receipts, actor/operation key uniqueness and exact source/Project target guard.
- `2026_09_20_050400_seed_project_permissions.php`: 13 explicit permissions,
  bringing the catalog to **42**, with narrowly scoped initial grants.

There are **13 new tables**, one PostgreSQL Project reference sequence and **37
new routes**, bringing the route catalog to **139** definitions. GET/HEAD counts
as one route. All B6 routes are under `/api/v1`; customer and staff personas remain
separate. The complete contract is in [B6 implementation](B6-IMPLEMENTATION.md).

Intake owns Converted/history writes; Proposals exposes exact accepted-revision
contracts; Projects owns delivery writes; Documents owns storage, inspection,
quota, download grants and durable work. Application coordinates real outer
transactions and locks. No cross-module model access or second storage system was
introduced. Architecture tests still reject unauthorized module implementations.

## Exact test results

The complete inherited PHP suite passed on **2026-09-20**:

| Suite | Tests | Assertions |
|---|---:|---:|
| Unit + Feature, including all B1–B6 regressions | 654 | 5,094 |
| Architecture | 9 | 28,516 |
| **Total PHP** | **663** | **33,610** |

Unit/Feature elapsed time was **19:05.288**; Architecture **00:00.565**. B6 contributes
**63 tests / 1,070 assertions**, included in those totals:

| B6 class | Tests | Assertions |
|---|---:|---:|
| MigrationUpgradeTest | 1 | 219 |
| ProjectConcurrencyTest | 12 | 190 |
| ProjectConstraintsTest | 15 | 104 |
| ProjectDocumentsTest | 9 | 203 |
| ProjectHttpTest | 15 | 236 |
| ProjectLifecycleTest | 6 | 67 |
| ProjectTeamMilestonesTest | 5 | 51 |

The built-image document inspector also passed **10 Python tests in 2.149 seconds**.
Pint passed **391 files**. PHPStan/Larastan **level 10** reported no errors. Composer
strict validation, locked installation, platform requirements and locked audit
passed with no advisory findings. Fresh PostgreSQL migrations, two no-op repeats,
configuration cache/clear, exact route checks and Docker/Compose validation passed.
No host PHP/Composer installation or new dependency was used.

## Conversion, baseline and concurrency evidence

Conversion is one transaction: authorize current staff, lock the request, lock the
accepted proposal, revalidate its owner acceptance/version, create the Project and
initial manager, transition the source through Intake, then append histories,
audit and replay receipt. PostgreSQL enforces one Project per request and bidirectional
commit-time pairing of Project creation with Converted. Both orphan directions are
rejected independently of Laravel validation. UUIDv7 IDs, immutable sequence references,
source/customer consistency and accepted proposal/decision/version links are checked.

HTTP tests verify eligible conversion, unaccepted/rescinded denial, customer denial,
CSRF/origin controls, exact replay, changed-key payload conflicts, stale ETags and
current permission checks. Scope, deliverables, LYD `30.369`, line price `10.123`,
timeline and proposal revision remain unchanged. Converted requests, accepted terms,
decisions and Project identities cannot be rewritten. The acceptance deadline
governs acceptance time; already accepted contracts are not silently expired later.

The concurrency class executes **11 real two-process PostgreSQL races**, plus a
separate stale-lifecycle test. Each race observes two waiting database peers with
different backend PIDs before releasing its lock barrier:

- Double conversion with different keys; same-key conversion with exact replay.
- Conversion against customer proposal rescission and request withdrawal.
- Advance against advance, and advance against hold.
- Competing resumptions restore the saved phase once and invalidate old evidence.
- Concurrent milestone edits and team add/remove commands.
- Concurrent conversion of distinct requests allocates unique references; rolled
  back sequence allocation is not reused.

Exactly one versioned command wins when competing commands share a stale version;
the other gets the expected stale response. Same-key replay creates no duplicate
Project, history, membership or decision. An accepted B5 proposal cannot be withdrawn
as an unaccepted offer; owner rescission is the applicable commercial competitor.

## Lifecycle, authorization and document evidence

All prescribed forward phases, hold/resume, Testing → Development and Deployment →
Testing are exercised. The current assigned PM, explicit permission, phase evidence,
versioned state history and audit are required. Team changes invalidate old plan
approval. Renewed evidence appends history; new deployment evidence invalidates an
older customer confirmation. Completion requires successful current deployment and
the verified owner's recent-authenticated confirmation of that exact evidence.
Super Admin has no bypass. Completed/Cancelled cannot reopen. Audit failure rolls
back the mutation, version and activity together.

Real session/cookie/MFA tests cover all seven staff roles, explicit grants, removed
membership, revoked sessions/permissions and customer A/B denial. Customer lists,
details, milestones, updates and documents are scoped to both customer and owner.
Internal history, reasons, evidence, responsibility and internal/pending documents
are excluded from customer projections. Foreign child IDs and forged ownership
fail closed. Milestone pagination follows explicit display order, including private
cursor rejection. Updates retain author/content/time and are immutable.

Project document tests exercise real B4 reservation, quarantine, scanning, exact
private bytes and current authorization before and after storage I/O. SQL composite
foreign keys reject cross-customer and same-customer/wrong-Project references.
Visibility and retained references are immutable; storage-key/body data does not
enter audit metadata. B4 quota is shared. Pending uploads expire through bounded
durable deletion, including after Project termination without changing terminal
versions. Retained archive downloads remain authorized; new terminal uploads fail.

The exact B5 → B6 upgrade test verifies all 19 baseline migration hashes, builds that
schema, seeds completed Discovery, an accepted proposal/decision and retained PDF
plus a separate quarantined upload, then snapshots every old table/column, grant
and both reference sequences. Applying B6 twice preserves those values. Independent
tests retain B5 proposal/history/document immutability and runtime-role restrictions.

## Production-mode evidence

The final application release image is
`sha256:79eb237b7102cf926ba50f9c297391b3870810c310a650d72fcd024808c5ed35`.
App, default worker, document worker and scheduler use this image in production mode,
with debug disabled and testing dependencies absent. **324 application/configuration/
migration/route/script files** match workspace SHA-256 values. There were **zero PHP
changes after the complete PHP suite**. Scheduler → Redis → worker → PostgreSQL
verification passed. All eight running service image identities match their scanned
release images after refreshing the document inspector/scanner containers.

Observed versions: PHP **8.4.25**, PostgreSQL **18.6**, Redis **8.2.9**, Nginx
**1.30.5**, SeaweedFS **4.47** (`c50733600`), ClamAV **1.5.4**, signature database
**28129** dated **2026-09-20 06:26:26 UTC**, and QPDF **12.4.1**.

Inherited B2/B3/B4/B5 HTTPS smokes passed. B4 evidence records two Available documents,
one harmless EICAR rejection, three completed scans, two retained revision attachments
and four audited download authorizations. Exact PDF/DOCX bytes were checked. B5
records two completed Discovery revisions, Superseded/Accepted proposals, one owner
acceptance and two private document references. The ingress check rate-limited **58
of 100** requests. Scanner and inspector remain network-isolated.

B6's real HTTPS workflow starts with B3 intake and a scanned B4 PDF, completes B5's
separate approval/acceptance, converts exactly once and replays conversion, verifies
A/B/team access, publishes updates, completes two milestones and scans two Project
documents with different visibility. It exercises these 11 transitions:
Design → Development → On Hold → Development → Testing → Development → Testing →
Deployment → Testing → Deployment → Completed. Completion has one explicit owner
confirmation, two active internal members and unchanged commercial terms/PDF bytes.
The source is Converted and the exact proposal remains Accepted.

Synthetic cleanup retains one Completed Project, its acceptance and 12 state-history
rows, with **zero active Projects**. Four fixture accounts are disabled; roles and
sessions are removed; no nonterminal fixture request remains. The commercial cleanup
counter intentionally reports one Accepted baseline because Converted contracts
are immutable; it is not an unconverted live offer. No history is erased for cleanup.

## Security scans and gate execution

Trivy **0.74.0** found no source secrets. All **eight** release images passed the
HIGH/CRITICAL vulnerability gate with **zero findings**: application, Mailpit,
inspector, PostgreSQL, Redis, ClamAV, SeaweedFS and Nginx. The version-2 database was
updated **2026-09-20 19:19:55 UTC**, downloaded **21:40:36 UTC**, with next update
**2026-09-21 19:19:55 UTC**. Actual image scans ran **21:40:37–21:41:05 UTC**.
No ignore list, removed check or additional severity suppression was introduced.

The initial complete `scripts/verify.sh` run passed builds, all PHP/Python/static/
architecture/schema checks and B1/B2 runtime checks, then correctly stopped on an
obsolete B3 smoke assertion that `/projects` must return 404. B6 now authorizes that
route. The replacement assertion verifies an empty owner list and explicit rejection
of Discovery → Project conversion, preserving the original business boundary.

After that verification-only Python correction, both images were rebuilt and the
entire original production/security section was executed again, including all B1–B6
runtime smokes and all security scans. It **exited 0**, ending with
`All B1, B2, B3, B4, B5 and B6 verification gates passed.` PHP did not change, so the
already-passed 663-test suite was not redundantly rerun. This is a completed gate
with a documented correction/resumption, not a claim that the first invocation
exited successfully. Final document checks also exercise the exact scanned scanner
and inspector images after their local container refresh.

Main evidence is under ignored `artifacts/`: `quality.log`,
`document-inspector-tests.log`, `b6-full-gate.log`, `b6-production-gate.log`,
`b6-test-summary.json`, `b6-runtime-source-match-final.json`,
`b6-release-images.json`, `b6-running-image-content.json`,
`b6-final-document-checks.log`, and `runtime-projects-{http,evidence,cleanup}.json`.
Logs retain failures and warnings. Combined-log SHA-256 values:

- Initial gate: `4eb232443d75232adebd99295419379b2ceea5420de0b7284f6bfbf9f6c142ac`.
- Corrected production/security section: `2103bf66f9acdb947e6ac71b13a13ad2b83dbd98ef94f7a7b78d93cc9dea9508`.

## Corrections and residual limits

Development checks corrected a PostgreSQL CASE syntax issue and a test harness that
needed to catch deferred constraint failures at COMMIT. Review tightened two-way
conversion pairing, exact baseline revalidation, nested command receipt allowlisting,
empty optional descriptions, historical replay after candidate disable, current
evidence renewal and milestone display-order pagination. The new raw-upload path
and edge cache rule were explicitly extended for Project documents. All corresponding
tests passed; no failures were suppressed.

Inherited Trivy limitations remain: missing detail for PostgreSQL's
`CVE-2026-80256`, an embedded EOL table lacking Alpine 3.24, and vendor-severity
selection notices. Package scanning still ran. The warning does not establish that
the CVE was rejected or resolved, and HIGH/CRITICAL zero is not proof of no possible
vulnerability. These warnings remain visible without suppressions.

This is local production-mode verification, not deployment to a production service.
B0/B4 region, retention/erasure, backup/restore, independent audit archive, secret/TLS
rotation and capacity decisions remain deployment prerequisites. Retained rejected
attachments can remain quota-bearing history under the existing retention policy.
Replay is bounded to 72 hours, while business uniqueness and historical immutability
remain permanent. Completion exceptions, delegated customer sign-off, change requests,
notifications and post-completion support require separate approval. **B7 is not started.**
