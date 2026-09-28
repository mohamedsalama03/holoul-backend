# G1 VPS deployment configuration review — 28 September 2026

This is deployment preparation, not deployment or capacity certification. No VPS connection, migration, data import, DNS change, production tag or public activation is authorized by this freeze.

## Target supplied by the owner

Ubuntu 24.04 LTS; 2 vCPU; approximately 2 GiB RAM, 2 GiB swap and 40 GB disk; Docker Engine 29.8.1 and Compose 5.5.1. These facts were supplied, not measured here. Earlier assumptions about a larger VPS are not evidence for this host.

## Configuration boundary

compose.yaml and docker/nginx/nginx.conf are the guarded **local-verification** topology: loopback ports, generated local certificates, Mailpit, PostgreSQL without TLS, Redis TCP, and local Next.js upstreams on 3001/3100. They are retained for reproducibility, not approved for public production deployment. The website forwarding addition already in the original worktree is preserved and fingerprinted; it adds no backend API operation.

Prepare a separate reviewed production topology with the actual same-origin hostname, public TLS, exact trusted Host/Origin/proxy configuration, private infrastructure/storage, verified PostgreSQL and Redis TLS, real SMTPS and externally mounted secrets. The approved business domain is holoul.com.ly; DNS/TLS/routing/provider details remain deployment inputs. Historical draft contact/taxonomy documents are not approved seed data and must not be automatically imported.

Keep APP_ENV=production, HOLOUL_DEPLOYMENT_PROFILE=production and APP_DEBUG=false. Do not use local-verification to bypass production validation. The environment example contains placeholders or non-secret policy defaults. Runtime uses holoul_app; only migrations receive holoul_migrator credentials. Each process needs its own private tmpfs configuration cache. Startup validates before and after compiling cached configuration.

The existing validator still rejects debug, insecure sessions, missing keys, unsafe database identities, incorrect queue routing/timing, unapproved AI providers, insecure production transport and inline secrets. Its operator declaration flags do not themselves configure a firewall, storage encryption or backup service.

## Resource tuning is a separate deployment decision

The local steady-service memory caps sum to about **9.938 GiB**, including a **4 GiB scanner cap** and six 512 MiB PHP process caps. Caps are limits, not actual resident usage or minimum requirements; they do not demonstrate fit on this 2 GiB host. Swap cannot replace workload measurements.

- Measure loaded scanner/signatures, PHP worker RSS under authenticated/guest/document traffic, database usage and parser/queue peaks together. Retain the original latency/error acceptance criteria. The earlier estimate was 150 customers, 100 projects and 20 concurrent users; confirm it still describes launch before capacity certification.
- Set FPM concurrency from a measured memory budget; the local pool allows eight children. Include every queue process, scheduler, database connection and maintenance reserve. Any lower concurrency must still pass the workload gate.
- Budget PostgreSQL connections/buffers/work memory, Redis limits, storage buffering and upload concurrency explicitly. PostgreSQL remains authoritative; Redis loss must not defeat limits or durable work recovery.
- Preserve dedicated default/documents/AI/notifications routing, after-commit semantics, worker timeout/retry-after/lease relationships, fencing and heartbeat expectations. Match declared slots to real processes. Do not disable scanning, merge security boundaries or weaken correctness to fit RAM.
- AI stays disabled. Do not omit a process that existing operations/readiness expects without separately reviewing the deployment plan.
- Budget the 40 GB disk for OS/images, database/WAL, private/quarantined objects, signatures and bounded logs. Do not retain developer artifacts, repeated image archives or unlimited build caches. Keep encrypted recovery copies off-host and key escrow separately protected.

If safe measured operation does not fit, increase capacity or obtain an explicit reviewed architecture decision. This Git freeze makes no throughput promise for the VPS.

## Build and verification boundaries

The development image keeps tests and fixture drivers. Production excludes tests, tools, local E2E, performance fixtures, synthetic restore drivers, contract tools and verification scripts. Only scripts/backup-seal.php remains as the reviewed operator encryption primitive. Private environment files, generated credentials/certificates, raw captures, Git history and development executables are excluded.

compose.verification.yaml explicitly mounts verification drivers read-only into local runtime containers and must never be used for production. The full local verification and G1 drivers select it; standalone runtime verification drivers need that explicit local override when using a release image. compose.restore.yaml independently mounts these scripts in its validated generated recovery namespaces. Local E2E seeding stays development-only and guarded.

The inspector and Nginx builds require libexpat >= 2.8.5-r0 after the current scan found a HIGH advisory in their previously built 2.8.4-r0 images. The first failed scan is retained. Rebuilt image identity, scan and inspector/regression results must be recorded before candidate approval. Do not deploy old cached inspector/Nginx images merely because the repository source has the fixed floor.

## Gates after Git freeze

Review the exact branch/SHA and external production topology, pin the built artifacts, prove migrations/recovery, then authorize deployment separately. Staff invitations remain on their separate candidate branch and are not part of this 172-operation G1 contract.

Deployment still must prove TLS/Host/Origin/session behavior, migrations and runtime privileges, private documents/quarantine, worker/scheduler health, provider backup/restore/key recovery and VPS capacity/performance. Local synthetic restore does not certify production providers, PITR or off-site escrow. FPM health is a socket probe; readiness checks configuration plus PostgreSQL writeability/schema/privileges. Heartbeats and operational observations cover other processes. Readiness alone is not full infrastructure/capacity certification.

No production release tag is authorized.
