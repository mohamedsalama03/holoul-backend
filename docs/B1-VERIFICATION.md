# B1 verification evidence

**Status: the complete B1 local verification gate passed. B2 is unstarted and requires B1 review and separate authorization.**

The final `bash scripts/verify.sh` run started **2026-09-16T13:40:24Z**, finished **2026-09-16T13:43:11Z**, and exited **0**, ending with `All B1 verification gates passed.` Primary evidence: [final-verification.log](../artifacts/final-verification.log) and [quality.log](../artifacts/quality.log). Failed or interrupted earlier attempts are not counted as passes.

## Baseline and environment

The user approved [B0-ARCHITECTURE.md](B0-ARCHITECTURE.md) and authorized B1 only on 16 September 2026. B0 remains unchanged from baseline commit `53ae5d582f2f36ab66343e2531221be490404827` on `main`. B1 implements the Laravel foundation, reserved module boundaries, safe HTTP/console/logging behavior, audit and durable-operation primitives, Docker topology and CI. Authentication, customer isolation, RBAC and business workflows remain outside this batch.

Validation used Docker Engine **29.6.1**, Compose **5.3.0**, Linux/amd64 containers, PHP **8.4.25**, Laravel **13.32.0**, PostgreSQL **18.6**, Redis **8.2.9**, Nginx **1.30.5**, and Trivy **0.74.0**. The final run used fresh HOLOUL development volumes and real PostgreSQL/Redis; no SQLite or host PHP was used. Exact pins and compatibility sources are in [B1-VERSIONS.md](B1-VERSIONS.md).

The [shared verification script](../scripts/verify.sh) invokes [verify-container.sh](../scripts/verify-container.sh) and is also the [GitHub Actions entry point](../.github/workflows/ci.yml). Raw evidence and acquisition helpers remain under ignored `artifacts/`; the script regenerates evidence on subsequent runs.

## Executed quality and database gates

Every check below exited **0** in the final run.

| Gate | Executed check | Result |
|---|---|---|
| Topology/builds | Compose configuration validation; development, service and production-image builds | Passed |
| Manifest/lock | `composer validate --strict` | Valid |
| Locked installation | `composer install --prefer-dist --no-interaction --no-progress` | Nothing to install, update or remove |
| Platform | `composer check-platform-reqs` | All requirements satisfied |
| Dependency security | `composer audit --locked --no-interaction` | No vulnerability advisories |
| Style | `vendor/bin/pint --test` | **64 files** |
| Static analysis | PHPStan/Larastan **level 10** for application/bootstrap/config/routes | **Zero errors**; no baseline or broad ignores |
| Database isolation | `php scripts/assert-test-database.php` | `holoul_test` / `holoul_migrator` confirmed |
| Fresh migrations | `migrate:fresh --force --no-interaction` | **3 infrastructure migrations** |
| Repeat migrations | `migrate --force --no-interaction`, twice | Both no-op |
| Unit/feature suite | `phpunit --testsuite Unit,Feature` | **102 tests, 276 assertions; 8.003s** |
| Architecture suite | `phpunit --testsuite Architecture` | **8 tests, 3294 assertions; 0.150s** |
| Repeat fresh rebuild | `migrate:fresh` after tests | All 3 migrations reapplied |
| Config/routes | `config:cache`, `route:list --json`, `config:clear` | Only GET/HEAD `/api/v1`, `/health/live`, `/health/ready` |

JUnit class summaries printed into `quality.log` account for all unit/feature results:

| Area | Tests / assertions | Principal evidence |
|---|---|---|
| Durable operations | **24 / 85** | Commit/rollback, duplicate identity/delivery, lost transport, reconciliation, independent row locks, leases/fences, retries/exhaustion and atomic result/success rollback |
| Append-only audit | **46 / 67** | Safe metadata, transaction rollback, application-role/raw-SQL UPDATE/DELETE/TRUNCATE rejection and repeated fresh migrations |
| Failed jobs | **8 / 50** | Identifier-only replay, invalid payload rejection and safe exception storage |
| Console exceptions | **7 / 13** | Sanitized Artisan failures and prevention of raw SQL/private exception disclosure |
| HTTP/API errors | **14 / 55** | Health/API contracts, validation, request IDs, headers and safe errors |
| Log processor | **3 / 6** | Redaction and bounded structured context |

Architecture checks combine resolved PHP AST inspection with runtime configuration checks: module boundaries, thin controllers, external-client placement, PostgreSQL-only configuration, production debug suppression, and B1 route/model/migration scope. They supplement review and do not establish later business authorization or provider correctness.

## Exact release-image and service evidence

The final script started the development topology, then replaced **app, queue and scheduler** with the same release image under `APP_ENV=production`. Each running image identity matched `holoul-app:b1-runtime`; each reported `debug:false` and `testing_dependencies:false`. Runtime checks confirmed required native extensions, UID/GID **1000**, matching secret-directory ownership and absence of Composer, Git, compilers/build tools, PHP headers and PHPUnit.

All six long-running services—Nginx, app, queue, scheduler, PostgreSQL and Redis—were healthy; initialization and migration exited successfully. PostgreSQL and Redis had no published host ports. HTTP checks passed for liveness, readiness and `/api/v1` (**200**), request-ID/`nosniff` headers, and a **12 MiB + 1 byte** request returning safe JSON **413** with a request ID. The private query probe was absent from captured service logs.

Worker/scheduler heartbeats passed. A bounded processing probe then created unpublished operation `01a0aa73-fd20-71c1-8360-a33f4e56ae2c` and observed **scheduler → Redis → worker → PostgreSQL** reaching the expected terminal `handler_missing` result, then cleaned its synthetic records. No business handler was registered for the probe. See [runtime-async.json](../artifacts/runtime-async.json) and the three `runtime-*-environment.json` files.

These final Docker Engine identities are manifest/image IDs, not image-configuration digests or published application registry references:

| Runtime | Final image identity | HIGH / CRITICAL |
|---|---|---|
| `holoul-app:b1-runtime` | `sha256:8512f17119102daff6289ca1692a82101eb8ea9ea96676e27de6cb259642c0b7` | **0 / 0** |
| `holoul-postgres:b1-runtime` | `sha256:d196583bd2112ac3364feeaea01d59161bee6383fdc5ee81ea6d15c391b8db26` | **0 / 0** |
| `holoul-redis:b1-runtime` | `sha256:9c1090bff9af0d03c729dfb76293e93a8adc9d574a27e2538532df4fe293a482` | **0 / 0** |
| Nginx 1.30.5 Alpine | `sha256:73c75df4075c918f91017fdda46ad81e55e5af77ba3a64ca3d5014bd9244fe7f` | **0 / 0** |

All four final vulnerability scans exited **0** against exported release images. They covered 47 application, 54 PostgreSQL, 22 Redis and 71 Nginx OS packages; application Composer vendor packages also reported zero findings. Source secret scanning exited **0**, with explicit exclusions `vendor`, `artifacts` and `.git`. Reports are `holoul-app-security.log`, `holoul-postgres-security.log`, `holoul-redis-security.log`, `nginx-security.log`, `secret-scan.log` and `scanned-images.txt` under `artifacts/`.

No ignore rules, unfixed-finding exclusions, untrusted-package flags or scanner skip-update flags were used. These are dated HIGH/CRITICAL results, not a claim of freedom from every vulnerability or future advisory.

## Failures found and remediated

Earlier scans correctly blocked acceptance:

| Rejected image | HIGH / CRITICAL | Remediation |
|---|---|---|
| Application on Debian 12.15 | **74 / 6**; Composer 0 | PHP 8.4.25 Alpine 3.24, current OS fixes and musl-native extensions |
| PostgreSQL 18.6 Bookworm | **101 / 16** | Maintained Alpine variant, preserving PostgreSQL 18.6 |
| Unpatched PostgreSQL Alpine candidate | **30 / 1** | Pinned OpenSSL/util-linux fixes and maintained `gosu` rebuild |
| Original Redis 8.2.9 Alpine | **8 / 0** | Pinned OpenSSL and `setpriv` security fixes |

Nginx already passed. Original evidence remains in `app-debian-security.log`, `postgres-debian-security.*`, `postgres-alpine-security.*` and `redis-original-security.*`. [B1-VERSIONS.md](B1-VERSIONS.md) records exact replacements and sources. Core product versions, B0 service boundaries and PostgreSQL authority remain unchanged.

Other repaired findings:

- Laravel configuration merging reintroduced SQLite and a storage upload route; merging was disabled and required response/view configuration made explicit.
- The log sanitizer needed Monolog's processor interface. Validation text, error headers and correlation IDs were bounded and normalized.
- Whole-second PostgreSQL timestamps rounded immediate operation eligibility into the future; durable-operation timestamps now retain microseconds.
- Fresh rebuilds retained the audit trigger function; `CREATE OR REPLACE` preserves repeatability and guards.
- Read-only Nginx required all temporary paths under `/tmp`; ingress errors and logging were corrected to retain safe request IDs without query-value disclosure.
- Runtime smoke verification was strengthened to require production settings and actual durable processing, beyond heartbeats.
- The first production-mode attempt correctly rejected the local database fixture because production defaults require `verify-full` TLS. Compose now explicitly selects `DB_SSLMODE=disable` for its private local fixture; the application production default remains `verify-full`. That failure also exposed raw Artisan SQL output, leading to `SafeExceptionHandler` and seven regression tests. The failed log is [final-verification-before-fixture-tls-fix.log](../artifacts/final-verification-before-fixture-tls-fix.log). **Real production database TLS/certificate verification was not exercised.**

## Download recovery and cache integrity

Composer's effective cache path differed from the original build mount; the final Dockerfile sets it explicitly for locked development and production installations.

The first Trivy DB download timed out; a later attempt was interrupted by an external Docker Desktop shutdown. After restoration, the preserved archive was resumed from Aqua's official ECR replica, serving the same immutable blob as GHCR/default mirror. Aqua's [publishing workflow](https://github.com/aquasecurity/trivy-db/blob/main/.github/workflows/cron.yml) identifies these replicas. The complete archive was verified at **118,824,879 bytes**, SHA-256 `3760a796edeb7260d0dc551e817fe41e11647de853f8896ef645aed4848fdd08`, before extracting only `trivy.db` and `metadata.json`.

Upstream schema-2 values were preserved: UpdatedAt **2026-09-16T07:08:16Z**, NextUpdate **2026-09-17T07:08:16Z**. Local DownloadedAt records actual acquisition at **2026-09-16T12:54:22Z**. Normal Trivy freshness validation exited 0 without a skip-update flag; see [trivy-db-acquisition.log](../artifacts/trivy-db-acquisition.log). Final scanner commands allow 30 minutes and list official replicas.

Slow compiler APK transfers were replaced only after parallel byte-range acquisition, full archive checks, normal Alpine signature verification and offline installation of the complete tool/header set. The named BuildKit cache passed its own network-disabled installation proof; C/C++ probes succeeded. Evidence: `apk-prefetch-signature-proof.log`, `apk-buildkit-cache-proof.log` and the checksum manifest in `artifacts/apk-prefetch/`. Cache seeding is optional: the Dockerfile retains normal network package installation and does not reference acquisition helpers or local artifacts. It shares the patched runtime layer and restores same-pinned PHP build files only in the builder; production retains no compiler or development dependencies.

## Warnings, limits and handoff

Final logs retain two upstream metadata limitations:

- Trivy's EOL table lacks Alpine 3.24, while the scanner selects the **3.24 vulnerability repository** and analyzes its packages. Alpine's [release policy](https://alpinelinux.org/releases/) lists 3.24 as maintained.
- PostgreSQL's scan reports missing details for `CVE-2026-80256` and vendor-severity fallback. The [upstream curl advisory](https://curl.se/docs/CVE-2026-80256.html) identifies a **Windows-only wcurl** path-traversal issue of **Medium** severity; this Linux container is outside the affected platform. The warning was retained without a suppression.

The shared CI script passed locally. **Hosted GitHub Actions, remote branch protection, publishing and deployment were not run.** Require `Foundation quality and integration` after configuring a remote; committing the workflow alone does not enforce protection.

Production launch still requires TLS ingress and verified database TLS, production secrets/rotation, staging acceptance, migration/rollback procedures, backup/PITR and an actual restore drill, monitoring, workload/capacity validation, retention/region decisions and operational ownership. This local fixture establishes no production availability or recovery guarantee.

Runtime credentials cannot migrate schema or modify audit rows, but privileged database operators can alter database-only history; an independently controlled audit archive remains necessary. PostgreSQL retains durable intent despite Redis transport loss. Leases/fences protect database outcomes; future external handlers still require provider idempotency and reconciliation of ambiguous outcomes. B1 registers no business handlers and proves no external provider or object-storage integration.

The **120-file** inventory is [B1-FILES.txt](B1-FILES.txt). Final foundation commit and clean Git status belong in the handoff rather than this document's own commit hash. **B2 remains unstarted pending review.**
