# B8 operational observations and configuration boundary

This implementation targets the supplied Ubuntu VPS envelope: two CPUs, 8 GB RAM, 1 TB disk, 150 customers, 100 projects and 20 concurrent users. The initial topology has one worker per queue. That is a starting configuration, not a claim of maximum capacity; measured load and queue results belong in the B8 performance evidence.

## Operator interfaces

Run these inside the pinned application image with the runtime database identity and mounted secrets:

```sh
php artisan operations:validate-config --json
php artisan operations:observe --json
```

Configuration validation exits nonzero when mandatory settings are unsafe. Container entrypoints run the same validation before production processes start. `/health/ready` independently validates configuration and PostgreSQL readiness, returning only the existing safe availability response. `/health/live` remains a process liveness check. The testing startup exemption requires both `APP_ENV=testing` and the explicit `local-verification` profile. Testing mode with the production profile fails startup before the target process starts; the dedicated local initialization path retains its separate bootstrap exemption.

The scheduler emits a fixed `operations.snapshot` JSON record and its heartbeat every minute. HTTP completion emits safe `telemetry.http` records with final response status, duration and SQL counts, including rendered errors. There is no public metrics route and no unauthenticated operational dashboard. `operations:observe` reports unavailable components in its JSON; its exit status alone is not an availability assertion.

## Data and privacy limits

| Observation | Source and interpretation |
| --- | --- |
| HTTP throughput, errors and latency | Redis minute buckets, closed route families/methods/status classes. Final middleware timing includes application handling; the load harness measures client-visible latency separately. |
| Query volume and slow queries | Counts and aggregate query time; the slow-query threshold is 200 ms. SQL text, bindings and query fingerprints are omitted. |
| PostgreSQL capacity | Connection counts, configured connection ceiling, active connections and lock waits from PostgreSQL statistics. No connection usernames, SQL text or resource identifiers. |
| Durable queue backlog | Pending, running, due and oldest-pending age from PostgreSQL, separately for default, documents, AI and notifications. Redis ready/reserved/delayed counts are transport observations only. |
| Queue latency | First claim wait and execution duration use bounded counters. Durable creation-to-completion percentiles use the most recent 10,000 rows within 24 hours. Pending operations have no completion latency sample. |
| Worker and scheduler | Fixed role/slot heartbeat, busy state, completed/failed job counts and cumulative busy time. Compare busy-time deltas over a measured interval; these are process observations, not business-success counts. |
| Redis | Ping duration, memory usage/peak/ceiling, evictions and transport depths. |
| Storage | Actual object-store put/open/stat/delete/list durations and exceptions, without object keys or versions. The independent TLS probe tests verified transport reachability only, not bucket authorization or policy. |
| Document scanning | Quarantine count and oldest age, document queue backlog/failures, scanner/inspector socket presence. Socket presence is explicitly not proof of a healthy scanner or fresh signatures. |
| AI | Fixed attempt outcomes, attempt timing, current UTC-day reserved/spent micro-USD and actual provider-call timing. External AI remains unapproved and disabled by default. |
| Notifications | Fixed delivery-attempt states/timing plus actual provider-call timing and an independent transport/worker. SMTP acceptance does not prove inbox delivery. |
| Backup and restore | Separate strict status files with state, completion timestamp and duration. Missing or malformed files are `unknown`; synthetic recovery drill files do not establish production backup health. |

Metric labels never contain user, customer, project, document, run, operation, recipient, URL-query or provider-reference identifiers. Existing structured request logs retain the established safe request correlation identifier; it is not a metric label. No bodies, email addresses, secrets, prompts, documents, raw exceptions or SQL are recorded by the new observations.

Metrics are disposable Redis data, expire after 15 minutes, and cannot determine authorization or business outcomes. The default snapshot combines the current and previous four minute buckets; the current bucket is partial. Histogram bounds are 5, 10, 25, 50, 100, 200, 300, 500, 1,000, 2,000, 5,000, 15,000, 120,000 and 600,000 ms. A sample above ten minutes is capped at ten minutes. Use raw benchmark durations for precise performance percentiles, not histogram interpolation.

Historical provider completion timestamps can have only whole-second precision while their starts retain fractions. The durable projection reports `timestamp_precision_ms: 1000` and `negative_interval_count` in each affected queue/provider group, clamps negative elapsed values to zero, and preserves stored history. It must not be used to claim subsecond provider latency. New provider-call counters use a monotonic clock around the actual adapter invocation; failures rethrow the original exception and remain visible as a separate fixed outcome. Provider exceptions do not by themselves establish whether a remote provider accepted a request; B7 uncertainty rules remain authoritative.

Observations use fixed groups, recent-row limits, and a 2.5-second PostgreSQL statement timeout per observation query. The TLS probe has a one-second connection timeout. Redis failure causes telemetry to become unavailable without rolling back business work. Existing rate limiters and security-sensitive Redis-dependent paths retain their own fail-closed behavior. Optional AI, Redis, scanner or storage availability does not redefine core PostgreSQL readiness; monitor their separate service states.

## Queue and process configuration

| Queue / connection | Compose worker | Job timeout | Durable lease | Redis retry visibility | Drain allowance |
| --- | --- | ---: | ---: | ---: | ---: |
| default / redis | queue | 30 s | 60 s | 90 s | 45 s |
| documents / documents | document-queue | 120 s | 150 s | 180 s | 135 s |
| ai / ai | ai-queue | 120 s | 150 s | 180 s | 135 s |
| notifications / notifications | notification-queue | 30 s | 60 s | 90 s | 45 s |

Redis transports only canonical operation identifiers. PostgreSQL retains durable intent and fencing. Notification jobs now have dedicated queue capacity. Failed-job storage accepts only canonical known job envelopes, including the existing AI job class, and checks the queue against durable operation kind when that operation exists. Raw envelopes and exception content never enter the failed-job record.

`HOLOUL_PROCESS_ROLE` is one of `app`, `default`, `documents`, `ai`, `notifications`, `scheduler`, or `migration`. Each worker instance needs a distinct `HOLOUL_WORKER_SLOT`; the observer reads slots from one through `HOLOUL_WORKER_SLOTS`, default one, maximum 64. Do not scale replicas with identical slot values. Heartbeats expire after ten minutes. Missing records mean unknown, not idle. A worker's heartbeat age can legitimately span its current job timeout; heavy queues need a longer stale threshold than the default queue.

## Explicit local and production profiles

Configuration defaults to `HOLOUL_DEPLOYMENT_PROFILE=production`. The checked-in Compose topology explicitly selects `local-verification`, including production-image verification with `APP_ENV=production`. The local profile accepts only the inherited localhost HTTPS origin and private synthetic `postgres`, `redis`, `storage`, and Mailpit topology. A public origin cannot use that exemption. It never authorizes real customers, external email, or production launch.

Real production additionally requires:

- Exact trusted hosts and trusted proxy addresses/CIDRs, HTTPS origin, matching same-origin CSRF configuration, debug disabled and secure encrypted `__Host-` session cookies.
- The runtime `holoul_app` database identity, separate migration process identity, PostgreSQL `verify-full` TLS with a mounted trust root, and verified Redis TLS with a trust root. Database/Redis hosts must be network hostnames/IPs; a Unix-socket path or embedded transport URI cannot bypass verified transport. Database identifiers and TCP ports are bounded before framework connection-string construction.
- Mounted application, database, Redis, private storage and email secrets outside the workspace; inline secret values are rejected. Resolved paths are checked, including traversal and symlinks, so an external-looking path cannot point to a workspace secret. Validated file paths reject connection-string delimiters/control characters, including a readable PostgreSQL CA filename that would inject a DSN option.
- Private/versioned/encrypted storage declarations, HTTPS storage with a trust root, bounded uploads, dedicated asynchronous queues, fixed safe timeout relationships and safe structured logging.
- Production SMTPS configuration and a verified sender. Local Mailpit/sandbox settings do not pass the production profile.
- Explicit edge HTTPS, edge rate-limit and maximum body-size declarations. A body limit above 12 MiB is rejected; documents remain capped at 10 MiB by their owner policy.

Storage endpoints must be absolute HTTPS URLs with a valid host/port and no userinfo, query or fragment. Application origins and production SMTP/Redis endpoints also receive hostname and port checks. These checks reject malformed mandatory configuration without attempting an external provider connection and report only stable safe codes.

The required declaration flags are `HOLOUL_EDGE_HTTPS_ENFORCED`, `HOLOUL_EDGE_RATE_LIMIT_ENABLED`, `HOLOUL_EDGE_BODY_LIMIT_BYTES`, `HOLOUL_PRIVATE_INFRASTRUCTURE`, `HOLOUL_STORAGE_PRIVATE`, `HOLOUL_STORAGE_VERSIONED` and `HOLOUL_STORAGE_ENCRYPTED`. See `.env.example` for TLS and secret-file settings. These flags describe an operator-verified deployment; setting them does not install certificates, create a firewall, encrypt a disk, configure an edge proxy, or prove storage policy.

## Monitoring and launch follow-up

An operator must connect scheduled JSON/access logs to a private durable collector, configure retention/access, and test alerts. This repository does not deploy an external monitoring vendor or claim that alerts have reached an on-call operator.

Suggested initial alerts, to refine against the measured VPS workload:

- Any mandatory configuration/readiness failure; missing scheduler heartbeat for three minutes; default/notification worker age above two minutes or heavy-worker age above four minutes.
- Unexpected 5xx responses, sustained client-visible p95 above the approved target, sustained high PostgreSQL connection utilization or lock wait growth, and Redis evictions or unavailable metrics.
- Growing oldest-pending age or due backlog in each separate queue; failed operations; quarantined documents without progress; storage exceptions; uncertain AI or notification attempts. Retry/replay follows owner policy, never an automatic telemetry decision.
- Production backup failure or absence, backup completion older than the approved schedule, and restore verification older than the approved drill cadence. A synthetic drill is separate evidence.

Infrastructure policy verification, actual certificate trust, encrypted/versioned storage, off-host backup durability, alert delivery, VPS operating-system patching and real external-provider acceptance remain deployment responsibilities. A passing local synthetic profile is not real-production approval.

## Focused verification evidence

- Pinned PHP 8.4.25 / PostgreSQL suite: 57 tests, 310 assertions across configuration, operations, independent queue transport and inherited readiness/failed-job/document transport checks.
- After adding actual provider timing and the retained-timestamp regression: operational telemetry suite passed seven tests, 61 assertions.
- After final configuration hardening: `ProductionConfigurationTest` passed 48 tests, 209 assertions in pinned PHP 8.4.25, with no database/provider calls. Cases cover Unix-socket/malformed endpoints, DSN field injection, real readable CA-path injection, canonical secret-path traversal/symlinks and child-process startup rejection. Scoped PHPStan/Larastan, Pint and shell syntax checks passed.
- Full PHPStan/Larastan analysis passed; Pint formatting and Compose validation passed.
- Identical synthetic settings with `APP_ENV=production` passed startup validation only under the explicit local profile. Selecting the production profile exited nonzero with safe mandatory-configuration codes.

The first complete Unit/Feature run subsequently **failed: 835 tests, 8,602 assertions, five errors and one failure**. New HTTP telemetry exposed two fixture assumptions: concurrency subprocess stdout was no longer a single JSON result, and the initial CSRF response initialized RedisManager before a test changed its connection configuration. A separate query-evidence write failed because its output directory was absent in the disposable verification container. The fixtures now route existing safe subprocess logs to stderr, rebuild Redis's configuration snapshot for the real closed-port outage probe, and create the evidence directory. Production telemetry and fail-closed security behavior are unchanged; all original security and concurrency assertions remain. The three affected classes then **passed 43 tests / 509 assertions** in `artifacts/b8-fixture-regression.log`. Retained first-failure logs and details appear in [the security review](B8-SECURITY-REVIEW.md).

The complete quality rerun on the earlier candidate **passed 835 Unit/Feature tests / 8,654 assertions** in **26:00.549**, plus **nine architecture tests / 38,799 assertions**, for **844 PHP tests / 47,453 assertions** with zero errors/failures/skips. This history remains in `artifacts/b8-full-gate.log`; its Python suite passed **17 tests in 3.066 seconds**.

The final-source quality stage, including subsequent startup compilation/runtime probes and arrival-model diagnostics, passed **835 Unit/Feature tests / 8,653 assertions** in **25:54.655**, plus **nine architecture tests / 38,799 assertions**: **844 PHP tests / 47,452 assertions**, with zero errors/failures/skips. Fresh `artifacts/b8-quality/` XML independently matches all **79 Unit/Feature class totals** and both overall results in `artifacts/quality.log`. Only the unchanged four-test intake concurrency class varies by one assertion because its transaction-winner branches have different assertion counts; no test was removed. Operational telemetry remains **7/61**, independent queue transport **3/33**, and production configuration **48/209**. Document SELECT counts are again **16/16/16 at 1/3/25 documents**. Pint passed **525 files**, PHPStan/Larastan level 10 and Composer/migration checks passed, and Python passed **17 tests in 2.939 seconds**.

Fresh saved runtime artifacts from **12:21–12:24 UTC on 2026-09-21** confirm all six private compiled-cache/160-route startup probes and the B1–B7 authenticated workflows on image `sha256:ba964a709c7faae3e0b341b16e79d006c8bc7b38dbf5a12128e14aa3d120a2a4`. Evidence includes private document scanning/downloads, separate commercial approval/customer acceptance, completed project delivery, four sandbox AI runs and in-app/local SMTP notifications; **paid provider calls are zero**. The local configuration is valid. The operational snapshot records PostgreSQL available at **62 ms**, Redis available at **3 ms**, and verified storage TLS reachability at **7 ms**; these are single probes, not latency percentiles or production-provider guarantees. Both production backup and restore states remain **unknown** despite the separately successful synthetic restore below.

The cached-runtime burst run in `artifacts/b8-cached-burst-latency-failed/` measured **480 requests, zero response errors, p50 308.726 ms, p95 631.887 ms and p99 923.249 ms**; latency acceptance failed. The harness schedules 20 synchronized requests every five seconds, an assumption not established by the supplied requirement for 20 concurrent users. Burst remains the strict default and all overall/per-endpoint p95 <300 ms / p99 <1 s checks remain intact. Optional staggered diagnostics preserve the same 20 identities and 480-request mix, report configured arrival rate/window separately from actual throughput, measure observed overlap and start lag, and fail if a user misses an assigned phase slot. They do not replace a failed burst gate.

The final-image strict burst result in `artifacts/b8-final-burst-latency-failed/` **failed latency acceptance**: **480 requests, 20 observed in flight, valid schedule, zero errors, 4.156 observed requests/s, p50 290.203 ms / p95 576.348 ms / p99 758.399 ms**. Both state-safe race probes passed. The unchanged overall and endpoint p95 <300 ms objectives failed; the separate staggered diagnostic does not replace this result.

Final-image queue verification independently passed with **33 heavy jobs**, one worker per queue, **8.709 seconds** of deliberate pause and **6.704 seconds** of drain. Immediately after heavy workers resumed, fresh default and notification work completed on the first attempt in **0.128346 / 0.094845 seconds**, both created and completed between the first and last completed document scan. This meets the 15-second bound during actual document progress. AI completion timestamps are reported separately; neither scenario establishes CPU saturation or external AI capacity. The retry scenario passed in **36.362 seconds**. Cleanup disabled **152 synthetic accounts** while retaining immutable history, and infrastructure restoration passed without failures.

The exact-image synthetic restore passed: **74 tables/658 rows/four sequences/1,219 constraints/137 triggers**, three exact private object versions/checksums and two decrypted MFA credentials. Backup took **48.402 seconds**, restore/comparison **56.182 seconds**, and the full isolated exercise **380.967 seconds**; resources were removed. This does not turn the production backup/restore status files healthy or certify PITR/offsite/key escrow/RPO/RTO.

Fresh source and eight image vulnerability summaries report **zero HIGH/CRITICAL findings** within the configured severity gate, and the configured secret scan completed without a finding-triggered failure. Seven images have missing Alpine 3.24 EOL metadata, not proof of unsupported lifecycle; PostgreSQL also retains severity-source and missing-CVE-detail warnings. See [the security review](B8-SECURITY-REVIEW.md) for the scope and limitations. Before/after scan identities match. All six final AI-disabled process probes report external AI disabled, private compiled caches and 160 routes; final core readiness is ready.

The final B8 gate **completed with exit code 1**, the explicit performance-blocked marker and no success marker. Independent quality, runtime, queue, recovery, scan and AI-disabled checks passed; strict burst latency failed. The blocked evidence records no B8 commit and matching current workspace/runtime source bytes. The launch classification remains **NOT PRODUCTION READY — BLOCKERS REMAIN**.
