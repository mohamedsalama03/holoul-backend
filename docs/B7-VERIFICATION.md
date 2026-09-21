# B7 verification evidence

## Scope and repository

B7 AI Assistance and Notifications only. The approved B6 baseline and intended
single-commit parent is `bf738302657f8da1bca911058186a24e87b7ea37`; its parent is
`cbcde0a583fd230ccf11e6817cb56d82f56428a5`. The initial `main` working tree was clean.
B8 Reporting, Analytics and production-launch work are not included.

The batch changes **131 files, 6,963 insertions and 77 deletions**. Application,
schema, tests, runtime verification and documentation are included in one B7 commit.

The final full HEAD, parent, diff summary and post-commit clean-tree check are
recorded in `artifacts/b7-release.json`, the Windows delivery copy and the final
task response. A tracked report cannot embed the hash of the commit containing
itself. `git show --format=fuller --stat HEAD` reproduces the committed batch.

## Schema, permissions and API

Three additive migrations extend 24 migrations to 27. All 24 approved migration
files retain their exact SHA-256 hashes:

- `2026_09_21_000001_create_ai_assistance.php`: five AI source/run, suggestion,
  provider-attempt, command-receipt and daily-budget tables.
- `2026_09_21_000002_create_notifications.php`: seven notification, inbox,
  preference, delivery, attempt, replay and command-receipt tables.
- `2026_09_21_000003_seed_assistance_permissions.php`: eight permissions, extending
  the catalog from 42 to 50 without granting bypasses of existing owner permissions.

There are 16 added route definitions under `/api/v1`, extending 139 to 155.
GET/HEAD is one definition. Session/origin/CSRF controls, request IDs, no-store safe
responses, UUIDv7, pagination, rate limits, idempotency and ETag preconditions remain
in place. The exact contracts are in [B7 implementation](B7-IMPLEMENTATION.md).

## AI architecture, privacy and human authority

Application source/authorization services compose the AI service, provider contract
and configured adapter. Expensive work uses PostgreSQL durable intent with a
dedicated Redis identifier-only transport and fenced worker. The AI module owns
AI writes; Project Intake and Discovery own explicitly applied business changes.
No provider SDK or unrelated dependency was added.

The sole configured provider is the deterministic local `sandbox` / `sandbox-v1`.
It makes no external AI request and settles at zero actual cost. AI defaults to
disabled. This verifies provider contracts and failure semantics; it does not claim
commercial language-model quality or actual paid-provider service behavior.
B0 ADR-07 supplier, privacy and spending approval remains outstanding.

Runs bind the requester, customer, parent and exact immutable source version/hash,
document checksum/object version where applicable, consent, prompt/schema versions,
provider/model and safe usage metadata. Suggestions are separate. Generation does
not write original descriptions, submitted revisions, taxonomy, requirements,
proposals or Project baselines. Explicit apply uses the owning module in the same
audited transaction as the suggestion decision and receipt. Existing confirmed
Discovery requirements are retained; newly accepted suggestions are proposed.

The worker rechecks current permission and source before extraction, after private
I/O, before provider dispatch and before committing output. Application rejects
stale results after a parent/source change. Receipt replay also requires current
authority. Full document-derived history output requires current document permission
and attachment readability, including creation replay, without requiring unchanged
source versions. Customers cannot read or mutate another customer's run or notification;
staff AI access retains existing assignment, team and document visibility rules.

Only Available B4 bytes with the exact checksum/object version enter constrained
offline extraction. QPDF plus pinned Poppler handles PDF; DOCX extracts bounded
main-body text and excludes metadata/comments/headers/footers/deleted/moved text
and text boxes. Explicit hidden Word runs, empty/unsupported input and output overflow fail
closed. Existing file/ZIP/time/process/memory limits and network isolation remain.
There is no OCR, provider fetch URL, credential or unrestricted tool access.

The adapter receives only selected bounded content and public taxonomy choices.
Pattern redaction removes recognizable contact and credential strings. Arbitrary
free text can still contain sensitive prose, so redaction is not presented as a
complete privacy guarantee. Strict schemas reject unknown keys, excessive sizes,
invalid taxonomy pairs and malformed output. Prompt injection fixtures have no
business authority. Prompts, document text and full outputs are excluded from
operational logs, audit metadata and queue payloads.

## Cost, concurrency and recovery

PostgreSQL owns admission, daily budget reservations/settlement, per-user limits
and global/user concurrency. Independent database processes exercise budget,
concurrency and same-key admission races. Exhaustion retains an audited unavailable
run without paid work. Redis loss cannot reset usage or spending limits.

Provider attempts are journaled before dispatch. Known safe failures use bounded
backoff, jitter and Retry-After; timeout, 429 and 5xx fixtures cover their outcome
classification. Unknown outcomes do not automatically resend or release uncertain
cost. Duplicate delivery, expired fences, cancellation during dispatch and worker
death retain conservative reservation/attempt history. The reconciler closes
abandoned work without making a blind second paid call.

The AI pool uses a 120-second worker timeout, 135-second shutdown grace,
150-second PostgreSQL lease and 180-second Redis retry interval. The provider
contract carries 5-second connect and 60-second overall bounds. A future network
adapter must enforce these bounds and accurately classify charge uncertainty.

## Notification durability and recipient controls

Explicit owner events record notification, email-delivery and durable work intent
inside the business transaction. Commit/rollback and duplicate-event tests cover
that boundary. Email provider outage occurs after commit and cannot roll back a
request, proposal, Project update or AI result.

Fixed generic templates cover request submission/information requests, proposal
issue/acceptance/decline, Project creation/public updates/state/customer-visible
milestones and useful AI success/failure. Private document text, AI output and
internal milestone content are omitted. Historical B3 passive intents are retained
without unsolicited backfill.

Recipient-scoped pagination, unread counts, read/read-all and preference commands
use durable versions/receipts. All personas may change `workflow_email`; B2
verification and password recovery remain independent and cannot be disabled by
that preference. A real PostgreSQL two-process race checks notification deduplication.

SMTP pre-send rejection can retry. Lost acknowledgement after sending and worker
death after possible acceptance become uncertain. No exactly-once email claim is
made. Operator replay requires explicit permission, recent password confirmation,
ETag, idempotency, reason and an append-only audit record. An uncertain replay
requires the operator's explicit `confirmed_not_accepted` decision. Replay preserves
the logical notification and does not repeat its originating business action.

## Exact quality results

The final inherited B1–B7 PHP suite passed on 2026-09-21:

| Suite | Tests | Assertions |
|---|---:|---:|
| Unit + Feature | 746 | 7,081 |
| Architecture | 9 | 34,876 |
| **Total PHP** | **755** | **41,957** |

Unit/Feature elapsed time was **24:03.663**, peak memory **121 MiB**. Architecture
took **0.800 seconds**. B7 adds 92 PHP cases to the approved 663-case baseline.
The built-image inspector passed **17 Python tests in 2.999 seconds**. Pint passed
**478 files**, PHPStan/Larastan **level 10** reported no errors, and Composer strict
validation, locked installation, platform requirements and audit passed with no
advisory findings. Fresh PostgreSQL migrations, two no-op repeats, configuration
cache/clear, exact routes and Docker/Compose validation passed.

| B7-focused class | Tests | Assertions |
|---|---:|---:|
| AIOutputSchemaTest | 1 | 3 |
| AIConcurrencyTest | 3 | 30 |
| AIDomainTest | 20 | 180 |
| AIApiTest | 11 | 236 |
| AIOwnerBoundaryTest | 8 | 118 |
| BusinessNotificationsTest | 3 | 48 |
| Assistance MigrationUpgradeTest | 1 | 683 |
| DocumentTextExtractionTest | 16 | 83 |
| NotificationConcurrencyTest | 1 | 13 |
| NotificationDeliveryTest | 13 | 99 |
| NotificationHttpTest | 5 | 96 |
| NotificationIdentityLockTest | 8 | 146 |

Two further B7 extraction cases extend the existing DocumentAdaptersTest; all eight
cases in that class pass. The table is a subset of the full suite, not additional
tests. Exact B4→current, B5→current and B6→B7 upgrades pass **3 tests / 1,035
assertions**. The B6 fixture snapshots old rows/columns, permissions/grants,
constraints/triggers and sequences, applies B7 twice and verifies preservation,
including retained documents, accepted commercial baseline and delivery history.

## Production-mode and inherited regression evidence

The final application image is
`sha256:7b52782381f51be2d4a86c46e4000df6a45c4c0ed686733cecaf841947e4635f`.
App, default worker, document worker, AI worker and scheduler use this exact image
in production mode, debug disabled, without testing dependencies. The release
collector compares 407 application/configuration/migration/route/script and
supporting code files with the workspace and committed source, plus effective
entrypoint/PHP/Nginx/inspector files. No PHP changed after the completed full suite.

Scheduler → Redis → worker → PostgreSQL passed with real durable work. All inherited
B2–B6 HTTPS acceptance smokes passed, including real sessions/email verification,
password recovery, MFA, A/B isolation, immutable intake submissions, exact commercial
prices, separate proposal approval/acceptance and exactly one Project conversion.
Project delivery exercised hold/resume, testing failure, deployment rollback and
completion with the owner's exact confirmation; the accepted baseline stayed intact.

B4 runtime evidence retains two Available documents, one harmless EICAR rejection,
three completed scans, two revision attachments and four audited downloads. B6
also verifies two Available Project documents with different visibility. The ingress
probe rate-limited 58 of 100 requests. Scanner and inspector remain network-isolated.

B7's real HTTPS/queue/private-parser/SMTP smoke passed:

- Four queued local-provider runs and four provider attempts; four reservations
  settled at zero actual cost. Two description runs and exact Available PDF/DOCX
  analyses used two owned intake drafts. No paid-provider call occurred.
- Generation preserved original draft text. One explicit human application and its
  exact replay changed the draft once; a later human edit caused stale application
  rejection. Consent, preconditions, queue deduplication and customer A/B isolation
  passed. Private storage URLs and source-version internals were absent from output.
- Four in-app notifications, one SMTP acceptance and three email suppressions after
  opting out. In-app delivery remained enabled; individual read/replay and read-all
  ended with zero unread records. Email bodies contained no AI/document content.
- Synthetic cleanup disabled four accounts, removed roles/sessions and left no
  nonterminal fixture request. Immutable history and the two fixture requests remain.

This production smoke covers description and intake-document analysis. Other AI
purposes, Project document permissions, budget/failure/cancellation cases and
operator replay are covered by the PostgreSQL tests above. B2 security-email
protection combines the preference/intent regression with the inherited real B2
delivery smoke; it is not described as a separate SMTP send after preference opt-out.

After verification, the same release image was restarted with AI disabled on all
five application services. Production/debug settings and readiness passed again;
`artifacts/b7-disabled-runtime-evidence.json` records that final local state.

Observed versions: PHP 8.4.25, PostgreSQL 18.6, Redis 8.2.9, Nginx 1.30.5,
SeaweedFS 4.47 (`c50733600`), ClamAV 1.5.4 with signatures 28129 dated
2026-09-20 06:26:26 UTC, QPDF 12.4.1 and Poppler `pdftotext` 25.12.0.

## Security and completed gate

The final `scripts/verify.sh` invocation exited **0**, ending with
`All B1, B2, B3, B4, B5, B6 and B7 verification gates passed.`
The full log is `artifacts/b7-full-gate.log`; quality, runtime, cleanup and security
artifacts are hashed in `artifacts/b7-release.json`. Earlier interrupted runs and
preflight failures remain separate evidence and are not counted as completed gates.

Trivy 0.74.0 found no source secrets. All eight release images passed the
HIGH/CRITICAL gate with zero reported findings: application, Mailpit, inspector,
PostgreSQL, Redis, ClamAV, SeaweedFS and Nginx. Scans ran on 2026-09-21 at
06:48:20–06:48:47 UTC. Database version 2 was updated 2026-09-20 19:19:55 UTC,
downloaded 21:40:36 UTC and next scheduled to update 2026-09-21 19:19:55 UTC.
No ignore list or severity suppression was added.

Scanner warnings remain visible: Alpine 3.24 is absent from its EOL table, some
severity values come from other vendors, and PostgreSQL scanning reports missing
details for `CVE-2026-80256` (possibly rejected). Zero reported HIGH/CRITICAL findings
does not erase those database limitations. Image references resolve to the same
IDs before and after the scans; running services match those IDs. The scanner logs
contain references rather than independently signed digest receipts.

## Corrections and residual limits

Preflight caught and corrected a PostgreSQL generated-column comparison in an
AI update trigger, eager provider/storage resolution during build, stale test
statistics in real-process race fixtures, JSONB key-order assumptions and a
deferred-constraint exception helper. Cancelled/expired worker attempts now close
their original journal fence conservatively. These failures are retained in local
preflight logs rather than represented as successful first attempts.

Final review caught a historical-output authorization gap after document permission
revocation. Full-result responses now recheck the owning document permission.
Customer Project, staff Project and staff intake HTTP regressions prove revocation
denial while retaining parent access; unrelated source changes still permit
authorized history. The focused AI HTTP/owner suite passed 18 tests / 281 assertions.
The first full gate was deliberately interrupted during PHPUnit to incorporate
this correction and the notification identity-lock regression; it is not counted
as a completed gate.

Notification publication also exposed a recipient foreign-key lock cycle against
concurrent authorized resource reads. HTTP and durable identity reads now use
PostgreSQL `FOR NO KEY UPDATE`, which permits recipient key-share checks while
still serializing account/role revocation. Exclusive session locks remain.
Eight real-process regressions passed 146 assertions: notification publication
against both identity readers, and disable/role/session-revocation serialization
against each. The full inherited identity suite remains part of the final gate.

A second review corrected the AI HTTP limiter's shared read/create counter. It
now checks a 120/minute aggregate bucket and an independent 20/minute creation
bucket atomically. Normal result polling no longer consumes creation allowance.
The second complete-gate attempt was interrupted during PHPUnit to include this
focused correction; only the final completed gate is reported as passing.
The scoped HTTP suite passed 11 tests / 236 assertions, including 21 reads before
successful creation, the 20-create limit and Retry-After, continued reads after
creation throttling, and a single run/reservation/operation under exact replay.

Exact historical upgrade fixtures suppress only newly introduced B7 owner events
while constructing their pre-B7 schemas. Production code has no table-existence
bypass. Eight inherited test classes now use the existing dedicated PostgreSQL
fresh-database fixture instead of destructive downgrade of new append-only history;
their business/security assertions remain. No architecture gate was removed.

External AI terms, region, retention/training/deletion behavior, approved models and
unit costs must be settled before customer-data enablement. Uncertain spending
stays reserved and may exhaust capacity until a separately approved reconciliation
process exists. Extraction does not completely resolve inherited Word style
invisibility or hidden PDF text; all extracted text remains untrusted. SMTP smoke
proves local sandbox acceptance, not real inbox delivery.
An incorrect operator uncertainty assessment can still duplicate external email.
Database administrators can alter database-only history. Production retention,
independent archive, recovery/launch readiness and B8 remain outside this batch.
