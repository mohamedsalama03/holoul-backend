# Backup and restore

This runbook covers PostgreSQL, private document storage and the keys required to
read them. Redis is disposable transport; PostgreSQL durable operations remain
authoritative. The checked-in recovery tool accepts only randomly named isolated
synthetic projects. It cannot target the running `holoul` project or restore over
an existing database. It is evidence for the local recovery procedure, not a
production backup service or a claim that the production RPO has been achieved.

## Production strategy and decisions

The B0 proposed targets remain RPO ≤15 minutes and service RTO ≤4 hours, subject
to an approved hosting plan, workload and budget. A small synthetic restore time
does not establish either target for the production dataset.

| Asset | Required production protection | Recovery condition |
|---|---|---|
| PostgreSQL | Encrypted base backups and continuous WAL archive/PITR, independent account/location, monitored archive lag and retention | A verified base backup plus every required WAL segment; target PostgreSQL major version and extensions must be compatible |
| Private objects | Provider version retention/replication or a coordinated snapshot containing both namespace/version metadata and actual bytes; encryption keys retained for the same period | Every version referenced by the selected database recovery point exists, has the exact version identifier and checksum, and remains private |
| Application keys | Versioned APP_KEY and previous-key material in an independently recoverable secret manager, separate administrative authority from data backups | Encrypted MFA/recovery payloads remain readable; replacing APP_KEY with a newly generated key is not recovery |
| Storage keys and access | Retained object encryption KEKs/KMS versions and provider policy/configuration; credentials restored or rotated under a documented plan | Decryption succeeds and the restored runtime identity still has only its intended bucket actions |
| Release/configuration | Immutable image digest, migrations, non-secret deployment configuration, secret version references | Recovered application and schema are compatible; secret values are never committed or placed in release reports |
| Audit | Database history plus independently controlled append-only archive when approved | Application append-only grants/triggers survive; database administrators remain outside this protection boundary |

Use a managed provider's supported backup/PITR mechanism or an approved backup
manager; choose and verify it before launch. Configure WAL archival failure and
lag alerts against the 15-minute RPO, scheduled base backups, backup expiry and
independent encrypted offsite retention. Keep recovery keys outside the backup
repository and test their availability through the recovery team. Separate the
application, migration, backup and recovery identities. Backup credentials must
not be injected into web or queue containers. Protect deletion and retention
changes with separate authority and an auditable operator process.

PostgreSQL logical dumps provide a consistent single-database snapshot, but do
not provide continuous PITR or automatically include global roles. The drill
recreates the existing separated role definitions and restores table owners,
grants, sequences and data. Production must also preserve required role policy,
extensions and server configuration through its approved provisioning process.
See the official [PostgreSQL backup overview](https://www.postgresql.org/docs/18/backup.html)
and [pg_dump reference](https://www.postgresql.org/docs/18/app-pgdump.html).

For online recovery, select a database recovery point whose document versions
are protected by the storage recovery point. Retain all referenced versions for
at least the database PITR window plus the approved safety margin. Do not let an
object lifecycle policy expire a version still referenced by retained history.
Recovering a newer object superset is acceptable only after exact-version checks;
recovering fewer versions is not. If the provider cannot preserve version IDs
across disaster recovery, that provider's recovery design remains blocked. A
fresh object upload or ordinary S3 copy can produce a different version ID and
does not repair immutable document references.

For this repository's single-node SeaweedFS configuration, `/data` contains
volume bytes, master metadata and the LevelDB filer namespace. The local
`-dir=/data` setting, `filer.toml` and the pinned
[SeaweedFS 4.47 server implementation](https://github.com/seaweedfs/seaweedfs/blob/4.47/weed/command/server.go)
establish those locations. Our local recovery procedure uses a **cold full-volume
archive**, with all application writers stopped and storage shut down cleanly;
its exact-version recovery claim depends on the actual drill evidence below.
Storage server configuration, SSE KEK, TLS/JWT secrets, application access
credentials and client CA are included in separate encrypted archives.

The upstream operator's [backup documentation](https://github.com/seaweedfs/seaweedfs-operator/blob/master/BACKUP_SUPPORT.md)
supports the narrower distinction between filer metadata snapshots and content
replication: restoring namespace/chunk references does not restore absent volume
bytes. It does not certify this repository's cold archive procedure or promise
preserved S3 version IDs. Those are local assertions checked by our isolated
drill. Do not extrapolate its results to a distributed storage cluster or a
different provider without a separate restore drill.

## Reproducible synthetic drill

Build and verify the B8 release image first. Run from the repository on the Docker
host; all PHP executes in the pinned application image:

```bash
bash scripts/verify-restore.sh
```

Default image references are `holoul-app:b8-runtime` and
`holoul-inspector:b8-runtime`. The driver resolves immutable image IDs before use.
It protects every resolved image with a verified unique drill-owned local tag,
then freezes both Compose plans with pulling disabled. Concurrent replacement of
the ordinary release tags cannot discard the drill's selected image manifests.
It performs no image build and does not use the shared application or test
database. Allow sufficient memory for one additional scanner while building the
fixture. Do not run concurrently with capacity measurements.

1. Create distinct source and target Compose namespaces with random identifiers,
   independent networks/volumes/secrets and loopback-only random ingress ports.
2. Initialize the source's empty PostgreSQL through the existing migration role.
   Run the inherited HTTPS workflow with four synthetic identities, real email
   verification/MFA, actual private uploads/scans, proposal approval/acceptance,
   conversion, milestones, phase evidence and customer-confirmed completion.
3. Stop every writer, worker and scheduler. Restart only source PostgreSQL and
   storage. Read and hash all application rows, sequence values, constraints,
   triggers and runtime grants. Prove the complete relationship chain and read
   every Available document through the normal exact-version/checksum adapter.
   Decrypt both staff MFA credentials using the original application key.
4. Stop storage. Stream the custom-format PostgreSQL dump and full storage/key
   archives directly through authenticated XChaCha20-Poly1305 secretstream
   encryption. The random recovery key is kept separately from the bundle.
   No plaintext backup is written during capture. Authenticate the manifest as
   well. A multi-frame round trip and negative checks cover changed ciphertext,
   truncation, appended bytes, bad headers, wrong archive context and wrong keys.
5. Validate the target namespace and empty target volumes. Authenticate each
   archive before extraction, reject unsafe tar entries, and restore key material
   before starting target PostgreSQL/storage. Restore the database with
   `pg_restore --single-transaction --exit-on-error` only after an explicit
   empty-database check. Advance the target's transaction counter beyond the
   recorded source high-water mark using at most 100,000 harmless committed
   reads. No queue, scheduler or email service runs on the target.
6. Recompute the inventory and require exact equality: every row and sequence,
   immutable commercial/delivery/audit history, constraints/triggers/grants,
   Available object version IDs/bytes/checksums and decrypted MFA fingerprints.
   PostgreSQL can reparse equivalent CHECK expressions into a different textual
   shape during logical restore. Both inventories canonicalize only those
   expressions through the same PostgreSQL parser on empty temporary tables in
   a rolled-back transaction. Constraint names, counts, types, validation and
   deferrability/inheritance flags, table ownership and every non-CHECK definition
   remain exact. No persistent table or business row is changed by this check.
7. Remove only containers, networks and volumes bearing the exact generated
   project names and matching Compose labels, using previously validated frozen
   plans and recorded namespace ownership. Remove only the verified drill-owned
   image tags afterward. No broad prune, shared-volume deletion, shared image-tag
   removal or live database reset is used.

The logical-restore transaction step matters because retained intake revisions
contain `attachment_creation_xid` values used by an attachment-history guard.
A fresh cluster must not reuse one of those historical transaction IDs. The
drill records and checks the high-water mark; it never edits control files or
uses `pg_resetwal`. A larger transaction gap exceeds this synthetic tool's scope
and fails closed. Physical backup/PITR preserves the cluster's transaction-ID
history and is the production recovery path to validate.

The target temporarily materializes authenticated plaintext archives inside a
mode-0700 synthetic work directory, with mode-0600 files. Each is removed after
use. Local storage media may retain deleted blocks; this is another reason this
tool accepts only synthetic data. Production recovery staging must use approved
encrypted storage and an operational disposal policy.

The safe summary is `artifacts/runtime-restore.json`. The private directory
`artifacts/b8-restore-<random>/` contains encrypted archives, a separate recovery
key, private hash inventories and diagnostic logs. Never publish that directory,
copy its recovery key alongside the bundle, or treat same-host retention as
offsite protection. The summary contains counts, image IDs, checks and durations,
not private object keys, document text, passwords or MFA secrets.
The archive format uses the runtime's existing
[libsodium secretstream API](https://doc.libsodium.org/secret-key_cryptography/secretstream)
with bounded frames, a required authenticated final marker and archive-specific
associated data. Manifest authentication uses a separately derived MAC key.

For diagnosis, `--keep-failed` retains only the generated isolated resources. Use
the recorded private context with `backup-synthetic.py` / `restore-synthetic.py`
only at the appropriate stopped/empty stage; a restore cannot be repeated over
its populated target. Investigate the private log, then use the driver's guarded
cleanup method. Do not substitute the live Compose project name.

## Evidence and measured limits

The restoration stage of final verification passed on 2026-09-21, with the run
recorded at 12:29:30 UTC, using application image
`sha256:ba964a709c7faae3e0b341b16e79d006c8bc7b38dbf5a12128e14aa3d120a2a4`
and inspector image
`sha256:004294317ce43fbb1726b72541c4c5c394fdc144e782c2d9e8aace828a452819`.
The authoritative result is `artifacts/runtime-restore.json`, with the completed
run also recorded in `artifacts/b8-restore.log`. It supersedes the earlier
successful preflight retained as `artifacts/runtime-restore-preflight-passed.json`.
The authenticated backup manifest has SHA-256
`1669c6c52077e20986ee3a15094e6afb81bef09903cf1b9a21c85e1df3af43a7`.

| Check | Final exact-image restore result |
|---|---|
| PostgreSQL rows and sequences | 74 tables, 658 rows and four sequences matched exactly |
| Schema and privilege checks | 1,219 constraints including flags/owners, 137 triggers and runtime grants matched |
| Complete business lineage | One completed Project linked to its customer, converted request, original revision, Available baseline document, approved/accepted proposal and decision |
| Retained history | 12 project state changes, two membership events, eight milestone changes, seven phase-evidence records, one completion confirmation, four updates, 38 project activity records and 123 audit events; all table hashes matched |
| Private objects | Three Available documents; all original version IDs, SHA-256 checksums and 1,833 total bytes matched through the normal private adapter |
| Key recovery | Both staff MFA credentials decrypted; application and storage encryption/access keys recovered from authenticated archives |
| Authenticated archives | Ten encrypted archives, 700,752 bytes; 131,328-byte multi-frame round trip and all six negative cases passed; corrupted real archive rejected |
| Backup duration | 48.402 seconds, including the source inventory and encrypted capture |
| Restore duration | 56.182 seconds, including empty-target creation, key/data restore, transaction guard and full comparison |
| Full exercise duration | 380.967 seconds, including fresh services/signatures, real HTTPS fixture and guarded cleanup |
| Historical transaction guard | Source high-water 1,368; 599 harmless transactions advanced the new target beyond it to 1,370 |
| Cleanup | All generated containers, networks, volumes and drill-owned image tags removed |

Earlier preflights found recovery-harness issues: ordinary image retagging could
discard a selected manifest, this Compose version lacks `create --no-deps`, the
storage image seeds an empty directory, and logically restored CHECK expressions
can have a different equivalent textual form. Those cases led to the explicit
image/target/parser guards described above. The failed summaries remain in
`artifacts/runtime-restore-preflight-1.json` and
`artifacts/runtime-restore-preflight-2.json`; the final passing attempt generated
its source inventory, authenticated manifest and target independently from
scratch. No retained manifest was patched to manufacture equality.

These are small local synthetic measurements. No production WAL/PITR,
independent offsite retention, managed-provider failover or approved production
key escrow has been exercised. The 56.182-second restore is not a production RTO
claim and does not measure operator response or traffic cutover.

This successful restore does not mean the overall B8 gate passed. The same
verification attempt still failed strict burst HTTP latency acceptance: p95
576.348 ms against the provisional target below 300 ms. That performance result
and all mandatory production launch blockers remain unresolved; no B8 commit or
overall production approval is implied by this recovery evidence.

## Incident recovery sequence

Declare the incident and recovery owner. Isolate the affected deployment from
traffic and external delivery; stop schedulers and drain/stop workers. Preserve
logs and the last known data before any repair. Identify the incident time,
desired database point, compatible release image and required object/key versions.

Provision isolated recovery targets. Verify archive signatures/authentication
and the documented key escrow. Restore PostgreSQL/PITR and storage independently,
keeping external effects disabled. Run row/constraint/ownership checks, the exact
version/checksum audit, MFA decryption, private-access tests and core health smoke.
Any missing referenced object, decryption key or broken audit/acceptance/project
link blocks cutover. Do not rewrite immutable references to conceal missing data.

Decide how to handle actions that may already have reached an external provider.
Recovered durable intent can include email/AI attempts whose external outcome
postdates the chosen database point. Keep uncertain work fenced and use the
existing audited reconciliation/replay policy; do not bulk-redeliver it. Redis
can be recreated and pending identifiers republished from PostgreSQL. It is not
restored as authoritative business or spending state.

After operator acceptance, switch traffic, start exactly the intended scheduler,
then workers in controlled stages. Verify backlog age, errors, storage and
notification outcomes. Record actual data loss, total recovery time, decisions
and residual uncertainty. Schedule another drill at least quarterly and after
storage, PostgreSQL, key-management or schema-recovery changes.

## Launch status

| Control | Status after the final local restore | Remaining requirement |
|---|---|---|
| Local synthetic full-chain restore | PASS | Final exact-image verification above; overall B8 performance acceptance remains separate and failed |
| Production PostgreSQL RPO/PITR | BLOCKED | Provider/backup manager, encrypted offsite WAL/base backups, lag alerts and a production-sized point-in-time restore |
| Production storage recovery | BLOCKED | Approved region/provider/retention, preserved-version guarantees and provider-specific recovery drill |
| Independent key recovery | BLOCKED | Approved APP_KEY and SSE/KMS version escrow, access separation and recovery-team test |
| Production RTO | BLOCKED | Accepted dataset/capacity and a measured operational recovery including provisioning and cutover |
| Retention and disposal | BLOCKED | Data-owner approval for database/object/audit/backup expiry and incident copies |

Monitoring consumes safe status files at
`/run/holoul-operations/backup-status.json` and `restore-status.json`, containing
only `state` (`succeeded`, `failed`, `never`), `completed_at` (RFC3339) and
`duration_ms`. A production backup job must publish its own result atomically.
The synthetic drill writes private local status files; it never marks production
backup monitoring successful. Missing or stale evidence requires operator action.
