# Deployment and recovery

This is an operator runbook for the approved modular monolith. Local Compose
verification is not a production deployment. Production hosting, public TLS,
secret injection, database TLS, email, alert routing and recoverable offsite
backups must pass the B8 launch checklist before traffic is admitted.

## Release preparation

The supplied target is one Ubuntu VPS with **2 CPUs, 8 GB RAM and 1 TB disk**,
approximately 150 customers, 100 projects and 20 concurrent users. Begin with
one worker in each of the four dedicated pools and one scheduler, then validate
the actual target against the final [performance evidence](B8-PERFORMANCE.md).
The local two-CPU affinity experiment does not reproduce VPS disk/network I/O or
establish sustained capacity. The Compose memory ceilings collectively exceed
8 GB; they are per-container limits, not reserved or proven simultaneous usage.
Approve an aggregate working-set budget with room for the operating system,
Docker, PostgreSQL/cache, scanner/signature loading and maintenance. Verify peak
memory, OOM events, CPU contention and queue age before increasing concurrency.

Treat 1 TB as a disk allocation, not an approved retained-data size. Check free
bytes and inodes against database growth, WAL/PITR retention, private object
versions, signatures, logs, images and temporary backup/restore space. Define
monitored headroom from the approved retention policy. Do not start another full
scanner/database/storage stack beside a loaded 8-GB production stack merely
because the local synthetic drill passed. Use a separate recovery host/failure
domain, or an explicitly budgeted maintenance window with sufficient capacity;
same-host spare space is not an offsite backup.

Record the approved commit, its parent, clean tree, migration list and complete
quality/security evidence. Build one production image and record its immutable
digest. Use that same digest for web, default/document/AI/notification workers,
scheduler and the migration job. Tag names alone are insufficient: record and
compare the running image IDs/digests. Pin the infrastructure and scanner/parser
images verified for this release. Keep image signatures/registry controls under
the deployment owner's policy.

Review each migration for lock duration, table rewrites, index strategy and old/
new application compatibility. The migration identity is separate and absent
from the web/worker runtime. B8 validation includes an exact previous-batch
upgrade fixture, repeated no-op migration, PostgreSQL constraints and production
image smoke; these do not replace a representative staging migration rehearsal.

Use expand/contract changes: add compatible columns/tables/indexes first; deploy
code that tolerates both forms; backfill in bounded resumable operations if
needed; prove no old readers/writers remain; remove obsolete structures only in
a separately reviewed later release. Do not change issued terms, original
revisions, accepted baselines or append-only history as a migration shortcut.
New indexes must have measured workload evidence. `CREATE INDEX CONCURRENTLY`
needs its own nontransactional plan and failed-index cleanup procedure when
chosen; do not place it blindly inside a transactional migration.

Before a risky migration, require a fresh recoverable database backup/PITR point,
the matching retained object versions and recoverable encryption-key versions.
Record the backup identifiers and restore evidence privately. A dump file's mere
existence is not sufficient. Follow [backup and restore](B8-BACKUP-RESTORE.md).

Name the recovery owner and verify independent access to the backup system and
key escrow before deployment. The recovery set includes the APP_KEY version
needed by retained MFA data, historical application encryption keys where used,
storage SSE/KMS key versions and required access/TLS material. Record secret
version references privately; do not print values or put them in release
artifacts. New randomly generated keys cannot decrypt existing history. Keep
the recovery key and its administrative access separate from the encrypted
backup bundle, retain necessary old encryption keys during credential rotation,
and exercise recovery-team access without relying on the failed VPS.

## Deploy

Production startup validates the mounted configuration, then compiles configuration
and routes into that container's private `bootstrap/cache` tmpfs. Startup fixes
Laravel's cache paths, clears only its old configuration/route files, requires
directory mode `0700` and generated file mode `0600`, and fails before starting
the process if validation or compilation fails. The configuration cache contains
resolved credentials and encryption keys: never bake it into an image, copy it
to a release artifact, print it, or share it between containers. After changing
secrets, environment settings or the AI-enabled flag, drain and recreate the web
application, all four workers and scheduler with the intended environment; each
must rebuild its own cache. Changing an environment variable through `docker exec`
does not replace cached configuration, and rewriting caches beneath running FPM
is unsafe with timestamp validation disabled. Verify the safe environment probe
on all six recreated processes: both caches present, private permissions and
160 registered routes. Repeat the disabled-AI probe after the final AI-disabled
recreation. These checks expose only booleans/counts, never cache contents.

1. Confirm the release digest, configuration validation and all mandatory launch
   gates. Use `APP_ENV=production`, disabled debug and
   `HOLOUL_DEPLOYMENT_PROFILE=production`; the checked-in local-verification
   profile does not authorize a public deployment. Inside the approved image,
   run `php artisan operations:validate-config --json` with the intended process
   role and mounted production secrets. A failure blocks process startup and
   cutover. Keep external AI disabled unless all provider/model/privacy/region/
   retention/deletion/unit-cost/budget/consent approvals are explicitly recorded.
2. Establish a maintenance or rolling-release plan supported by the actual
   hosting topology. For the current single-host topology, announce the accepted
   interruption; it has no demonstrated zero-downtime guarantee. Stop new risky
   state changes while crossing an incompatible migration boundary.
3. Stop the scheduler before replacing workers. Let current jobs drain within
   their configured shutdown grace. Default work has a 30-second timeout with
   45-second grace; document/AI work has a 120-second timeout with 135-second
   grace. Notifications use 30-second timeout and 45-second grace. Record durable
   PostgreSQL pending/running work and lease state as well as Redis transport
   depths; an empty Redis queue alone is not a completed drain. Ensure no running
   writer crosses an incompatible migration boundary, while preserving pending
   durable intent for compatible code to resume. Do not use
   `kill -9` as routine draining. If a worker exceeds grace, preserve uncertainty
   and allow PostgreSQL lease/fence reconciliation; do not manually mark it done.
4. Run the release migration job once with the approved digest and migration
   credentials and `HOLOUL_PROCESS_ROLE=migration`:
   `php artisan migrate --force --no-interaction`. Capture its exit
   status and duration. A nonzero result blocks application cutover. Investigate
   locks/partial nontransactional work and use a reviewed forward fix.
5. Start the new application instances with runtime credentials only. Verify
   liveness and safe readiness, production mode/debug disabled, trusted ingress,
   HTTPS/session/CSRF behavior, storage privacy and exact runtime image identity.
6. Start the independent worker pools and one intended scheduler owner. The
   scheduler dispatches/reconciles durable PostgreSQL intent; Redis carries
   identifiers. Verify heartbeats, leases, retry/backoff and backlog age. A
   restarted worker must not redispatch an uncertain paid AI/email attempt.
   Read `php artisan operations:observe --json`; inspect component states and
   ages, because a successful CLI exit is not an all-components health result.
7. Run the bounded post-deploy smoke using synthetic accounts/data: authentication
   and customer isolation; request intake; private scan/download; accepted
   proposal and distinct Project lineage; reporting/audit permission denials;
   notification polling; safe disabled-AI behavior. Avoid real customer data and
   paid providers. Preserve append-only synthetic history according to policy.
8. Admit traffic gradually and compare latency, errors, database connections/
   locks, Redis health, storage failures, queue age and delivery outcomes with
   the measured baseline. Reconfirm backups, alert routing and incident contacts.

In the local verification environment the reproducible gate is
`bash scripts/verify.sh`. The isolated recovery exercise is
`bash scripts/verify-restore.sh`. Run restore and performance/capacity measurements
in separate windows; an extra ClamAV/DB/storage stack changes host contention.
Use the release evidence for the exact commands, dataset and measurements.
These guarded scripts create local synthetic fixtures; they are not deployment
commands for a public VPS. The production orchestration, host access and ingress
configuration remain launch decisions. Rehearse the same bounded checks through
the approved production/staging operator workflow after those are supplied.

## Rollback limits

Rollback of code is allowed only when the current schema and newly written data
are compatible with the earlier image. Repoint all application processes to the
previous verified digest, drain workers and rerun health/smoke. Keep the newer
compatible additive schema. Do not run `migrate:rollback`, `migrate:fresh`, manual
history deletion or blind schema downgrades as routine production recovery.

If a release changed meaning/invariants or wrote data unreadable by the old code,
prefer a reviewed forward correction. If recovery requires PITR, restore to new
isolated targets and follow the full database/object/key procedure. A database
rollback can lose accepted customer decisions and cannot undo an already sent
email or provider charge. Record the data-loss decision and reconcile external
outcomes before reenabling delivery. Never accept a successful health endpoint
as proof that immutable commercial/document lineage survived a rollback.

## Recovery scenarios

Before restoring, identify the incident owner, recovery point, approved image
and exact destination resources. Verify the destination host/account, database
and storage namespace are newly provisioned and contain no existing customer
data; record ownership and identifiers privately. Validate those targets again
before cleanup or deletion. The local drill enforces generated namespaces,
frozen plans, Compose labels, empty targets and authenticated archives. Its
synthetic-only scripts are not a way to restore over the live `holoul` project.
Production recovery needs equivalent provider-specific controls and must fail
closed when ownership, target emptiness, an archive or a key cannot be verified.

Restore to isolated targets with ingress, schedulers and external dispatch
disabled. Verify the entire customer/request/revision/document/proposal/
acceptance/Project/history chain, original object versions/checksums, key
decryption and database grants/guards before operator-approved cutover. Preserve
the original failed environment until evidence and recovery acceptance permit
its retirement. Do not use blanket volume/image pruning or a schema reset as
cleanup.

| Failure | First action | Recovery boundary |
|---|---|---|
| Bad application release | Remove from traffic, retain evidence, stop new work | Previous digest only if schema/data-compatible; otherwise forward fix |
| Migration failure | Stop cutover; inspect locks and transaction outcome | Forward repair or isolated full recovery; no destructive automatic downgrade |
| PostgreSQL loss/corruption | Stop writes and external dispatch | PITR/base backup plus object-version/key consistency and lineage checks |
| Redis loss | Keep PostgreSQL authoritative; replace private Redis | Reconcile durable operations and identifiers; preserve AI spending/uncertainty |
| Object store loss or wrong key | Deny affected document access and stop deletion/reconciliation | Recover original versions, bytes, metadata and keys; never substitute a new version silently |
| Scanner/parser outage | Keep uploads quarantined; monitor age | Restore isolated processor/signatures, retry through existing bounded operations |
| Email acknowledgement loss | Mark/retain uncertainty | Audited operator replay only after the existing explicit nonacceptance decision |
| Optional AI outage | Keep core API available, retain conservative reservations | Bounded classified retry; no automatic paid-provider failover or blind resend |
| Compromised secret | Contain access and preserve audit | Rotate access credentials; keep historical encryption-key versions required for recovery |

Every incident record identifies the owner, timeline, affected release and data,
containment, tested recovery point, actual loss/downtime, uncertainty and follow-up.
Production alert destinations/on-call coverage, provider support contacts,
retention/erasure authority and accepted single-host availability risk are human
operational decisions and remain launch blockers until approved and exercised.

B0's proposed **RPO ≤15 minutes and RTO ≤4 hours** still require data-owner
approval and production-sized evidence. The successful small local restore
does not prove continuous WAL/PITR, independent encrypted offsite retention,
provider version recovery, independent key escrow or provisioning/traffic
cutover time. Those controls remain blocked in the
[launch checklist](B8-LAUNCH-CHECKLIST.md), even after the local release gate
passes. Current launch classification remains **NOT PRODUCTION READY — BLOCKERS
REMAIN** until the mandatory production evidence is supplied and accepted.
