# HOLOUL B8-P1 — performance remediation report

**PERFORMANCE ACCEPTANCE FAILED — NOT PRODUCTION READY.** Both final strict repeats failed the unchanged p95 target: **662.590 ms and 699.075 ms**, against **less than 300 ms**. All eight B8-P1 runs are preserved below. No production optimization was retained, no new B1–B8 full gate was run, and no B8 commit was created. Performance work stops with B8 blocked; this report is not deployment approval.

Authorized scope is B8-P1 performance diagnosis/remediation only. The target remains **p95 < 300 ms, p99 < 1,000 ms and zero unexpected HTTP errors**, using 480 authenticated HTTPS requests, 24 synchronized waves of 20, five seconds apart, the original endpoint mix and correctness/race checks. The original harness's existing endpoint-level checks also remain unchanged. Staggered traffic is not acceptance evidence. No frontend integration or subsequent business batch is authorized.

## 1. Root-cause analysis and measurement limits

The completed stable diagnostic points to shared admission/scheduling capacity as the largest observed delay. Across the exact 480 matched requests, mean client duration was 363.942 ms, PHP time before response was 86.766 ms, and Nginx upstream-response duration less FPM execution was 243.914 ms. The latter is a **queue/transport/response-boundary residual**, not a directly isolated queue timer. Independent observations found a listener backlog up to 17, while the original dynamic pool usually held three workers and reached at most four total workers in sampled observations. PHP bootstrap p95 was only 6.218 ms.

The sum of matched residuals divided by the sum of matched client durations was 67.02%. This was calculated from individual matched durations, not by subtracting percentiles. Twenty residuals were negative, consistent with differing response/termination boundaries and timer resolution; an individual cause is not proven. Retaining those values avoids inflating the result. This ratio describes measured residual time; it is not a causal percentage attributable exclusively to queueing.

Prestarting four workers did not meet the target: client p95 worsened from 700.879 to 771.459 ms. Mean residual fell only 4.1%, while mean PHP execution increased 30.0%, and inclusive database and connection time increased. Static8 was worse again: p95 992.073 ms and p99 1,854.780 ms. Its mean residual fell to 202.116 ms, while mean PHP execution rose to 254.648 ms; SQL-inclusive p95 rose from 150.675 to 448.500 ms and connection/configuration p95 from 29.415 to 100.955 ms. Insufficient worker startup alone is therefore not a sufficient explanation or remedy. The observations are consistent with greater concurrency competing for shared execution and database resources, but variable host activity and accumulated history prevent assigning every change solely to worker count. Both ephemeral static candidates were rejected. The original dynamic8 pool was restored before an uninstrumented warm-up and two strict measured repeats; all three failed acceptance.

Actual PostgreSQL lock-wait duration is **unavailable**. Direct PDO commit, transaction, authorization and SQL-call durations are measured, but SQL calls include execution, transport, scheduling and possible waits. The exact stable HTTP-window PG sampler observed no lock waiters/blockers, which does not exclude waits shorter than its 100 ms cadence. There is no measured basis for removing identity/session/business locks or relaxing durability.

Evidence limits apply throughout:

- The environment is local WSL/Docker, not the user's VPS. CPUs 0 and 1 are SMT siblings on one physical core. The original two-logical-CPU placement was preserved. This does not certify the capacity of a VPS with two vCPUs; actual CPU allocation, storage and contention remain uncommissioned.
- Client `perf_counter`, PHP `hrtime`, Linux FPM duration and Nginx upstream connect/header/response timers use monotonic clocks. Nginx `$request_time`, `$msec`, framework QueryExecuted elapsed and PostgreSQL timestamp ages use wall clocks and are unsafe across observed clock steps. Stable diagnostics add separate monotonic query clocks and verified common monotonic epoch evidence. Wall-based fields remain preserved and labeled.
- Stage spans overlap. PDO connection time is inside the first lazy session SELECT; authorization and session validation overlap controller/transaction work. Nested transaction windows overlap outer windows. Percentiles must not be added together.
- The profiling overlay and observers add overhead. Exact clean acceptance must remove them. The original harness internally labels synchronized traffic `diagnostic_only=false`; the outer instrumented run manifest explicitly labels these runs diagnostic-only. Both original files are preserved, and instrumented results are not promoted to clean acceptance evidence.
- The fixture adds the same synthetic class each time, but immutable business/audit history is retained. The accumulated dataset grows across runs. Small changes cannot be assigned solely to FPM configuration, and a single candidate run does not prove repeatability.

Clock implementation references and their exact pinned upstream sources are recorded in `artifacts/p1-clock-sources.md`. Common clock-domain proof is in `artifacts/p1-clock-domain-check.json`.

## 2. Before/after measurements — every completed run

All eight completed B8-P1 runs below executed 480 requests, observed 20 requests in flight, retained the original schedule, returned zero unexpected HTTP errors and passed both state-safe race probes. The matrix completed with successful observer exits and unchanged frozen source hashes. The clean warm-up and both strict repeats each passed evidence/source/runtime checks, produced expected race statuses [201, 201] and [201, 412], disabled 152 fixture accounts and passed restoration checks while retaining immutable history. All eight runs failed performance acceptance. Throughput is completed requests divided by observed elapsed time, not maximum sustainable capacity; offered traffic remains four requests/second in synchronized bursts.

| Run | Configuration and scope | p50 ms | p95 ms | p99 ms | Throughput req/s | Result |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| Historical B8 final gate | Original dynamic8, before this remediation | 290.203 | 576.348 | 758.399 | 4.156 | Failed; preserved prior evidence |
| P1 unchanged reproduction | Original driver/runtime; evidence destination only changed | 332.929 | 691.868 | 935.167 | 4.139 | Failed; reproduced before optimization |
| First dynamic8 diagnostic | Instrumented; Nginx reload during HTTP phase | 416.046 | 1,170.185 | 2,866.879 | 4.136 | Failed; unsuitable as controlled comparison |
| Stable dynamic8 diagnostic | Dynamic max8/start2/min1/max-spare3; frozen helpers | 337.899 | 700.879 | 990.263 | 4.154 | Failed; frozen-instrumentation baseline |
| Static4 diagnostic | Four prestarted workers; otherwise same diagnostic stack | 366.867 | 771.459 | 1,006.380 | 4.153 | Failed; no demonstrated improvement |
| Static8 diagnostic | Eight prestarted workers | 444.388 | 992.073 | 1,854.780 | 4.138 | Failed; no demonstrated improvement |
| Final uninstrumented warm-up | Restored original dynamic8; original telemetry only | 365.776 | 937.173 | 1,506.264 | 4.148 | Failed; result preserved |
| Final uninstrumented strict run 1 | Exact original harness after warm-up | 333.142 | 662.590 | 909.720 | 4.154 | Failed; p95 target missed |
| Final uninstrumented strict run 2 | Same configuration and exact harness | 333.109 | 699.075 | 929.308 | 4.153 | Failed; p95 target missed |

The initial static8 launcher failed with `Wsl/Service/0x8007274c` before script/output-directory creation: **zero requests ran**. The failure and successful retry are not hidden or counted as an HTTP measurement. See `artifacts/p1-static8-launch-failure.json` and the retry's run manifest.

| Run | Total customers | Total projects | Total requests | Audit rows at manifest capture |
| --- | ---: | ---: | ---: | ---: |
| Unchanged reproduction | 1,428 | 905 | 9,061 | 45,902 |
| First diagnostic | 1,578 | 1,005 | 10,061 | 50,779 |
| Stable dynamic8 | 1,728 | 1,105 | 11,061 | 55,776 |
| Static4 | 1,878 | 1,205 | 12,061 | 60,580 |
| Static8 | 2,028 | 1,305 | 13,061 | 65,370 |
| Final uninstrumented warm-up | 2,178 | 1,405 | 14,061 | 70,162 |
| Final strict run 1 | 2,328 | 1,505 | 15,061 | 75,019 |
| Final strict run 2 | 2,478 | 1,605 | 16,061 | 79,880 |

Each new fixture contributes 150 synthetic customers, 100 projects and 1,000 requests. These figures distinguish the launch estimate from the larger accumulated verification database.

## 3. Endpoint-level latency and stage attribution

The following clean endpoint table preserves every final run and the unchanged reproduction. Every latency cell is p50 / p95 / p99 in milliseconds; sample counts and the endpoint mix are unchanged. Samples of 12 staff calls and 16 submissions have coarse tail estimates. No pooled percentiles are used.

| Endpoint class | n | Unchanged reproduction | Clean warm-up | Strict repeat 1 | Strict repeat 2 |
| --- | ---: | ---: | ---: | ---: | ---: |
| Customer request list | 70 | 355.161 / 662.395 / 941.033 | 330.050 / 855.708 / 1,479.710 | 282.937 / 606.527 / 713.898 | 301.897 / 714.007 / 967.461 |
| Notification polling | 70 | 361.916 / 706.258 / 1,001.933 | 331.523 / 951.351 / 1,140.425 | 303.662 / 627.940 / 1,016.195 | 294.864 / 616.908 / 967.767 |
| Project detail | 70 | 309.729 / 637.997 / 676.467 | 383.161 / 1,027.435 / 1,584.158 | 342.853 / 607.839 / 1,001.972 | 355.818 / 691.163 / 929.308 |
| Customer request search | 68 | 339.181 / 655.886 / 777.867 | 403.022 / 830.489 / 1,585.708 | 274.458 / 635.963 / 688.991 | 326.256 / 625.619 / 865.704 |
| Customer request detail | 68 | 349.724 / 725.062 / 873.137 | 315.114 / 993.088 / 1,589.342 | 298.618 / 671.813 / 816.155 | 347.173 / 621.864 / 912.201 |
| Customer project list | 70 | 265.028 / 650.635 / 888.380 | 341.566 / 710.443 / 1,463.217 | 356.490 / 637.421 / 909.554 | 302.702 / 661.790 / 927.464 |
| Audit timeline | 12 | 432.106 / 935.167 / 935.167 | 478.685 / 1,125.974 / 1,125.974 | 357.746 / 568.517 / 568.517 | 434.396 / 950.958 / 950.958 |
| Staff project list | 12 | 308.395 / 523.491 / 523.491 | 337.768 / 1,076.807 / 1,076.807 | 358.389 / 734.134 / 734.134 | 362.622 / 824.470 / 824.470 |
| Staff request list | 12 | 250.580 / 501.622 / 501.622 | 468.966 / 1,069.245 / 1,069.245 | 353.862 / 680.986 / 680.986 | 372.504 / 645.899 / 645.899 |
| Staff dashboard | 12 | 412.877 / 930.044 / 930.044 | 485.586 / 1,139.048 / 1,139.048 | 285.103 / 903.890 / 903.890 | 390.986 / 540.503 / 540.503 |
| Request submission | 16 | 310.221 / 1,048.777 / 1,048.777 | 394.666 / 899.453 / 899.453 | 496.116 / 999.495 / 999.495 | 540.128 / 950.691 / 950.691 |

The next table uses the stable dynamic8 exact request set. It is diagnostic evidence, not clean acceptance or a fresh breakdown of the clean repeats. SQL time uses monotonic beforeExecuting-to-QueryExecuted elapsed, including first connection setup and previously registered query listeners. Query-count p95 includes the occasional session garbage-collection query.

| Endpoint class | n | Client p50 / p95 / p99 ms | PHP pre-response p95 ms | SQL-inclusive p95 ms | Queue/transport residual p95 ms | Query count p50 / p95 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Customer request list | 70 | 343.839 / 703.529 / 1,011.323 | 136.327 | 95.527 | 478.935 | 15 / 16 |
| Customer request search | 68 | 343.140 / 634.490 / 849.527 | 128.200 | 98.138 | 515.052 | 15 / 15 |
| Customer request detail | 68 | 331.299 / 814.826 / 1,070.126 | 226.884 | 169.286 | 594.425 | 19 / 19 |
| Customer project list | 70 | 353.865 / 634.489 / 1,074.537 | 124.279 | 93.191 | 523.629 | 15 / 15 |
| Project detail | 70 | 291.024 / 648.122 / 806.589 | 166.345 | 126.306 | 521.299 | 20 / 20 |
| Notification polling | 70 | 310.962 / 685.547 / 954.762 | 117.081 | 97.644 | 546.462 | 15 / 15 |
| Staff request list | 12 | 375.855 / 909.797 / 909.797 | 210.341 | 140.951 | 614.280 | 13 / 13 |
| Staff project list | 12 | 361.433 / 441.616 / 441.616 | 82.053 | 59.190 | 362.042 | 13 / 14 |
| Staff dashboard | 12 | 479.900 / 688.405 / 688.405 | 335.438 | 298.797 | 425.576 | 29 / 29 |
| Audit timeline | 12 | 413.677 / 944.647 / 944.647 | 215.934 | 189.285 | 733.519 | 16 / 16 |
| Request submission | 16 | 321.001 / 1,006.723 / 1,006.723 | 364.393 | 302.509 | 661.871 | 44 / 44 |

The slowest 24 stable requests span nine endpoint classes: request detail six, submission five, customer request list four, notifications three, search two, and one each for customer projects, project detail, audit and staff request list. They occur in waves 0, 3, 5 and 7. This is not a single-report-only failure. Reporting and audit still have measurable query work worth inspecting separately.

All three frozen-instrumentation matrix runs have **480/480 unique client/PHP/Nginx/FPM joins**, 480 complete monotonic-query traces, zero status/UUID mismatches, zero duplicate timed UUIDs, zero dropped records and zero unfinished timed spans. Setup, login/MFA, guards, queue work and later race requests are excluded by the exact client UUID set. No request/response bodies, cookies, credentials, SQL text, bindings or business identifiers are exported.

| Monotonic measured stage | Dynamic8 p50 / p95 / p99 ms | Static4 p50 / p95 / p99 ms | Static8 p50 / p95 / p99 ms |
| --- | ---: | ---: | ---: |
| PHP before response | 73.317 / 183.469 / 307.975 | 98.879 / 206.654 / 287.223 | 208.278 / 507.881 / 1,119.071 |
| FPM complete execution | 76.591 / 185.683 / 311.213 | 102.650 / 211.841 / 291.111 | 215.343 / 509.972 / 1,120.581 |
| Upstream response less FPM execution | 226.835 / 538.150 / 755.118 | 218.227 / 578.281 / 757.480 | 168.333 / 593.931 / 1,073.481 |
| Nginx upstream connect | 1 / 25 / 46 | 1 / 28 / 56 | 2 / 35 / 57 |
| PHP bootstrap | 1.892 / 6.218 / 10.747 | 1.937 / 6.826 / 11.440 | 2.084 / 12.971 / 25.269 |
| Controller dispatch | 31.781 / 117.580 / 225.601 | 40.940 / 122.984 / 208.797 | 90.325 / 310.137 / 668.369 |
| Authorization before business callback | 11.388 / 26.914 / 48.857 | 15.026 / 40.415 / 60.845 | 33.397 / 85.976 / 133.651 |
| Both SessionSecurity validations | 13.116 / 31.290 / 50.771 | 17.965 / 47.280 / 80.138 | 41.956 / 115.189 / 155.598 |
| PDO connect/configure | 13.416 / 29.415 / 46.344 | 19.506 / 43.149 / 60.230 | 42.778 / 100.955 / 228.556 |
| SQL-inclusive monotonic sum | 55.782 / 150.675 / 233.948 | 78.448 / 174.840 / 241.152 | 172.178 / 448.500 / 1,016.836 |
| Lock-statement inclusive sum | 2.791 / 13.211 / 33.982 | 3.486 / 14.763 / 26.329 | 7.750 / 41.367 / 111.873 |
| Outer transaction window | 30.328 / 116.640 / 218.244 | 39.340 / 122.488 / 206.186 | 87.341 / 308.113 / 667.791 |
| Direct PDO commit | 1.686 / 6.926 / 15.185 | 2.181 / 10.278 / 18.528 | 5.366 / 32.328 / 57.445 |

The same endpoint counts and mix were retained in the static candidates. Their endpoint p95 values show broad changes rather than a single isolated slow endpoint:

| Endpoint class | Static4 client p95 ms | Static8 client p95 ms | Static8 PHP p95 ms | Static8 SQL-inclusive p95 ms |
| --- | ---: | ---: | ---: | ---: |
| Customer request list | 651.897 | 889.007 | 395.987 | 319.987 |
| Customer request search | 860.742 | 926.070 | 419.943 | 334.335 |
| Customer request detail | 676.889 | 874.111 | 454.572 | 386.830 |
| Customer project list | 677.331 | 901.252 | 446.182 | 371.091 |
| Project detail | 782.643 | 854.689 | 491.864 | 418.175 |
| Notification polling | 712.944 | 872.304 | 332.255 | 288.433 |
| Staff request list | 954.921 | 950.915 | 374.259 | 322.010 |
| Staff project list | 812.620 | 1,854.780 | 357.231 | 315.573 |
| Staff dashboard | 1,116.086 | 992.073 | 724.575 | 673.698 |
| Audit timeline | 607.461 | 1,104.309 | 671.263 | 651.203 |
| Request submission | 800.596 | 1,947.194 | 1,445.283 | 1,297.552 |

Static8's slowest 24 requests include 11 submissions and eight other endpoint classes; 15 occur in wave 9, six in wave 7 and three in wave 3. Their concentration is useful diagnostic context, not proof of one causal database wait.

The dominant exclusive middleware costs in stable dynamic8 were StartSession before-next p95 44.137 ms, SessionAuthenticated before-next 25.326 ms, and StartSession after-next 9.196 ms. Exact-origin, CSRF, cookie security and remaining middleware were retained; their measured costs do not justify weakening them.

Full per-endpoint and middleware measurements, safe SQL-family totals and all 24 wave summaries are in each run's `p1-timing.json` and `p1-db-auth-analysis.json`. `artifacts/p1-stage-comparison.json` preserves matched stage changes.

## 4. FPM, runtime and resource evidence

| Observation | Stable dynamic8 | Static4 | Static8 |
| --- | ---: | ---: | --- |
| Pool mode / configured children | Dynamic / max8 | Static / 4 | Static / 8 |
| Sampled total workers min–max | 2–4; median 3 | 4–4 | 8–8 |
| Sampled maximum active workers | 3 | 4 | 8 |
| Sampled kernel accept backlog maximum | 17 | 16 | 12 |
| FPM scoreboard queue maximum | 8 | 3 | 12 |
| Max-children-reached delta | 0 | 0 | 0 |
| App cgroup CPU p95, one logical CPU=100% | 87.184% | 86.933% | 91.956% |
| Selected host CPU pair busy p95 | 100% | 100% | 100% |
| App CPU-pressure some p95 | 36.232% | 45.001% | 56.409% |
| App cgroup sampled memory peak | 111,415,296 bytes | 112,988,160 bytes | 165,609,472 bytes |
| OOM / memory-limit / CPU-quota throttling events | 0 / 0 / 0 | 0 / 0 / 0 | 0 / 0 / 0 |
| Sampled PHP-process scheduler runtime / runqueue delay | 11.200 s / 13.502 s | 11.336 s / 18.135 s | 12.998 s / 42.418 s |
| Voluntary / involuntary process context switches | 31,277 / 15,790 | 31,920 / 15,969 | 31,793 / 15,853 |

FPM's queue scoreboard is refreshed by approximately one-second master maintenance; polling it every 100 ms does not make that value a fresh 100 ms queue measurement. The independent kernel listener backlog is therefore also reported. The max-children-reached counter pertains to dynamic/on-demand spawning and can stay zero while a static pool is fully occupied. It is not evidence of spare capacity in either static pool.

Per-request PHP `getrusage` totals are 10.651 CPU-seconds for stable dynamic8, 10.783 for static4 and 12.326 for static8. The stable wave CPU sums average 443.801 ms, with p95 639.423 ms; static4 averages 449.296 ms, with p95 654.692 ms; static8 averages 513.583 ms, with p95 704.232 ms. These are observed PHP contributions, including tracing and excluding PostgreSQL/Nginx/other processes and final trace write. They are not a clean theoretical capacity bound. Host CPU/context-switch measurements include unrelated host work; process deltas miss work outside sampled lifetimes and include master/private-status activity where documented.

Production caches and the authoritative Composer classmap were already enabled before P1. PHP is pinned at 8.4.25. FPM OPcache is enabled, configured for 128 MB and 10,000 scripts, timestamp validation off; JIT is disabled. Live trace evidence showed approximately 31.1 MB used, 846 scripts, no cache exhaustion, waste or restarts. No measured OPcache capacity issue or reason to change the existing Laravel caches was found. CLI settings alone were not used to infer FPM occupancy.

The app image remained `sha256:ba964a709c7faae3e0b341b16e79d006c8bc7b38dbf5a12128e14aa3d120a2a4`; profiling used explicit ignored read-only bind overlays, recorded separately from image identity. Effective config and run manifests preserve hashes. Resource placement during load comes from the samplers/driver; a pre-run config snapshot with an empty cpuset is not substituted for the actual timed placement.

## 5. PostgreSQL, session, authorization and query evidence

Stable dynamic8 executed 8,427 captured statements: query-count p50/p95/p99 15/29/44, maximum 44. Static4 executed 8,423 with the same quantiles and maximum; static8 executed 8,453, again 15/29/44 with maximum 45. The small count difference includes variable session housekeeping/update behavior, not a changed workload assertion.

All 480 stable requests had one PDO setup span and first queried the session table. That first session read's monotonic p95 was 43.206 ms, including connection/configuration. Subtracting the nested connection span on each request leaves p50/p95/p99 4.987/13.695/22.581 ms, still including statement transport and prior listeners. Database connection establishment is measured as connect/configure elapsed; an independently isolated connection-wait duration is not available.

Authority reads remain explicit and bounded: identity SELECTs have per-request p50/p95 counts 5/5, identity-session SELECTs 3/3, and permissions SELECTs 2/2. Identity lock-statement p95 is 4.796 ms; identity-session lock-statement p95 is 3.888 ms. These are inclusive SQL-call times, not lock wait timers. Session activity writes and application authorization are preserved. The observed duplicate reads are candidates for a narrowly proven change, not permission to bypass fresh checks or reuse the preliminary middleware snapshot.

The stable exact HTTP-window PG report contains 1,155 fully contained 100 ms observations over 115.539 seconds. It observed at most nine non-observer connections, four active backends and three idle transactions; no lock waiters or blockers were sampled. WAL waits were seen in 30 samples: 29 WalSync backend observations and three WALWrite observations, with overlap. These are sample counts, never milliseconds or event totals. They include other runtime activity during the HTTP interval and do not identify individual HTTP backends.

The corresponding saved reports for all three matrix runs show:

| Exact HTTP-window PG observation | Dynamic8 | Static4 | Static8 |
| --- | ---: | ---: | ---: |
| Fully contained samples / HTTP window seconds | 1,155 / 115.539 | 1,155 / 115.562 | 1,159 / 116.006 |
| Maximum non-observer runtime connections | 9 | 10 | 13 |
| Maximum active backends / idle transactions | 4 / 3 | 4 / 4 | 9 / 7 |
| Samples with any lock wait / maximum blocking edges | 0 / 0 | 0 / 0 | 1 / 1 |
| Samples with any WAL-related wait | 30 | 49 | 75 |
| PG observer query elapsed total / fraction of HTTP window | 1,337.715 ms / 1.158% | 1,338.392 ms / 1.158% | 3,365.972 ms / 2.902% |

Static8 sampled one transaction-ID ShareLock waiter with one blocker at an observation starting about 46.182 seconds after the first HTTP request. This is neither an exact lock duration nor attribution to a particular endpoint. Its observer query took 45.002 ms, which must not be substituted for waiter duration. WAL, lock, connection and idle-transaction counts reflect all runtime activity in the HTTP interval, not exclusively the 480 requests. No sampler counts are multiplied by cadence to claim exact waiting time. See each run's `p1-postgres-http-window.json`.

PostgreSQL 18.6 retained `fsync=on`, `full_page_writes=on` and `synchronous_commit=on`, with maximum connections 100 and `fdatasync`. IO/WAL timing collection was off; zero time counters cannot establish absence of IO wait. The observer's read-only setting and short timeouts apply only to the observer, not application behavior. No PG locking, timeout, invariant, grant or durability setting was weakened.

Saved query-plan review identified the following, without introducing speculative indexes:

| Workflow | Stable separate EXPLAIN execution | Finding |
| --- | ---: | --- |
| Customer requests, limit25 | 0.057 ms | Uses `intake_customer_created_idx`; no demonstrated indexing gap |
| Admin requests, limit25 | 4.764 ms | Sequential scan and sort; secondary hypothesis at this cost |
| Staff projects, limit25 | 0.072 ms | Uses primary key; bounded indexed read |
| Reference lookup | 0.056 ms | Unique reference index; one row |
| Audit timeline, limit 25 | 42.401 ms | Broad scan/sort of roughly 56,246 rows to deliver 25; an `(occurred_at, id)` ordering-index experiment is justified for investigation, not yet implemented/proven |
| Audit second page | 51.237 ms | Broad scan/sort persists despite cursor-bounded output |
| Staff text search, limit25 | 58.238 ms | Broad-match text work; this is **not** the customer search used by the timed HTTP mix |

Dashboard uses 15 fixed aggregates, including six repeated request/revision cohort scans with separate EXPLAIN times 6.384–26.854 ms. This is repeated aggregate work, not application N+1. Compatible query consolidation is a potential experiment only if it preserves the repeatable-read snapshot, bounds, cohort/status rules and currency separation. No Redis business cache, stale aggregate or materialized view was introduced.

At requested page limits 1/25/100, profiled query counts remain constant: customer/admin requests 1, staff projects 1, proposals 2, documents 8, notifications 3 and audit 1. Cardinality limits matter: this customer's requests only reach 7 rows, proposals 1, documents 25 and notifications 10; those cases do not prove behavior at 100 actual returned rows. Staff request/project and audit probes do reach 100 rows. Every retained SELECT fingerprint appears once per action. No exact duplicate index candidates were found, but this is not a complete redundant-index audit.

EXPLAIN executions are separate warm-buffer replays, not HTTP timings, and parent/child plan times must not be summed. Exported plans omit some sort/temp/worker details, so absence of spill or exact planner cost is not established. Source hashes, indexes, plan cardinalities and remaining limitations are in `artifacts/p1-query-plan-review.json` and `.md`.

Redis's existing operational monitor recorded PING p50/p95/max of 2/4/4 ms in stable dynamic8 and static4, and 2/11/11 ms in static8. All 16/16, 16/16 and 15/15 samples respectively were available. Sampled memory peaks were 1,666,024, 1,679,944 and 1,685,088 bytes against 128 MiB maxmemory; eviction counters and sampled ready/reserved/delayed queue lengths were zero. These sparse observations cover the original monitor lifetime, including setup/authentication/races, rather than an exact 480-request monotonic join. PING uses integer-truncated monotonic elapsed and can include lazy connection and scheduling time; it is not server-only or per-request cache latency. The monitor sleeps five seconds after each command batch, and wall timestamps are unsafe across clock steps. No absence of transient latency or queue backlog is inferred. Full source-bound evidence is `artifacts/p1-redis-review.json`.

## 6. Changes retained, rejected and deferred

**Retained for evidence only:** payload-free UUID correlation, bounded private tracing, direct FPM/kernel resource sampling, monotonic SQL/stage clocks, safe PG observations and saved plan/analysis artifacts. These are ignored diagnostics; their runtime mounts/status listener were removed before the clean warm-up. The preexisting B8 production caches remain baseline behavior, not a new P1 gain.

**Rejected as remediation:** static4 and static8. Both prestarted pools failed, with client p95 worse than the frozen-instrumentation dynamic baseline. PHP execution, session/authentication, SQL-inclusive, connection and commit time increased with both candidates; static8 showed substantially greater scheduler delay and execution tails. No production optimization is retained from this matrix. The original dynamic8 configuration has been restored for final uninstrumented verification. Increasing max children without outcome evidence is not accepted. FastCGI connection time is much smaller than the broader residual, so connection keepalive has not been demonstrated as the primary remedy.

**Deferred, unimplemented candidates:** a narrowly targeted audit ordering index; consolidation of compatible reporting aggregates; reuse of freshly transaction-locked identity/session rows through shared validation logic, eliminating two rereads while retaining all checks and locks; and a separately designed persistent-connection experiment. None has proven a strict-load benefit yet.

Persistent PDO cannot be enabled safely as a flag-only benchmark change: the diagnostic per-request application-name DSN would defeat reuse, and session state/reset, rollback/error handling, cross-user isolation, reconnect/recycle behavior, credentials and bounded connection counts require explicit proof. It is not part of the current runtime.

Authorization, CSRF/origin checks, cookies, rate limits, session expiry/revocation, transaction-held authority, PostgreSQL locks/invariants, audit append-only behavior, durable operations, document inspection/access and AI safety remain unchanged. No Octane/RoadRunner/Swoole, Kubernetes, replica, new cache architecture or unrelated package was introduced.

## 7. Exact final workload and diagnostic incident record

The app and Nginx containers were recreated from the original Compose configuration before the clean warm-up. `artifacts/p1-final-effective-runtime.json` confirms dynamic max 8/start 2/spares 1–3, no FPM status path/listener, original images and enabled configuration/route caches plus authoritative autoload. The warm-up manifest proves original app/vendor/config file hashes, no diagnostic mounts and unchanged runtime before/after the run. Its original B8 operational monitor remains enabled; added P1 tracing and high-frequency observers are absent.

The clean warm-up completed at 17:09:25 UTC: p50/p95/p99 365.776/937.173/1,506.264 ms, 480 requests, zero unexpected errors, observed concurrency 20 and valid original arrival schedule. Start-lag p50/p95/p99/max was 4.621/11.292/16.896/20.746 ms. Both race probes completed with expected statuses. Source/runtime/evidence checks passed; performance acceptance failed. Evidence is `artifacts/p1-final-warmup/`.

Both subsequent strict measured repeats completed with the exact original workload. The following scheduling and integrity evidence is retained independently of the failed latency outcome:

| Run | Start lag p50 / p95 / p99 / max ms | Observed max in flight | Source/runtime/evidence checks | Cleanup disabled | Restoration | Private fixture directories | Queue isolation / live drain / retry |
| --- | ---: | ---: | --- | ---: | --- | ---: | --- |
| Unchanged reproduction | 5.014 / 16.140 / 31.428 / 34.586 | 20 | Preserved original reproduction evidence | Recorded by original driver | Passed | Not used as a new clean-run integrity claim | Passed / passed / passed |
| Clean warm-up | 4.621 / 11.292 / 16.896 / 20.746 | 20 | Passed; 461 source hashes | 152 | Passed | 0 | Passed / passed / passed |
| Strict repeat 1 | 4.874 / 12.016 / 25.635 / 27.199 | 20 | Passed; 461 source hashes | 152 | Passed | 0 | Passed / passed / passed |
| Strict repeat 2 | 5.004 / 11.548 / 14.553 / 16.509 | 20 | Passed; 461 source hashes | 152 | Passed | 0 | Passed / passed / passed |

The burst scheduling-valid flag alone does not guarantee on-time starts; start lag is therefore retained. All runs kept the original timing window, arrival shape, HTTP assertions and race checks. Cleanup revoked synthetic sessions/grants and retained immutable history. Private fixture directories were checked only for presence; credential contents were not exported.

The three clean final source maps and full app/Nginx runtime boundary snapshots were identical: the same container IDs, creation/start times, image IDs, zero restart counts, deployed file hashes and mounts. A full strict warm-up preceded fresh client-process repeats. This supports runtime continuity, but does not prove that every FPM worker/cache page stayed hot or directly observe all possible in-container reloads. No cache-temperature probe was added. `artifacts/p1-final-results.json` and `.md` retain input hashes and all endpoint statuses, schedules, dataset counts, cleanup/restoration and continuity records.

Final restoration verification completed at **17:23:34 UTC** and passed with no issues. All 14 services retained their original image IDs and were running, unpaused and healthy under their configured health checks. The six application-role processes matched all 452 source files byte for byte; source and effective-configuration comparisons against the original baseline showed no changed paths. All six production configuration/cache probes passed with debug off, AI disabled, no external AI provider configured, private cache permissions, and configuration/routes cached.

Verified HTTPS readiness returned **200**; the diagnostic status path returned **404** without exposing FPM status. The private status listener on port 9001 was closed and trace bootstrap/output files were absent. App and Nginx were recreated after the load sequence to restore the final AI-off runtime. The continuity claim above covers the warm-up and two repeats only, not this later recreation. Evidence: `artifacts/p1-final-restoration.json`. Successful restoration does not override failed performance or establish production readiness.

The first instrumented run is preserved despite its additional comparison defect. The private FPM status listener also made its status path recognizable on the main FPM pool. A dedicated Nginx denial was added; the initial reload launcher failed with a WSL service timeout, and the successful reload occurred at 16:25:40 UTC after the HTTP phase began. Approximately 300 timed client starts precede and 180 follow that wall-clock point; clock steps prevent claiming a precise per-request causal boundary. No deployment occurred. The defect was corrected before the frozen-instrumentation matrix. Even those later comparisons remain subject to history growth and host-activity variation.

Public status denial was then proved: `/__p1_status` returned sanitized 400 `MALFORMED_REQUEST`, contained no FPM status payload, and had no upstream timing; the private loopback listener remained available in all 10 proof samples. The application's existing error mapping converts the internal 404 denial to 400. Evidence is `artifacts/p1-status-ingress-proof.json`, with repeated proof directories for later candidates. The final effective-runtime snapshot confirms removal of the diagnostic status path/listener before the clean warm-up.

Observers were visible in resource accounting. Stable dynamic8's app observer consumed 6.201 CPU-seconds over 180 seconds; static4's consumed 6.435 seconds and static8's 7.057 seconds. That excludes the private status process and Docker daemon/coarse stats collection. Exact-window PG observer query elapsed fractions are reported above; they are not CPU percentages. Final trace-write/finalization residual p95 was 0.969 ms in stable dynamic8, 0.587 ms in static4 and 0.899 ms in static8; this does not measure all in-process tracing overhead. These costs and unrelated host work prevent treating diagnostic timing as clean acceptance.

The first sampler lacked an absolute monotonic epoch; its historical window selection remains less precise. Later samplers and PHP traces add absolute monotonic timestamps. Verified same kernel boot and matching monotonic namespace offsets, plus a clock sandwich, support subsequent exact-window joins. They do not imply zero transport time. Wall clock discontinuities remain documented rather than silently discarded.

## 8. Full regression gate

**B1–B8 full regression has NOT been rerun for B8-P1 because strict performance acceptance has not passed.** This follows the requested order; prior checks are not presented as a fresh post-remediation pass.

The prior B8 gate remains historical evidence: 835 Unit/Feature tests with 8,653 assertions and nine architecture tests with 38,799 assertions, totaling **844 PHP tests and 47,452 assertions**; 17 Python tests; Pint across 525 files; PHPStan/Larastan level 10; Composer validation, installation, platform checks and audit; migrations and the exact B7-to-B8 upgrade; pinned Docker builds; production workflow smoke checks; document, commercial, project and AI regressions; encrypted restore drill; source/image security scans; and final AI-off probes. That full gate exited 1 because the strict burst failed. Restore and scan success did not override performance failure.

P1 isolated diagnostic-helper syntax/semantic/privacy/clock checks passed, and each completed original performance driver retained its queue/isolation/retry and HTTP race checks. Those bounded checks are not substitutes for the complete gate.

Both final strict repeats failed. Accordingly, performance work stops, the full B1–B8 gate is not rerun, and no commit is created. Any future remediation would still require unchanged strict acceptance followed by the entire regression gate before release approval.

## 9. Git and release state

Approved baseline HEAD: `96445baded70ddd2e4d8b8617793c78a4a3e1816`.

Baseline parent: `bf738302657f8da1bca911058186a24e87b7ea37`.

Branch: `main`. HEAD and its parent remain the values above: **zero commits since the approved baseline**, 35 modified tracked paths, 67 untracked paths and zero staged paths at the recorded final status check. The whitespace/diff check exited 0. Evidence: `artifacts/p1-final-git.json`. The existing B8 implementation remains uncommitted; the working tree is not clean. The P1 report and related documentation preserve failed evidence rather than claiming a release.

All 452 application/configuration/runtime source files compared with the original P1 baseline were unchanged. The 461-file maps frozen for the clean final sequence also remained identical across all three runs; these inventories have different scopes and are not interchangeable counts. The static-pool experiments were temporary ignored overlays and were removed. No application, authorization, database schema or production runtime optimization is retained. Final runtime/image/config identity and diagnostic removal are recorded separately from these source comparisons.

## 10. Remaining blockers and next decisions

The immediate blocker is the unchanged strict synchronized latency target. Both static candidates, the clean warm-up and both final strict repeats failed. No production optimization is retained and no additional performance work is authorized by this report. Remaining options, in priority order, are:

1. Reproduce the exact unchanged workload on a commissioned target environment with verified CPU topology/allocation, storage latency, resource limits and host contention. The local SMT-sibling result does not establish the capability of the user's two-vCPU VPS, and different hardware is not guaranteed to meet the target.
2. Design and measure narrow connection/identity improvements: reuse only freshly transaction-locked identity/session records through shared validation while preserving all checks, or separately test persistent PostgreSQL connections with explicit rollback/reset, credential/role/search-path handling, cross-user state isolation, reconnect/recycle and connection-count tests. Compare each change against the exact same strict workload; neither candidate is currently implemented or proven.
3. Investigate the secondary query findings: a narrowly targeted audit ordering index and compatible reporting aggregate consolidation. Preserve paging bounds, authorization, repeatable-read consistency, cohort/status rules and currency separation. Isolated EXPLAIN gains alone do not establish end-to-end acceptance.

Any future change must retain CSRF, session revocation, identity and business locks, durability, audit, document controls and AI safety. Staggering arrivals, relaxing thresholds or dropping correctness checks cannot resolve this gate.

Even a future latency pass does not make HOLOUL production ready. The existing B8 launch blockers remain: actual supported/patched VPS commissioning and resource headroom; single-host failure planning; private PostgreSQL/Redis/TLS/role/connection commissioning; private versioned object storage and inspection isolation; independent workers/scheduler/drain/reconciliation/alerts; production SMTP and sender DNS; public domain/TLS/proxy controls; secret injection/rotation and historical key escrow; durable monitoring and on-call ownership; encrypted offsite backup/retention/alerts; production provider restoration/PITR and approved RPO/RTO; migration timing/lock/cutover evidence; scanned approved release and deployed verification; privacy/retention/deletion/location/supplier approvals; incident/recovery ownership and exercises. Paid external AI remains uncommissioned and requires its separate approvals before activation.

User-provided launch estimate remains 150 customers, 100 projects and 20 concurrent users on an Ubuntu VPS with 2 CPU, 8 GB RAM and 1 TB disk. No actual VPS production commissioning or deployment has been performed in this work.

B8 PERFORMANCE REMEDIATION FAILED — B8 REMAINS BLOCKED
