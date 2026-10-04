# Temporary 4 GB launch candidate

**Prepared configuration; deployment BLOCKED and not authorized.** Upload policy is verified in `f1567e1ae264e76327e640accc0ca8a35437c48f`, parent `c5c7a1335102370a54b93cbdbabdd2cf3c1d0e92`. These files are a separate deployment-only delta. They do not change the live local stack, frontend source or OpenAPI.

Canonical origin: https://holoul.com.ly. www redirects permanently to it. This is a temporary low-traffic topology with new document uploads and AI disabled. It is not the full later 8 GB topology or B8 performance certification.

## Runtime inventory and isolation

13 long-running services and two successful preparatory jobs:

| Service | Memory cap MiB | CPU quota | Concurrency / purpose |
|---|---:|---:|---|
| app | 224 | 1.25 | PHP-FPM, 2 static children; recycle after 500 requests |
| postgres | 224 | 0.75 | 32 connections, 128 MB shared buffers, 2 MB work_mem |
| redis | 160 | 0.25 | 128 MB maxmemory, noeviction; no authoritative records |
| queue | 96 | 0.5 | 1 default worker, including portfolio durable work |
| document-queue | 128 | 0.5 | 1 worker; deletion, cleanup and durable document state retained |
| ai-queue | 96 | 0.5 | 1 worker; reconciliation/finalization retained, external AI disabled |
| notification-queue | 96 | 0.5 | 1 notification worker |
| scheduler | 192 | 0.5 | 1 schedule process, all inherited schedules retained |
| portfolio-processor | 448 | 0.75 | Existing single socket processor and image limits |
| storage | 512 | 0.75 | Private TLS S3; two simultaneous writes, 20 MB upload accounting |
| website | 320 | 0.75 | Node production runtime, 256 MB old-space |
| dashboard | 320 | 0.75 | Node production runtime, 256 MB old-space |
| ingress | 72 | 0.25 | Nginx one worker, 256 connections |
| migrate (one-shot) | 256 | 0.5 | Separate holoul_migrator; complete before runtime |
| storage-bootstrap (one-shot) | 256 | 0.5 | Verify private ACL/versioning/encryption before runtime |

Runtime caps sum **2,888 MiB**, leaving **1,027.89 MiB** from 3,915.89 MiB visible target RAM. The two initialization jobs finish before PHP runtime starts; do not run migrations/backups/builds alongside peak traffic. CPU quotas are individual burst ceilings, not simultaneous CPU reservations; total hardware remains two vCPU. No builds on the launch VPS. Nginx has room for its bounded 32 MiB request-buffer tmpfs plus worker/TLS memory; PostgreSQL retains over twice the study's charged peak, with active demand constrained by two FPM children and four workers.

ClamAV, FreshClam, Mailpit, local/test initializers and verification services are absent. The inspector/parser is omitted because new document processing and AI are disabled. Its source/image/pipeline remain available for restoration. An absent scanner cannot produce a clean verdict or promote a quarantined document. Document workers and all four durable queue connections remain; queues have inherited timeout/retry/after-commit semantics.

Only ingress publishes TCP 80 and 443. FPM 9000, PostgreSQL 5432, Redis TLS 6379, storage TLS 8333, Website 3100, Dashboard 3001 and image processing sockets are private. Separate internal `backend` and `web` networks use 10.203.81.0/24 and 10.203.80.0/24. Check for host/VPN/subnet conflicts before deployment; changing the backend subnet requires changing pg_hba and the exact trusted ingress IP together.

Only application processes have mail egress; ingress also has an external bridge. These bridges publish no application ports. No Docker socket is mounted. Root filesystems are read-only; PostgreSQL explicitly runs as UID 70 and requires its data directory to be provisioned with that owner. Secrets are read-only, runtime cache is private tmpfs, and all application containers prohibit swap by setting memswap_limit equal to mem_limit.

## Capacity basis and limits

The preserved local capacity study measured the complete scanner topology at 2,183.45 MiB steady and 3,076.06 MiB kernel peak. Removing scanner/updater from simultaneous samples yielded **1,574.25 MiB** main-node peak (including the inspector). Adding the study's conservative 64 MiB transport allowance and 1,024 MiB reserve yields **2,662.25 MiB**, leaving **1,253.64 MiB**.

This justifies a temporary planning envelope; it is NOT a fresh measurement of this TLS production topology on the VPS. The 2,888 MiB caps are explicit protection, not claims that each service consumed its cap. Low-traffic VPS validation, no-OOM checks, disk pressure, queue latency, HTTP acceptance and swap telemetry are still required. Do not count the 2 GiB host swap as available application RAM. Record host swap-in/out separately; stop launch on sustained application/host pressure or inherited latency/error failures.

Historical study failures are retained: the tight full-scanner run had roughly 4.2-second p95, and a legitimate maximum-size DOCX hit a scan limit and failed closed. The earlier B8 performance evidence is not overridden or squashed.

Private study: `/home/mohamed/.local/share/holoul-freezes/capacity-20261004-launch`. Source baseline c5c7a13. Its safety reserve was 640 MiB OS/Docker/filesystem plus 384 MiB variability. This plan retains at least the same total physical headroom.

## Routing and transport security

Nginx routes /api/v1 and /sanctum boundaries to the single Laravel front controller; /admin boundaries go to Dashboard with the full prefix preserved; all remaining ordinary paths go to Website. Unknown hosts/TLS names are rejected. /health/live and /health/ready are Laravel checks; /health/edge checks only Nginx. No arbitrary PHP file execution.

Nginx discards untrusted Forwarded/X-Forwarded metadata and writes the known HTTPS canonical host and observed client address. It does **not** rewrite Origin and sets no broad CORS. Exact trusted ingress address in Laravel is 10.203.81.2. Frontend CSP is preserved; API receives its strict headers. Cookies, CSRF and identity rotations remain application-controlled.

The private web network resolves holoul.com.ly to ingress, so Website server rendering can call its actual HTTPS origin without disabling certificate validation or traversing public DNS. The leaf certificate must be publicly trusted and include both apex and www. Dashboard image must implement basePath=/admin. Neither frontend is a development server.

API body limit remains 12 MiB, rate limit 10 requests/second per source with burst 40, and connection limit 20. Application limits still apply. API errors retain the documented safe envelopes. Logs omit raw URLs, queries, cookies, tokens, bodies and emails; each container has 5 MiB x 2 Docker logs. Node/application versions still require reviewed safe logging and image provenance.

PostgreSQL accepts only TLS + SCRAM for holoul_app and holoul_migrator on the backend subnet. Local postgres peer access is container administration only; remote superuser/test access is not granted. No holoul_test database is created. Runtime is not an owner or migrator. Redis uses TLS with password authentication, verified server identity, port 0 for plaintext, maxmemory 128 MB/noeviction and PostgreSQL durable intent as authority.

References: [PostgreSQL TLS](https://www.postgresql.org/docs/current/ssl-tcp.html), [PostgreSQL hostssl rules](https://www.postgresql.org/docs/current/auth-pg-hba-conf.html), [Redis TLS](https://redis.io/docs/latest/operate/oss_and_stack/management/security/encryption/).

## Required inputs and secrets

Copy environment.example to a protected deployment directory **outside the checkout**. Empty SMTP and image fields deliberately prevent Compose expansion. Supply reviewed immutable image digests (or verified loaded sha256 image identities); never use an unreviewed latest tag. Backend source must include f1567e1. Website/Dashboard production images and their exact identities still require frontend-owner acceptance.

Provision actual secrets directly on the VPS. Do not run the local initializer, import local/demo databases, use its self-signed certificates, or commit filled templates. No real secret or production certificate was generated by this task.

Expected `/etc/holoul/secrets` layout (or the private path selected in the environment file):

| Path | Contents / read identity |
|---|---|
| app/app_key | Laravel base64 32-byte application key; UID 1000 |
| app/database_password | holoul_app credential; UID 1000 |
| app/redis_password | Same as redis/password; >=32 random bytes encoded hex; UID 1000 |
| app/storage_access_key, app/storage_secret_key | Runtime S3 identity only; UID 1000 |
| app/mail_password | Real provider secret, >=16 characters under current validator; UID 1000 |
| migrator/password | Distinct holoul_migrator credential; UID 1000, mounted only for migration |
| bootstrap/postgres_password | Administrative credential; PostgreSQL bootstrap only |
| bootstrap/app_password, bootstrap/migrator_password | Exact protected copies of runtime/migrator values, readable by PostgreSQL UID 70 during initial bootstrap |
| redis/password | Same Redis credential, readable by container UID 999 (configured GID 999) |
| ca/ca.crt and ca/client-ca.crt | Same internal CA bundle; public trust material only |
| postgres-tls/server.crt, server.key, ca.crt | Internal-CA signed SAN postgres leaf/key; key UID 70, mode 0600 |
| redis-tls/server.crt, server.key, ca.crt | SAN redis leaf/key/CA; key readable only UID 999 |
| storage-server/certificate.pem, private-key.pem, ca.crt | Internal TLS leaf/key/CA; SAN storage, localhost and 127.0.0.1; server+client auth for internal gRPC |
| storage-server/s3.json | Filled storage-s3.example.json; no anonymous identity, runtime not Admin |
| storage-server/security.toml | Filled storage-security.example.toml; preserve KEK/JWT durably, UID 1000 |
| storage-admin/access_key, secret_key | Bootstrap S3 administrator, mounted only in storage-bootstrap |
| edge-tls/fullchain.pem, privkey.pem | Real public certificate chain/key for apex+www; key root:101 mode 0440 or UID 101 mode 0400 |
| Separate offline backup sealing key | Required for authenticated encrypted backups; no application mount, do not put it with the backup ciphertext |

Private directories 0700 for their reader; credentials 0400/0600 (edge key exception above), CA/certificates readable. A bind directory exposes its contents only to that service: no global shared directory of all secrets. Host backup tooling must separately protect root access and the sealed-key recovery path.

Pre-create empty state directories under /srv/holoul: postgres (UID 70 required before first start), objects (UID 1000), run/portfolio (UID 1000), operations (UID 1000 reader), acme-webroot (ingress readable). No automatic data import. Put operations' externally verified backup/restore status records there; do not fabricate successful timestamps. Bind mounts have create_host_path=false so typos cannot silently create empty secret directories.

Private versioned encrypted S3 bootstrap is the existing real verification: enforce private ownership, versioning and AES256; write a bounded probe and verify anonymous/ACL escalation denial; remove its exact probe versions. Both server-side SSE KEK and application encryption keys are persistent secrets. Losing the KEK can destroy recoverability.

## SMTP — blocker confirmed by owner

Approved sender address: **info@holoul.com.ly**. Requested display name: **Holoul Platform**. SMTP host, port, encryption and username are **unconfirmed**. Password will be provisioned privately on the VPS later.

Do not fill these gaps with guessed hostnames/465, a development mail service or a fake production credential. The current backend production validator requires implicit TLS `smtps`; if the provider supports only STARTTLS, report the mismatch for a separate reviewed change rather than lowering verification. Account recovery, invitations and mail notifications/contact delivery block acceptance until real SMTP delivery and authentication are verified.

The existing config/mail.php hardcodes display name **HOLOUL**. It has no MAIL_FROM_NAME variable. This deployment-only change does not pretend an environment variable can set Holoul Platform; the small sender-name configuration change remains a separate application requirement before mail acceptance.

Contact delivery also requires the existing HOLOUL_CONTACT_DASHBOARD_ACCEPTED=true and HOLOUL_CONTACT_PRODUCTION_MAIL_ENABLED=true after their integration/provider acceptance. The template defaults both to false; saving messages/receipts still works, but production contact mail is not silently activated.

The historical Contact destination remains info@holoul.ly in its existing product configuration; a sender address is not authorization to change that recipient.

## TLS / ACME — prepared, not issued

Use the prepared acme.ini with a reviewed Certbot installation/image. The [official Certbot webroot/renewal procedure](https://eff-certbot.readthedocs.io/en/stable/using.html) supplies account email and CA terms at execution. DNS A/AAAA for both names must reach the approved VPS; do not leave a stale AAAA record.

For first issuance, use the HTTP-only overlay with the base file and start **only ingress with --no-deps**. It serves the challenge and returns 503 for product paths; it uses no dummy HTTPS certificate. All declared input directories must already exist. Do not run the default full service set with the bootstrap overlay.

Example structure for a FUTURE authorized operator session, never executed by this task:
```sh
docker compose --env-file /etc/holoul/production.env -f compose.production.yaml -f deploy/production/compose.acme-bootstrap.yaml up -d --no-deps ingress
# Run reviewed Certbot with acme.ini and webroot /srv/holoul/acme-webroot.
# Install real fullchain.pem and privkey.pem into the edge-tls directory.
# Return to the base production file, validate Nginx, then recreate ingress.
```

When running Certbot in a container, mount /srv/holoul/acme-webroot at /var/www/acme and its separate protected account/certificate directory at /etc/letsencrypt. Keep ACME account private key out of ingress. Copy dereferenced certificate bytes into edge-tls atomically; do not mount dangling Certbot live symlinks. Validate chain, hostname, expiry, permissions, nginx -t and renewal reload. Plan a daily host timer for renew, installing changed certificates and reloading Nginx only on success. No Docker socket inside an ACME container. A failed renewal must alert the operator before expiry.

No certificates were issued, CA accounts created, DNS changed or timers installed here. Temporary certificates used for isolated syntax tests are not production material.

## Frontend handoffs and blockers

Use [the exact policy handoff](../../docs/TEMPORARY-DOCUMENT-UPLOAD-POLICY.md). Website must implement server-side WEBSITE_DOCUMENT_UPLOADS_ENABLED=false for guests and combine it with the existing identity capability for authenticated customers. The variable is declared here as a deployment requirement; the currently inspected Website source does not consume it. **Injecting it into the old image is insufficient.** Release of the new Website image and integration acceptance are blocking prerequisites.

Hide new upload/document-analysis dependent UI; keep intake without a file; use “رفع المستندات سيكون متاحًا قريبًا.” / “Document upload will be available soon.” Do not expose infrastructure details.

Dashboard must retain historical metadata and actual states and never label quarantined data safe. Staff role grants are unchanged; new document upload requests receive 503. Backend contract copies and frontend source were not modified.

## Disk and operations budget

Do not build images on the 38 GiB VPS. Plan conservative reservations from the measured study: images+rollback 6.5 GiB; PostgreSQL data 1 GiB initially; WAL 1 GiB; private objects/versions/quarantine plus portfolio 5 GiB; temporary processing 1 GiB; bounded logs 0.5 GiB; total **15 GiB** without scanner signatures, leaving about **19 GiB** of the stated 34 GiB free. This is a monitoring/admission budget, not an implemented storage quota. Reserve another 1 GiB for signatures on restoration. Preserve all existing object versions; do not delete history to meet a budget.

PostgreSQL max_wal_size is a checkpoint target, not a hard disk quota. Monitor growth, long transactions and retention. Add host journald limits and logrotate policy during the controlled deployment review; Docker log limits alone do not bound OS logs. Alert at 70% disk usage, stop optional batch work at 80%, and preserve at least 8 GiB free before onboarding more content. Backups must be sealed and transferred to approved off-host storage, with restore evidence; do not accumulate unbounded local archives. A 4 GB host must not run concurrent image builds or heavy backup/import tasks.

## Verification and release order

1. Policy application gate is already complete; see [verification](../../docs/TEMPORARY-DOCUMENT-UPLOAD-VERIFICATION.md).
2. Fill reviewed non-secret deployment inputs and provision private secrets later. Static check: `python3 deploy/production/validate.py --env-file /etc/holoul/production.env`. It never starts services and is not a deployment approval.
3. Finish Website flag/image integration, Dashboard image acceptance and sender-name/SMTP settings. Confirm TLS/ACME, storage private/encrypted/versioned verification, firewall, backups/restore, disk and subnet checks.
4. After a separate deployment approval: initialize only the fresh private production stores, run the migrator and storage-bootstrap successfully, then start all runtime services from the same reviewed release. Do not import local candidate users or E2E data.
5. Run actual same-origin login/MFA/logout, guest/customer docless intake, claims, portfolio/contact and queue/reconciliation checks. A disabled upload must return safe 503. Verify no production worker has an old cached true policy.
6. Measure the actual VPS at low traffic with unchanged performance/error thresholds, OOM/swap/pressure telemetry and a rollback plan. This task does not replace that acceptance gate.

## Restore full document mode on 8 GB

Rollback rule: keep the verified policy-capable backend image and uploads=false, or put ingress into maintenance while recovering. Do not roll back to c5c7a13 (or any earlier image that ignores the new policy) with the scanner absent. Preserve database and object versions; restore from authenticated backups only under a separate reviewed recovery plan. Verify the loaded image's source identity and resolved documents.uploads_enabled=false before allowing public traffic. An immutable digest alone does not prove it contains this policy.

Keep uploads=false and Website flag=false during preparation. Upgrade first; build/review a separate full-topology overlay with the preserved scanner/inspector images, socket mounts and FreshClam egress. Initialize real signatures and verify freshness; use one clamd thread and one document worker initially. Recreate application processes consistently. No local verification initializer or synthetic verdict.

Test EICAR rejection, clean PDF/DOCX including the supported 10 MiB bound, exact-version/authorization/claim document E2E, quarantine/download denial on scanner failure, and the capacity/performance gate. Address the retained maximum-size scan-limit failure before acceptance. Observe actual Quarantine → Scan → Available/Rejected.

Only after those gates, enable the backend upload policy across all web/worker/scheduler processes, then enable the reviewed Website flag. Clear/recompile config caches through normal entrypoints. Enabling a flag does not scan old quarantine or authorize bulk promotion: use the existing bounded retries/reconciliation. Gemini remains separately disabled until explicitly approved. No reconstruction of deleted scanning code or migrations is necessary.
