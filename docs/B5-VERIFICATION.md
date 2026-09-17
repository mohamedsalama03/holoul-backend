# B5 verification evidence

## Scope and repository

B5 Discovery and Proposals only. Approved B4 baseline and intended commit parent:
`5c4e753cdc05736d28cdb8d3b843d8202eb9a175`. Its parent is
`a22b21a873ca43da9f9c28741d432ba350cf7fa4`. The initial tree was clean on `main`.
All 15 B1–B4 migration files retain their approved SHA-256 hashes. Composer files
and locked package versions are unchanged. B6 has not started.

The final B5 commit hash and clean-tree result are reported after the gate and
commit; this file cannot contain its own commit hash. The committed change is
reproducible with `git diff HEAD^ HEAD --stat`.

The complete gate passed before the single B5 commit. No B6 work is included.
The batch changes **76 files, 4,285 insertions and 32 deletions**:
Discovery/Proposals modules, transactional Intake coordination, four migrations,
26 routes, private-document integration, production verification and evidence.

## Schema, routes and implementation

Four additive migrations extend the 15-file baseline to 19 migrations:

- `2026_09_17_040000_extend_intake_for_commercial_workflow.php`: Proposal and
  Approved request states, Discovery information-return origin, and commercial
  history versions/correlation. System expiry alone may omit a human actor.
- `2026_09_17_040100_create_discovery.php`: four Discovery tables, revision
  ownership, requirements, immutable completion and relational sign-off.
- `2026_09_17_040200_create_proposals.php`: ten Proposals tables, exact prices,
  immutable sequence references, approval/decision/history, private document
  references, 72-hour replay receipts and database integrity guards.
- `2026_09_17_040300_seed_commercial_permissions.php`: 12 explicit permissions
  (29 total), initial role grants, and refusal to downgrade commercial history.

There are 26 additional `/api/v1` routes and 102 route definitions in total.
The full method/path contract, state transitions, price rules, permission matrix
and operational commands are in [B5 implementation](B5-IMPLEMENTATION.md).

Intake owns request transitions/history; Discovery owns its revisions and sign-off;
Proposals owns commercial terms, approvals and decisions; Documents owns private
bytes and grants. Application orchestration holds the real outer transaction and
request/proposal locks. Cross-module reads use explicit contracts. Redis and
notifications are outside commercial authority. No new asynchronous commercial
pipeline or expanded Notifications functionality was introduced.

## Complete acceptance gate

The complete run's PHP results are **591 Unit/Feature tests, 4,024 assertions**,
plus **9 architecture tests, 24,374 assertions**: **600 PHP tests and 28,398
assertions**. All passed. The B5 subset adds 78 tests and 785 assertions:

| B5 class | Tests | Assertions |
| --- | ---: | ---: |
| ProposalPricingTest | 19 | 22 |
| CommercialAtomicityTest | 4 | 33 |
| CommercialConcurrencyTest | 8 | 117 |
| CommercialConstraintsTest | 14 | 99 |
| CommercialHttpTest | 18 | 218 |
| CommercialWorkflowTest | 11 | 104 |
| Commercial MigrationUpgradeTest | 1 | 133 |
| ProposalDocumentsTest | 3 | 59 |

The built-image Python document inspector passed **10 tests in 1.644 seconds**.
Pint passed **344 files**, PHPStan/Larastan level 10 reported no errors, and Composer
strict validation, locked installation and platform checks passed. The locked
dependency audit reported no vulnerability advisories. Fresh migration, two no-op
repeats, the exact B4 upgrade, architecture boundaries and route/config cache checks
passed. Docker/Compose validation and development/production builds passed.

The complete `bash scripts/verify.sh` run **exited 0 on 2026-09-17 at 16:07:56
UTC**, ending with `All B1, B2, B3, B4 and B5 verification gates passed.` This
includes every inherited gate, production smoke and source/image security check.
Combined output is retained in ignored `artifacts/b5-complete-gate.log`; class
counts are also in `artifacts/quality.log`. The combined log's SHA-256 is
`1f9c7a3ed0715334197982fc0aa79010c6ebec77c7d3add6502b9123caa889ce`.

Trivy **0.74.0** reported no source secret findings. All eight actual release
images passed the HIGH/CRITICAL vulnerability gate with **zero findings**:
application (OS and Composer packages), Mailpit (OS and executable), inspector,
PostgreSQL, Redis, ClamAV, SeaweedFS (OS and executable), and Nginx. The version-2
vulnerability database was updated **2026-09-17 07:06:17 UTC**, downloaded
**09:37:50 UTC**, with next update **2026-09-18 07:06:17 UTC**. Actual scan times
were 16:07:27–16:07:56 UTC. No ignore list or severity suppression was added.
Metadata limitations are retained below; these are severity-filtered results.

## Commercial correctness and concurrency evidence

The B5 tests exercise the full Draft → In Progress → Completed Discovery lifecycle,
confirmed/excluded requirement guards, exact-version sign-off and preservation of
completed content. New Discovery revisions preserve previous baselines. Proposal
tests cover separate authors/approvers, contributor tracking, invalidation after
material edits, current-revision issuance guards and immutable issued terms.

USD and LYD prices use B3 integer minor units. Whole line quantities, exact products,
aggregate equality, fractional precision, foreign line currencies, malformed money
and multiplication/addition overflow are tested. Independent SQL tests bypass
application validation and exercise foreign ownership, incomplete Discovery,
approval/version inconsistencies, bad totals, unpaired request/proposal states,
immutable children and append-only history. Runtime-role tests retain DDL and
UPDATE/DELETE/TRUNCATE restrictions.

Commercial races use separate PHP processes and separate real PostgreSQL backend
PIDs. A parent transaction holds the request row, and the harness observes both
workers waiting on a PostgreSQL lock before releasing it. It asserts distinct
worker PIDs, both exits and committed state/history:

| Concurrent actions | Required committed outcome |
| --- | --- |
| Accept vs staff withdrawal | One success; stale loser; one terminal event |
| Accept vs supersession | One success; stale loser; original terms retained |
| Accept vs customer request withdrawal | Approved or Withdrawn, with matching proposal and decision |
| Two acceptances with different keys | One decision; stale second command |
| Two acceptances with the same key | Two successful responses; one decision and one receipt |
| Accept vs expiry before deadline | Accepted; expiry has no effect |
| Accept vs expiry after deadline | Expired; acceptance cannot commit |
| Issuance on two distinct requests | Two unique immutable sequence references |

Expiry tests wait on the actual PostgreSQL clock, not a mocked application clock.
Expired offers cannot be accepted with the scheduler stopped. The bounded expiry
command is repeatable and audits its system action. A separate test proves sequence
values consumed in a rolled-back transaction are not reused. An injected real
audit INSERT failure rolls back acceptance, request state/version, history and
receipt together. Expired replay receipts reject retries while retaining the
immutable customer decision.

## Authorization and document regression

HTTP tests exercise authenticated customer ownership, verified email, recent
password confirmation, revoked sessions/permissions, CSRF, exact Origin,
ETag/If-Match, required idempotency keys, changed-key payloads, safe malformed-ID
errors and unknown/forged fields. Foreign parent/child paths and unissued customer
views return no private content. Staff tests use actual MFA enrollment for Super
Admin, PM, BA, Sales, Reviewer, Administrator and Support, with assignment changes.
No role, including Super Admin, may approve its own authored or edited proposal.

Structured proposal terms are canonical. PDF generation/new staff uploads are not
required for this batch; optional attachments reuse an Available historical B4
intake document from the same parent/customer. Proposal document tests cover
quarantined and foreign attachments, pre-issuance customer denial, immutable
issued attachment, explicit staff document permission and a session revoked during
storage I/O. Revocation releases no bytes and creates no download-started audit.
All inherited document, encryption, scanner, parser, queue, quota, isolation,
history, reconciliation and cleanup tests remain in the complete gate.

The exact B4 → B5 migration test first verifies every baseline migration hash and
creates a real B4 schema with only those 15 files. It seeds an intake submission
and retained quarantined document, snapshots every old column/row across 27 tables,
permissions/grants and the request sequence, then applies B5 twice. Old rows and
grants remain unchanged; new constraints, runtime restrictions and inherited
immutable-history/document-retention guards are exercised independently.

## Production evidence

The complete run passed on application image
`sha256:577e19694c60280fd7b90d3b48fb07d3e03d09d7472060be9adfc922bac705c5`.
The app, default worker, document worker and scheduler all used that exact image
and reported production mode, debug false and no testing dependencies. The
scheduler → Redis → worker → PostgreSQL probe passed. Inherited B2/B3/B4 HTTPS
smokes also passed. The ingress test observed 58 rate-limited responses among
100 requests. Scanner and inspector retained network mode `none`.

Observed versions: PHP **8.4.25**, PostgreSQL **18.6**, Redis **8.2.9**, Nginx
**1.30.5**, SeaweedFS **4.47** (`c50733600`), ClamAV **1.5.4** with database
**28126** dated **2026-09-17 06:24:16 UTC**, and QPDF **12.4.1**. B4 source/image
pins and its real security fixes are retained; no dependency was added or upgraded.

The smoke uses real HTTPS session/CSRF cookies, customer email verification, staff
MFA, B3 intake handoff, an uploaded/scanned clean PDF, two completed Discovery
revisions and two proposals. It verifies separate approval, self-approval denial,
edit invalidation, reapproval, issuance, supersession, owner isolation, exact LYD
pricing, private verified PDF bytes, customer acceptance and exact replay.

The PostgreSQL evidence check passed with proposal states `[superseded, accepted]`,
two completed Discovery revisions, one acceptance, two private document references
and one Approved request. Synthetic cleanup then appends rescission/withdrawal,
retains both original acceptance and rescission, disables four synthetic accounts,
revokes sessions/roles and leaves no active proposal or nonterminal fixture request.
Cleanup passed with zero active offers, two retained decisions, four disabled
users, zero remaining roles/sessions and zero nonterminal fixture requests. It
retained one request/revision, two assignment records, eight state changes, one
inherited intake notification intent and 68 audit events. The accepted proposal
becomes Rescinded during synthetic cleanup; the accepted decision remains.
Cleanup never rewrites or deletes commercial, intake or audit history.

Final B4 document evidence records two Available documents, one antivirus malware
rejection, three completed scan operations, two retained revision attachments and
four audited download authorizations. Exact PDF and DOCX bytes and the harmless
EICAR rejection were verified through real services. Result files include
`runtime-proposals-http.json`, `runtime-proposals-evidence.json`,
`runtime-proposals-cleanup.json`, `runtime-proposals-account-cleanup.json` and
`runtime-documents-evidence.json` under ignored `artifacts/`.

## Failed checks and corrections

Development checks found and corrected a polymorphic PostgreSQL trigger field
lookup, a two-step approved-draft edit that conflicted with version guards, and
test-database teardown attempting a forbidden downgrade after commercial history.
The trigger now selects its table-specific field through explicit branches;
material edits invalidate approval and save terms/version together; B5 tests reset
only the dedicated PostgreSQL test database. Production history is never erased
to make rollback pass.

Focused authorization checks exposed the old pre-Discovery assignment restriction;
B5 explicitly extends assignment to Discovery, Proposal and Approved. The initial
attachment test expected creation status for a mutation; it now expects the
actual 200 command response. Optional HTTP notes/descriptions now handle Laravel's
empty-string-to-null normalization and have an end-to-end regression test.

The first inherited Unit/Feature preflight finished with 584 tests, 3,933 assertions
and two failures. Both were old B3 expectations around newly authorized B5 states.
The invalid information-origin case now uses Proposal, which remains prohibited.
The missing-context history cases remain prohibited: database checks require
version/correlation for commercial transitions and preserve a human actor for all
non-expiry transitions. The correction run passed 38 tests / 316 assertions.
The final focused run passed 13 tests / 170 assertions, including simultaneous
numbering, real audit-failure rollback and the 72-hour replay window.

The local production preflight had already applied unpublished B5 migrations.
Their later additive history/replay checks and current terms-guard function were
refreshed in that local database using the migrator identity, without deleting
data. This development-only refresh is recorded in
`artifacts/b5-preflight-schema-refresh.json`. The reproducible fresh-schema and
exact B4 upgrade tests use the final migration files themselves.

## Operational limits and residual risks

Completion requires review of the evidence; no production deployment or B6 work is
included. At least two appropriately authorized staff actors are needed to prepare
and approve terms. Fractional quantities, taxes, payments, FX, e-signatures and
public proposal links are deliberately outside the approved batch. Commercial
decisions have no external notification dependency; expanded delivery is deferred.

Expiry runs once per minute with a bounded batch. A delayed scheduler may leave an
expired offer visible as Issued until reconciliation, but time-checked acceptance
still fails. Clients must refresh a stale request ETag, even when another staff
action changed only a newer unissued draft. Replay is limited to 72 hours; retained
expired receipts conflict and require a new key. Business uniqueness never expires.

The conservative B4 document policy and B0 production region, retention/erasure,
backup/restore, certificate/secret rotation, independent audit archive and capacity
decisions remain applicable. Local Compose and synthetic fixtures are verification
evidence, not a substitute for those deployment decisions. A clean scan does not
guarantee every external viewer is safe. Retained commercial history is not
downgraded or rewritten by migration rollback.

The same inherited Trivy metadata warnings recur: missing vulnerability details
for PostgreSQL's `CVE-2026-80256`, and the scanner's embedded EOL table lacking
Alpine 3.24. The logs confirm Alpine package scanning still ran. The earlier
source/applicability assessment remains in [B4 verification](B4-VERIFICATION.md);
this run does not independently resolve an unknown-severity record. The warning
is not evidence that the CVE was rejected, and the HIGH/CRITICAL zero count is not
proof of no possible vulnerability. Warnings were retained without suppression.

B6 has not started.
