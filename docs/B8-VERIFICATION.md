# B8 Operations & Production Readiness — verification

**NOT PRODUCTION READY — BLOCKERS REMAIN**

**B8-P1 follow-up, 2026-09-21:** performance remediation did not pass. After a
complete strict warm-up, the two unchanged 480-request synchronized repeats
measured p95 **662.590 / 699.075 ms** and p99 **909.720 / 929.308 ms**, with
zero unexpected HTTP errors. Static4/static8 candidates were rejected and
the original dynamic pool restored. No application optimization, new full-gate
pass, B8 commit or production approval is claimed. Final restoration passed;
AI is disabled on all six PHP services. The complete measurements, endpoint
tables and limitations are in [B8-P1 verification](B8-P1-PERFORMANCE.md).
The full-gate results below remain the preceding B8 evidence.

The local verification round completed on **2026-09-21 at 12:39:12 UTC**.
The full gate exited **1** because authenticated burst latency failed its
unchanged acceptance target. Independent quality, workflow, recovery and
security checks completed successfully. **B8 acceptance remains blocked; no
B8 commit or production approval was created.** This report records completed
assessment work, not a passed release gate.

## Scope and release

B8 implements staff reporting, restricted audit investigation, production
telemetry/configuration validation, measured query/queue/HTTP performance,
synthetic encrypted recovery, and deployment/incident runbooks. No frontend or
new business domain is included. B0–B7 ownership and security boundaries remain.

Approved baseline: `96445baded70ddd2e4d8b8617793c78a4a3e1816`, parent
`bf738302657f8da1bca911058186a24e87b7ea37`. Final observed HEAD and parent are
unchanged from those values, on `main`: **zero commits since the baseline**.
The working tree is intentionally **not clean**: 35 tracked status entries and
67 new files including the B8-P1 report, none staged. The tracked textual diff contains 382 insertions
and 56 deletions; new files are counted separately. Documentation finalization
does not change the tested application image. No clean-tree or committed-release
certification is claimed.

The change adds five staff GET routes (155 to **160** total) and one additive
permission migration (27 to **28**, permissions 50 to **52**):
`2026_09_21_100000_add_reporting_permissions.php`. Permissions are
`reporting.read` and `audit.investigate`. The four report routes cover dashboard,
requests, projects and customers; the fifth exposes restricted audit timelines.
The remaining diff provides owner-scoped bulk document metadata reads,
production configuration/telemetry, independent notification transport,
reproducible performance/recovery verification, and operational documentation.
No new business tables, speculative indexes or business-result cache were added.

Final application image:
`sha256:ba964a709c7faae3e0b341b16e79d006c8bc7b38dbf5a12128e14aa3d120a2a4`.
Final document inspector image:
`sha256:004294317ce43fbb1726b72541c4c5c394fdc144e782c2d9e8aace828a452819`.
All six PHP services use the same application image. Read-only source manifests
include new B8 code and match the workspace bytes in all six containers;
performance and restore evidence identify these final images. Before/after
inventories bind all eight scanned references to unchanged image IDs, with
mapping SHA-256 `d2c3d3e9bbaa330b02680495c9cbd8ca501f18a30db9c99706b13401c520e027`.

The supplied launch envelope is 150 customers, 100 projects and 20 concurrent
users on an Ubuntu VPS with 2 CPUs, 8 GB RAM and 1 TB disk. Local measurements
share two logical CPUs across all principal application/dependency containers;
they do not certify the target processor, network, storage IOPS or availability.

## Evidence status

| Gate | Status |
| --- | --- |
| Reporting/audit authorization, coherent snapshots and bounded filters | PASS in complete regression: 23 reporting HTTP tests / 345 assertions and 2 audit cursor tests / 38 assertions |
| Document list N+1 correction | Authenticated SELECT count 16/18/40 → 16/16/16 at 1/3/25 attachments |
| Exact B7 → B8 upgrade, immutable business/AI/notification history | PASS in complete regression: exact upgrade 1 test / 789 assertions; seven sequential upgrade tests / 2,244 assertions |
| Production configuration and safe telemetry | PASS on final source and image under the explicit local-verification profile; all six services verified private compiled configuration/routes and 160 routes |
| Complete PHPUnit/static/migration quality stage | PASS on final source: 835 Unit/Feature tests / 8,653 assertions; 9 architecture tests / 38,799 assertions; 17 inspector tests; Pint 525 files, PHPStan level 10 and Composer checks passed |
| Production workflow/container/security stages | PASS: final-image B1–B7 workflow smokes, runtime/cache probes, source/secret/eight-image scans, all six AI-disabled recreation probes and final readiness |
| Final-image authenticated HTTP load and state-safe races | BLOCKED: 480 requests; p50 290.203 ms / p95 576.348 ms / p99 758.399 ms; 20 observed in flight, zero unexpected HTTP errors, valid arrival schedule and passing state-safe races |
| Independent default/documents/AI/notifications queues | PASS on final image: one worker per queue, heavy/light isolation and live-drain proof, real document/sandbox outcomes and SMTP outage/backoff/recovery; 33 heavy jobs drained in 6.704 seconds |
| Actual encrypted PostgreSQL/private-object recovery | PASS on final images: 74 tables / 658 rows / 3 exact object versions, keys and lineage verified; restore plus verification 56.182 seconds |
| Final source/image identity | PASS for the uncommitted workspace and running images; before/after scan identity mapping unchanged |
| Clean one-commit B8 batch | BLOCKED by failed performance acceptance; no B8 commit, working tree remains dirty |
| Real production launch | BLOCKED; the local profile and synthetic drill do not commission or certify the supplied VPS |

## Failed attempt and corrective evidence

The first full Unit/Feature attempt completed 835 tests and 8,602 assertions
with five errors and one failure. Four HTTP concurrency subprocess results
contained new telemetry before their JSON result; the document-query evidence
writer assumed an artifacts directory existed inside its disposable container;
and the Redis outage fixture changed configuration after HTTP telemetry had
already initialized the Redis manager. The cached healthy configuration meant
that test did not actually connect to its intended closed port.

Corrections preserve production telemetry, isolate subprocess result JSON from
redacted stderr logs, create the evidence directory, and rebuild the test's
Redis configuration snapshot. The original concurrency, HTTP 503 and
no-side-effect assertions remain. The three affected classes passed **43 tests,
509 assertions** in `artifacts/b8-fixture-regression.log`. The complete rerun
subsequently passed **835 tests / 8,654 assertions in 26:00.549**. Architecture
checks passed **9 tests / 38,799 assertions**, for **844 PHP tests / 47,453
assertions** overall; the inspector passed **17 Python tests**. Failed logs are
retained as `artifacts/b8-full-unit-first-failure.log` and
`artifacts/b8-full-gate-first-failure.log`.

The final-source repeat passed **835 Unit/Feature tests / 8,653 assertions in
25:54.655**, plus **9 architecture tests / 38,799 assertions** and **17 Python
tests in 2.939 seconds**: **844 PHP tests / 47,452 assertions**. Both preserved
JUnit reports contain zero errors, failures and skipped tests. All 79
Unit/Feature class counts match the earlier passing run except the unchanged
`IntakeConcurrencyTest`: its four tests made 102 rather than 103 assertions.
Its accepted race-winner branches execute different assertion counts; no
assertion or test was removed. The final document-list SELECT measurements
remain **16/16/16** for 1/3/25 attachments.

The final gate now preserves both JUnit reports and document-query measurements
in `artifacts/b8-quality/` outside the disposable verification container. It
also runs a source dependency vulnerability scan including development
dependencies, in addition to secret scanning and all eight runtime image scans.

## Performance acceptance remains open

The strict initial workload sends 20 simultaneous authenticated HTTPS requests
every five seconds, for 480 requests. On the shared two-CPU local environment,
the first complete-gate run measured p50 308.838 ms, p95 559.918 ms and p99
682.572 ms, with zero unexpected HTTP errors. A separate eight-warm-worker
candidate measured p50 354.310 ms, p95 609.631 ms and p99 713.547 ms, also with
zero errors. Both failed the provisional p95 target; race correctness does not
convert either latency result into a pass. The warm-worker change was rejected
and the original dynamic pool configuration restored in source.

The failed evidence is preserved independently in
`artifacts/b8-performance-latency-failed/` and
`artifacts/b8-warm-pool-latency-failed/`. A subsequent candidate compiles Laravel
configuration and routes into private container tmpfs at startup; all six
production-mode runtime probes verified private cache permissions and exactly
160 routes. Its synchronized authenticated workload still failed: p50 308.726
ms, p95 631.887 ms and p99 923.249 ms across 480 requests with zero unexpected
errors. Evidence is retained in `artifacts/b8-cached-burst-latency-failed/`.
Configuration/route compilation improved a separate health-bootstrap probe;
it did not resolve authenticated burst latency. No authorization, lock,
durability or latency threshold was weakened.

A separately labeled staggered diagnostic passed the same latency/error/race
checks: 480 requests in 119.840 seconds, p50 47.385 ms, p95 86.406 ms, p99
107.434 ms and zero unexpected errors. Twenty authenticated users each made
one request every five seconds with fixed 250-ms phase offsets. Actual peak
overlap was **one** request, with zero missed arrival slots. This is evidence
for that pacing model, not for 20 simultaneous requests or maximum capacity.
Its retained dataset contained 1,118 customers, 704 projects and 7,053 requests,
including earlier immutable synthetic history. The complete artifacts are in
`artifacts/b8-staggered-diagnostic-passed/`.

The subsequent B8-P1 instruction explicitly fixes acceptance at 24 waves of
20 simultaneous authenticated HTTPS requests, five seconds apart. This is no
longer an unresolved harness assumption. The staggered result cannot replace
that gate or authorize a B8 commit. See [B8-P1 evidence](B8-P1-PERFORMANCE.md).
The preceding full final-source gate completed against image
`sha256:ba964a709c7faae3e0b341b16e79d006c8bc7b38dbf5a12128e14aa3d120a2a4`.
It retained the failed performance result while completing independent restore,
security-scan and AI-disabled checks. Its actual final exit was **1**; the
performance-blocked marker is present and its all-gates-passed marker is absent.

The final strict burst has now repeated the failure on that image: **480
requests, p50 290.203 ms / p95 576.348 ms / p99 758.399 ms, zero unexpected
errors**, with 20 observed requests in flight and all state-safe race probes
passing. Schedule validity and cleanup/restoration also passed. The p95 target
remains unmet; final evidence is retained in
`artifacts/b8-final-burst-latency-failed/`. No B8 commit is authorized by this
result. Independent exact-image recovery and security verification have passed.

## Query, queue and resource evidence

The final synthetic dataset contained 1,278 customers, 805 projects and 8,061
requests. Retained immutable history explains why this exceeds the latest
150-customer/100-project fixture. Audit rows increased from 41,093 at manifest
capture to 41,713 at profiling. Actual load was 480 requests over 115.502 seconds,
4.156 requests/second, in 24 synchronized waves of 20 every five seconds.
Reads, notification polling, staff reports/audit, project reads and 16 request
submissions ran over authenticated HTTPS; separate replay/conflict races passed.

Real PostgreSQL plans covered customer/admin lists, reference/search, proposal,
project/document, notification, dashboard/report and audit reads. The index
inventory contained 219 indexes and zero exact duplicates. Bounded aggregate
and nonselective scans did not justify a speculative index. Document batching
removed measured growth: authenticated SELECTs stayed 16/16/16 at 1/3/25
attachments, and CLI owner calls stayed eight queries at requested limits
1/25/100 (only 25 attachments existed). Full scope and plan limitations are in
[performance](B8-PERFORMANCE.md).

With one worker per queue, 25 real 500-page PDFs and eight sandbox AI jobs
drained in 6.704 seconds after the deliberate heavy-worker pause. Fresh default
and notification work completed during document progress in 0.128346 and
0.094845 seconds on first attempt. Real local SMTP outage/backoff recovery took
36.362 seconds. PostgreSQL remained authoritative for job status/age; Redis
depth was recorded separately. This short exercise supports the tested initial
pool layout, not sustained saturation or external-provider capacity.

Sparse surrounding samples observed at most seven PostgreSQL connections, two
active connections and no waiting locks; Redis ping p50/p95/p99 was 2/3/3 ms.
Maximum sampled combined container memory was approximately 1.900 GB, including
approximately 1.018 GB for the scanner. App/PostgreSQL sampled CPU maxima were
50.85%/51.63%. These are sampled observations rather than continuous peaks;
target-VPS capacity and storage/network performance remain unverified.

## Actual encrypted restore

`artifacts/runtime-restore.json` records a successful final-image synthetic
restore: **74 tables, 658 rows, four sequences, 1,219 constraints and 137
triggers**. All rows, sequence values and runtime grants matched. The restored
Customer → Request → Revision → Document → Proposal → Acceptance → Project →
History/Audit lineage includes 123 audit events and 38 activity entries. Three
Available private documents retained their original object versions and exact
checksums (1,833 bytes total); two MFA credentials decrypted after recovery of
application/storage keys.

Ten authenticated encrypted archives totalled 700,752 bytes. Tampered,
truncated, trailing, wrong-header/context/key ciphertext was rejected. Backup
took **48.402 seconds**; restore and verification **56.182 seconds**; the whole
disposable exercise **380.967 seconds**. Transaction-ID safety was preserved
without resetting WAL. Disposable resources were removed. Manifest SHA-256:
`1669c6c52077e20986ee3a15094e6afb81bef09903cf1b9a21c85e1df3af43a7`.

Production-provider recovery, point-in-time recovery and offsite key escrow are
explicitly **not certified**. A small local drill is not a production RTO proof;
the suggested RPO/RTO still require approval and a production-sized rehearsal.

## Observability and security result

Implemented observations cover HTTP errors/latency, bounded query metrics,
PostgreSQL connections/locks, Redis health, durable per-queue backlog/age,
worker/scheduler heartbeats, document backlog, storage/provider timings, AI
attempts/cost and notification outcomes. Labels exclude resource IDs and PII;
logs omit bodies, credentials, SQL bindings and private AI content. Redis
metrics expire after 15 minutes and never determine business outcomes.
Production backup/restore health correctly remains `unknown`; synthetic-drill
success does not populate production monitoring as healthy.

The inherited and B8 security suites passed authorization/BOLA, privilege,
CSRF/session, injection/mass-assignment, malicious-document, provider/SSRF,
AI-abuse, immutable-audit, concurrency and rate-limit assertions. Production
workflow smokes exercised staff MFA, customer isolation, private document
scanning, proposal acceptance, full delivery lifecycle and sandbox assistance.
There were **zero paid provider calls**. All six final services verified
`APP_ENV=production`, debug off, AI disabled, no external provider configured,
private compiled caches and 160 routes; final readiness was ready.

Composer audit and source secret scanning passed. Trivy 0.74.0 scanned source
dependencies including development packages and eight runtime images with
**zero HIGH/CRITICAL findings in that selected severity scope**. No suppression
or ignore-unfixed exception was used. Seven image logs warn that Alpine 3.24
is absent from the scanner's EOL metadata; this is not proof of an unsupported
release. The PostgreSQL scan also retains cross-vendor severity guidance and a
missing-detail warning for CVE-2026-80256. These data limitations remain visible;
the result is not a claim of zero vulnerabilities at every severity or complete
security assurance. See [security review](B8-SECURITY-REVIEW.md).

`artifacts/b8-blocked-evidence.json` records the observed nonzero gate exit,
fresh checks/JUnit, dirty Git state, unchanged HEAD, source/image identities and
scan bindings. It reports `ready=false`, `gate_failed=true`,
`commit_created=false`, and no observation inconsistencies. This deliberately
blocked evidence is separate from a release certificate; the strict release
collector was not used to manufacture a passing result.

## Every unresolved launch blocker

1. **Performance and release acceptance:** synchronized 20-request bursts fail
   p95 <300 ms. B8-P1 explicitly fixes the original 480-request synchronized
   workload and forbids weakening or replacing its p95/p99 thresholds. Meet
   that unchanged gate repeatedly, then rerun the complete B1-B8 gate.
   The staggered diagnostic cannot satisfy acceptance. The one B8
   commit/clean release is consequently withheld.
2. **VPS commissioning and capacity:** verify the actual supported/patched
   Ubuntu host, processor, memory headroom, disk IOPS/space growth, network,
   longer workload and single-host failure/recovery arrangement.
3. **Database:** deploy private PostgreSQL with distinct runtime/migration
   identities, verified TLS, connection budget and verified production grants,
   constraints and recovery configuration.
4. **Redis:** prove private authenticated TLS transport, memory/eviction policy
   and durable-work reconciliation after transport loss.
5. **Storage:** select and verify private versioned encrypted storage, region,
   retention, credentials and exact-version recovery on the actual provider.
6. **Scanner:** verify isolation, production signatures/updates, quarantine
   handling, backlog/failure alerts and operator response.
7. **Queues:** commission independent worker pools, graceful drain/restarts,
   retry/reconciliation procedures and backlog/age alerts on the VPS.
8. **Scheduler:** commission a singleton scheduler, heartbeat and missed-run
   alerts with tested recovery.
9. **Email:** approve the production SMTP supplier, sender/DNS, secrets,
   processing location/terms, real delivery and failure/support procedures.
10. **Public ingress:** supply the domain, trusted TLS certificates/renewal,
    exact host/proxy policy, HTTPS enforcement, edge limits and private ports.
11. **Secrets and historical keys:** implement protected injection, separate
    credentials, rotation and independent recoverable key escrow.
12. **Monitoring:** connect private durable logs/metrics, retention/access,
    CPU/disk/availability alarms and tested delivery to an on-call owner.
13. **Backups:** provision encrypted offsite database/object backups, approved
    schedules/retention and failure/absence alerts.
14. **Recovery:** prove actual-provider point-in-time database and exact-version
    object/key recovery, approve RPO/RTO and rehearse production-sized cutover.
15. **Migrations:** rehearse target migration duration/locks, pre-migration
    checkpoint and expand/contract/forward-fix recovery; destructive rollback
    is not the primary plan.
16. **Deployed security/release verification:** approve the eventual passing
    immutable release, verify host/provider controls and repeat deployed health,
    smoke and current vulnerability checks before real traffic. Resolve scanner
    lifecycle/detail limitations, and verify on the actual deployment that
    external AI stays disabled and optional provider failure leaves the core
    API available.
17. **Privacy and operations ownership:** approve retention/deletion/data
    location, supplier terms and consent policy consistent with immutable
    history; assign incident/recovery owners, escalation and supplier contacts,
    and exercise the procedures.

External paid/customer-data AI is **NOT APPLICABLE while disabled**. Enabling it
later requires explicit approval of provider, models, retention, training/data
use, region, deletion policy, unit costs, platform budget and customer consent.
No frontend integration, next backend batch or production deployment was begun.

## Supporting evidence and launch blockers

See [reporting](B8-REPORTING.md), [document reads](B8-DOCUMENT-READS.md),
[performance](B8-PERFORMANCE.md), [observability](B8-OBSERVABILITY.md),
[security review](B8-SECURITY-REVIEW.md), [backup/restore](B8-BACKUP-RESTORE.md),
and [deployment/recovery](B8-DEPLOYMENT-RECOVERY.md).

The [explicit launch checklist](B8-LAUNCH-CHECKLIST.md) records all mandatory
production blockers: target commissioning, private database/Redis/storage and
verified TLS, public ingress/domain, production email, secret/key management,
scanner/worker/scheduler operations, durable monitoring and alert response,
offsite backups/PITR/key recovery and approved RPO/RTO, target migration/security/
capacity rehearsal, privacy/retention policy and incident ownership. External
paid/customer-data AI remains disabled and requires separate explicit approval.
