# HOLOUL backend — B8 operations and production readiness

Laravel modular monolith implementing infrastructure, Identity, Customers,
Categories, Project Intake, private Documents, Discovery, Proposals, Projects,
optional AI assistance and durable in-app/email Notifications.
The approved design is [the B0 architecture](docs/B0-ARCHITECTURE.md);
pinned versions and sources are in
[B1 versions](docs/B1-VERSIONS.md) and [B2 versions](docs/B2-VERSIONS.md).
The B2 browser/API contract is documented in
[B2 implementation](docs/B2-IMPLEMENTATION.md). The taxonomy/intake API,
permissions, exact budgets, immutable revisions and workflow decisions are in
[B3 implementation](docs/B3-IMPLEMENTATION.md). Private document APIs and lifecycle
are in [B4 implementation](docs/B4-IMPLEMENTATION.md), with pinned storage/scanner
details in [B4 storage and inspection](docs/B4-STORAGE-INSPECTION.md).
Discovery, commercial terms, approvals and customer decisions are documented in
[B5 implementation](docs/B5-IMPLEMENTATION.md). Conversion, delivery phases, team,
milestones and private project documents are in [B6 implementation](docs/B6-IMPLEMENTATION.md).
AI source/cost controls and notification delivery are in [B7 implementation](docs/B7-IMPLEMENTATION.md).
The initial AI adapter is a local deterministic sandbox, disabled by default;
external provider/privacy/spending approval remains outstanding.
Staff reports and restricted audit investigation are documented in [B8 reporting](docs/B8-REPORTING.md).
See [operational observations](docs/B8-OBSERVABILITY.md), [performance evidence](docs/B8-PERFORMANCE.md),
[backup and restore](docs/B8-BACKUP-RESTORE.md), [deployment and recovery](docs/B8-DEPLOYMENT-RECOVERY.md),
and the explicit [production launch checklist](docs/B8-LAUNCH-CHECKLIST.md).
The local verification stack is not a commissioned production deployment.
Payments and public proposal sharing remain outside the authorized scope.
The [B3 permission matrix](docs/B3-AUTHORIZATION.md) defines staff intake access;
the [approved B3 baseline](docs/B4-BASELINE.md) records the B4 starting commit.

## Development

Requirements: Docker Engine with Compose v2.24+ (or Docker Desktop with WSL
integration). Run from this directory in Linux/WSL:

~~~sh
docker compose up -d --build --wait --wait-timeout 900
~~~

The authenticated APIs use `https://localhost:8443`; health endpoints also listen at
`http://localhost:8080`. Local Mailpit is available at `http://localhost:8025`
through Nginx. These ports bind only to loopback:

| Environment variable | Default | Purpose |
|---|---|---|
| `HOLOUL_HTTPS_PORT` | `8443` | HTTPS identity/API origin |
| `HOLOUL_HTTP_PORT` | `8080` | HTTP health/API checks |
| `HOLOUL_MAIL_PORT` | `8025` | Mailpit UI through Nginx |

To choose different ports, export the relevant variables before starting Compose;
for example, `export HOLOUL_HTTPS_PORT=9443`. Compose updates the configured
identity origin to match. `HOLOUL_MAIL_PORT` changes the host UI port, not the
private SMTP port (`MAIL_PORT=1025`). No host PHP, Composer, npm, or manually
populated `.env` is required. Subsequent starts use `docker compose up -d`.

Startup generates a local self-signed certificate for `localhost` and `127.0.0.1`.
Export its public certificate and explicitly trust it for a local HTTPS check:

```sh
mkdir -p artifacts
docker compose cp nginx:/run/holoul-tls/certificate.pem artifacts/holoul-local-ca.crt
curl --cacert artifacts/holoul-local-ca.crt "https://localhost:${HOLOUL_HTTPS_PORT:-8443}/health/live"
```

For browser access, import that public `.crt` into the browser's or operating
system's trusted root certificate store. A Windows browser needs Windows trust;
trusting it inside WSL alone does not configure Windows. Export and import only
the public certificate; the private key stays in its Docker volume. The backend
provides API endpoints; a frontend and recovery-link pages are not included.

Startup creates random development secrets in project-scoped named volumes,
initializes PostgreSQL roles/databases once, runs schema migrations as
`holoul_migrator`, then starts runtime services. Bootstrap and migration jobs use the
same image and exit after success. App/queue/document-queue/ai-queue/notification-queue/scheduler receive only application
credentials; no migrator/bootstrap secrets. No credentials are printed, tracked,
or baked into images.

`GET /health/live` checks process availability. `GET /health/ready` checks
safe configuration, PostgreSQL, infrastructure schema and required runtime writes; Redis is excluded.
`GET /api/v1` returns the minimal API envelope. B2 supplies the cookie/session
endpoints; B3 adds 38 taxonomy and intake routes. B4 adds eight document routes,
and B5 adds 26 discovery/proposal routes. B6 adds 37 project routes, B7 adds 16 assistance/notification routes,
and B8 adds five staff reporting/audit routes, for 160 route definitions in total.
GET routes also accept HEAD and count as one definition. The implementation
documents above list the exact endpoints and access requirements.

~~~sh
docker compose ps --all
docker compose logs --follow app queue document-queue ai-queue notification-queue scheduler
docker compose run --rm verify vendor/bin/phpunit
bash scripts/verify.sh
~~~

The tools-profile verification container uses separate `holoul_test` and migration
credentials. Never aim it at real data. Verification checks isolation before fresh
migrations and records output under ignored `artifacts/`. It builds development and
production images, exercises PostgreSQL/Redis and health, and runs pinned security
scans, measured workloads, and an encrypted synthetic backup/restore drill. The inherited upgrades
and exact B7 → B8 upgrade tests preserve
baseline schema and record checks. B3 adds workflow, assignment, immutable revision,
money, idempotency, isolation and concurrency coverage. Only the development image
contains testing dependencies. The production
stage also excludes Composer, Git, compilers and PHP build headers.

B4 also starts private versioned SeaweedFS, ClamAV and an offline PDF/DOCX
inspector. Scanner signatures are downloaded on the first start; allow additional
startup time and memory (the scanner has a 4 GiB ceiling). Scanner and parser
containers have no network access. A separate updater sees only signature files.
Neither storage nor scanner ports are published. The full gate exercises real
object storage, antivirus detection, parser constraints and production-image
upload/download requests. See the B4 documents above for operational commands,
conservative format restrictions and production storage/retention requirements.

The storage image rebuilds pinned SeaweedFS 4.47 source with the upstream gRPC
security fix. Its first build downloads a large Go dependency graph and can take
considerably longer than later builds, which reuse the module/compiler caches.
Go runs only inside the pinned build stage; no host Go installation is needed.

The full verification script also requires Bash, curl and Python 3 in Linux/WSL;
application test tooling runs inside Docker.

Verification then runs the exact production image for app, queue and scheduler
with `HOLOUL_APP_ENV=production` and checks each service's effective environment and
disabled debug setting. It inserts one unpublished `infrastructure.b1_probe`
operation and waits up to 150 seconds for the actual scheduler, Redis transport
and worker to produce the expected `handler_missing` result, with a 160-second
process limit covering stalled I/O. No probe handler or business effect is
installed. Only that verified terminal probe is removed;
failed checks retain their reserved record for diagnosis. This smoke check uses
the isolated Compose deployment's existing development secrets and does not turn
the development topology into a production deployment. The B2 smoke also uses
real HTTPS cookies and the private Mailpit sandbox for identity, recovery and MFA
flows. B3 adds an HTTPS taxonomy/intake workflow and an edge-throttling check.
Its synthetic intake and history records remain as terminal fixtures; cleanup
disables their accounts, revokes sessions, removes fixture role grants and
deactivates their taxonomy. Credentials and recovery tokens are not retained in
verification output.

Services run source from the image. Rebuild after edits. For faster local tests,
use the development verification image with an explicit workspace bind mount:

```sh
docker compose run --rm --no-deps -v "$PWD:/var/www/html" verify vendor/bin/phpunit
```

Keep production containers running the source baked into their tested image.

## Infrastructure boundaries

Later reserved module folders contain no domain implementation. Identity and
Customers own account/profile writes; Categories owns taxonomy, and Project Intake
owns drafts, immutable revisions, assignments, clarification and workflow history.
Application workflows compose module contracts without passing domain models
across those boundaries. Audit is append-only, including raw SQL
UPDATE/DELETE/TRUNCATE guards. Runtime can append/read only;
metadata accepts bounded identifiers/statuses/counts, never arbitrary content.

Async operations keep intent/attempts in PostgreSQL; Redis carries operation IDs.
The scheduler reconciles lost work; leases and fencing protect duplicate/stale
commits. B2 registers the Identity recovery-mail handler; tests also register
infrastructure-only handlers.
Future handlers perform external work outside a transaction and return a
database-only writer; result and success commit together. External provider
idempotency and uncertain-outcome reconciliation remain necessary.

Intake submission appends a passive notification-intent record containing only
request/revision IDs and event kind. It is committed with the revision and audit,
but B3 does not dispatch or deliver intake notifications. Existing Identity
verification/recovery mail remains the only installed mail workflow.

Intake mutations hold the authenticated identity/session and request locks through
commit. Staff detail and child reads hold a shared request lock so reassignment
cannot change access halfway through reading revisions, information or history.
Customer ownership comes from the authenticated contact contract. Submitted
snapshots preserve contact and taxonomy labels after later profile/catalog edits.

Worker timeout: 30 s; operation lease: 60 s; Redis `retry_after`: 90 s; shutdown
grace: 45 s. Redis read timeout exceeds its blocking pop. Queue/scheduler
heartbeats check work-loop activity. Specialized business worker pools belong to
later batches.

PostgreSQL sessions now back Sanctum identity authentication. Sanctum is explicitly
registered; package auto-discovery remains disabled to prevent duplicate routing.
PostgreSQL is the only application database; Redis separates environments by
prefix and cache/queues by logical database. Its development memory policy is
`noeviction`.

## Production security and operation

Compose is a development topology. Only Nginx publishes loopback ports, including
the Mailpit UI proxy. Mailpit has no external network or relay configuration;
PostgreSQL, Redis and PHP-FPM also stay private. Application processes are non-root
with a read-only root and temporary writable paths. PostgreSQL initializes its persistent volume,
then the upstream entrypoint drops privileges. Derived PostgreSQL and Redis images
apply pinned OS security fixes; product versions are unchanged. PostgreSQL uses the
built-in `C.UTF-8` locale provider for deterministic Unicode ordering across Linux
distributions. B1 was verified on fresh volumes; never reuse a cluster with
different locale semantics without a reviewed migration.

Production supplies secrets externally, without the development initializer.
Application processes use `holoul_app`; migrations use a release-only identity.
Set `APP_ENV=production`, `APP_URL`, `APP_KEY`/`APP_KEY_FILE`, database/Redis credentials,
exact `APP_TRUSTED_HOSTS`, and `DB_SSLMODE=verify-full` with the provider CA. Debug is
hard-disabled regardless of `APP_DEBUG`. Cached configuration preserves host/proxy
settings.

The private Compose database has no TLS, so Compose explicitly uses
`DB_SSLMODE=disable`, including the production-mode smoke test against that disposable
fixture. Application production defaults still require `verify-full`. The local
HTTPS smoke explicitly trusts its generated SAN certificate; it does not verify a
deployed production certificate or database TLS.

Development Nginx discards forwarded headers. Production TLS ingress must
allowlist ingress source addresses before accepting forwarded protocol/client IP,
pass only normalized headers onward, and set Laravel `APP_TRUSTED_PROXIES` to private
Nginx peers. Never trust all proxies. Set HSTS at TLS ingress, not local HTTP.
B2 verifies HTTPS URLs, host rejection, Secure cookies and authentication throttling.
B3 also checks the ingress limit of 10 requests per second per client address,
with a burst of 40, independently of Redis. Tune this initial limit for deployment
traffic and trusted proxy behavior.
Credentialed cross-origin CORS is disabled.

Structured logs omit bodies, queries, credentials and exception text/stack traces.
Critical-only edge error logging avoids ordinary request-query leakage; sanitized
access statuses cover rejected requests/upstream errors. Business audit is separate.
Public errors carry stable codes and correlation IDs.

Deploy the same tested image to app/queue/scheduler, migrate once, and drain workers.
Use expand/contract schema changes compatible with old/new instances. PostgreSQL
backup/PITR, restore drills, external monitoring, TLS and hosting remain launch
gates in later batches.

## B8-P3 frontend integration candidate

The [B8-P3 integration guide](docs/B8-P3-ADMIN-INTEGRATION.md),
[OpenAPI 3.1 contract](docs/openapi.json), [current endpoint matrix](docs/API-ENDPOINT-MATRIX.md)
and [B8-P3 verification](docs/B8-P3-VERIFICATION.md) describe B1–B7 plus the uncommitted B8
candidate. The [B8-P2 review](docs/B8-P2-REVIEW.md) and its matrix/evidence remain historical snapshots.
Production performance certification remains pending the target VPS.
No production-ready release is implied.

With the local PostgreSQL/Redis verification stack already initialized, use
`bash scripts/verify-contracts.sh <unique-run-name>` for the independent review
gate. It does not rerun performance or replace the running application stack.
Reviewed JSON fragments are canonical; `scripts/contracts/build.py` assembles
the public spec/matrix and checks an explicitly reviewed source manifest.
The ordinary full verification script also includes contract validation before
its unchanged performance acceptance gate. Synthetic response captures can
contain test MFA/recovery material: keep them private and share summary reports.

## F1-E1 local dashboard integration

The [local integration guide](docs/F1-E1-LOCAL-INTEGRATION.md) explains the
`https://localhost:8443/admin` route, private synthetic E2E identities and reset
command. [Verification](docs/F1-E1-VERIFICATION.md) records routing/security
checks and the remaining F1 frontend MFA-flow blocker. This is local enablement;
production performance certification remains pending the VPS.

## CI execution

The GitHub workflow runs the shared verification script with PostgreSQL, Redis
and the local Mailpit sandbox. Any failed command fails the check.
Make “Foundation quality and integration”
required in branch protection when a remote exists; local workflow files cannot
configure GitHub enforcement. No broad analysis baseline or vulnerability
suppression is included. Actual results are recorded in the
[B1 verification report](docs/B1-VERIFICATION.md),
[B2 verification report](docs/B2-VERIFICATION.md) and
[B3 verification report](docs/B3-VERIFICATION.md); a workflow file alone does not
establish that hosted CI ran or that branch protection is enabled.
