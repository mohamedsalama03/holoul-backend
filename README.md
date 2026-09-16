# HOLOUL backend — B1 foundation

Laravel modular monolith implementing only infrastructure. The approved design is
[the B0 architecture](docs/B0-ARCHITECTURE.md); pinned versions and sources are in
[B1 versions](docs/B1-VERSIONS.md). Authentication and all business domains remain
unimplemented.

## Development

Requirements: Docker Engine with Compose v2.24+ (or Docker Desktop with WSL
integration). Run from this directory in Linux/WSL:

~~~sh
docker compose up -d --build --wait
~~~

The backend listens at http://localhost:8080. Set HOLOUL_HTTP_PORT to select another
loopback port. No host PHP, Composer, npm, or manually populated .env is required.
Subsequent starts use docker compose up -d.

Startup creates random development secrets in project-scoped named volumes,
initializes PostgreSQL roles/databases once, runs infrastructure migrations as
holoul_migrator, then starts runtime services. Bootstrap and migration jobs use the
same image and exit after success. App/queue/scheduler receive only application
credentials; no migrator/bootstrap secrets. No credentials are printed, tracked,
or baked into images.

GET /health/live checks process availability. GET /health/ready checks PostgreSQL,
infrastructure schema and required runtime writes; Redis is excluded. GET /api/v1
returns the minimal API envelope. No business/authentication/Sanctum routes exist.

~~~sh
docker compose ps --all
docker compose logs --follow app queue scheduler
docker compose run --rm verify vendor/bin/phpunit
bash scripts/verify.sh
~~~

The tools-profile verification container uses separate holoul_test and migration
credentials. Never aim it at real data. Verification checks isolation before fresh
migrations and records output under ignored artifacts/. It builds development and
production images, exercises PostgreSQL/Redis and health, and runs pinned security
scans. Only the development image contains testing dependencies. The production stage also excludes Composer, Git, compilers and PHP build headers.

Verification then runs the exact production image for app, queue and scheduler
with HOLOUL_APP_ENV=production and checks each service's effective environment and
disabled debug setting. It inserts one unpublished infrastructure.b1_probe
operation and waits up to 150 seconds for the actual scheduler, Redis transport
and worker to produce the expected handler_missing result, with a 160-second
process limit covering stalled I/O. No probe handler or
business effect is installed. Only that verified terminal probe is removed;
failed checks retain their reserved record for diagnosis. This smoke check uses
the isolated Compose deployment's existing development secrets and does not turn
the development topology into a production deployment.

Services run source from the image. Rebuild after edits. For faster local tests,
use the pinned PHP base with an explicit workspace bind mount; do not bind source
into production containers.

## Infrastructure boundaries

Reserved module folders contain no domain implementation. Audit is append-only,
including raw SQL UPDATE/DELETE/TRUNCATE guards. Runtime can append/read only;
metadata accepts bounded identifiers/statuses/counts, never arbitrary content.

Async operations keep intent/attempts in PostgreSQL; Redis carries operation IDs.
The scheduler reconciles lost work; leases and fencing protect duplicate/stale
commits. B1 has no production handlers. Tests register infrastructure-only ones.
Future handlers perform external work outside a transaction and return a
database-only writer; result and success commit together. External provider
idempotency and uncertain-outcome reconciliation remain necessary.

Worker timeout: 30s; operation lease: 60s; Redis retry_after: 90s; shutdown grace:
45s. Redis read timeout exceeds its blocking pop. Queue/scheduler heartbeats check
work-loop activity. Specialized business worker pools belong to later batches.

Sessions and failed_jobs are framework infrastructure, not implemented identity.
Sanctum is pinned with discovery disabled until B2. PostgreSQL is the only
application database; Redis separates environments by prefix and cache/queues by
logical database. Its development memory policy is noeviction.

## Production security and operation

Compose is a development topology. Only Nginx publishes a loopback port; PostgreSQL,
Redis and PHP-FPM stay private. Application processes are non-root with a read-only
root and temporary writable paths. PostgreSQL initializes its persistent volume,
then the upstream entrypoint drops privileges. Derived PostgreSQL and Redis images apply pinned OS security fixes; product versions are unchanged. PostgreSQL uses the built-in C.UTF-8 locale provider for deterministic Unicode ordering across Linux distributions. B1 was verified on fresh volumes; never reuse a cluster with different locale semantics without a reviewed migration.

Production supplies secrets externally, without the development initializer.
Application processes use holoul_app; migrations use a release-only identity.
Set APP_ENV=production, APP_URL, APP_KEY/APP_KEY_FILE, database/Redis credentials,
exact APP_TRUSTED_HOSTS, and DB_SSLMODE=verify-full with the provider CA. Debug is
hard-disabled regardless of APP_DEBUG. Cached configuration preserves host/proxy
settings.

The private Compose database has no TLS, so Compose explicitly uses
DB_SSLMODE=disable, including the production-mode smoke test against that disposable
fixture. Application production defaults still require verify-full. This local
test does not verify a production database certificate or TLS ingress.

Development Nginx discards forwarded headers. Production TLS ingress must
allowlist ingress source addresses before accepting forwarded protocol/client IP,
pass only normalized headers onward, and set Laravel APP_TRUSTED_PROXIES to private
Nginx peers. Never trust all proxies. Set HSTS at TLS ingress, not local HTTP.
Verify HTTPS URLs, host rejection, Secure cookies and client-IP throttling in B2.
Credentialed cross-origin CORS is disabled.

Structured logs omit bodies, queries, credentials and exception text/stack traces.
Critical-only edge error logging avoids ordinary request-query leakage; sanitized
access statuses cover rejected requests/upstream errors. Business audit is separate.
Public errors carry stable codes and correlation IDs.

Deploy the same tested image to app/queue/scheduler, migrate once, and drain workers.
Use expand/contract schema changes compatible with old/new instances. PostgreSQL
backup/PITR, restore drills, external monitoring, TLS and hosting remain launch
gates in later batches.

## CI

The GitHub workflow runs the shared verification script with PostgreSQL/Redis.
Any failed command fails the check. Make “Foundation quality and integration”
required in branch protection when a remote exists; local workflow files cannot
configure GitHub enforcement. No broad analysis baseline or vulnerability
suppression is included. See the B1 verification report for actual results.
