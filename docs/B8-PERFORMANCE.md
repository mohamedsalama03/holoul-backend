# B8 performance evidence and unresolved acceptance

The subsequent [B8-P1 investigation](B8-P1-PERFORMANCE.md) preserved the exact
original workload, profiled its request stages and compared dynamic8, static4
and static8. Both static candidates worsened latency and were removed. Two
clean strict repeats after a complete warm-up still failed at p95
**662.590 / 699.075 ms**, with p99 **909.720 / 929.308 ms** and zero unexpected
errors. No performance optimization or B8 commit was retained. The evidence
below records the earlier B8 rounds and is not a substitute for this follow-up.

## Status and preserved runs

**The local performance gate has failed. B8 performance acceptance is not complete.** The final strict run completed 480 authenticated HTTPS requests with an observed peak of 20 in flight, zero unexpected responses and passing state-safety races, but p95 was 576.348 ms against the unchanged <300-ms target. Three earlier burst attempts also failed. No B8 acceptance or commit is claimed while this blocker remains. Passing queue, query and correctness checks does not override this result. Further staggered-arrival diagnostics cannot substitute for the default simultaneous-wave gate.

The results below are from 2026-09-21. Preserved artifact directories prevent later diagnostic runs from silently replacing these observations:

| Run | Runtime image SHA-256 | Preserved artifacts |
| --- | --- | --- |
| Final strict run with compiled configuration/routes | `ba964a709c7faae3e0b341b16e79d006c8bc7b38dbf5a12128e14aa3d120a2a4` | `artifacts/b8-final-burst-latency-failed/` |
| Original dynamic PHP-FPM pool | `95b93479a116192e9e83de3a0e56a2c48751e55d7ab7dfb3013a604ea3a85cfb` | `artifacts/b8-performance-latency-failed/` |
| Static eight-worker candidate, rejected | `de6d07e5a5aa4d754a0f8498f5d60575bfd071b7f5b7fbc4e710b6ae9a4ce44d` | `artifacts/b8-warm-pool-latency-failed/` |
| Compiled configuration/routes with original dynamic pool | `0f5c0e9e9446f8b4304415bc58da8a8370bda0ec67ee199bd0530b53c0635f56` | `artifacts/b8-cached-burst-latency-failed/` |

The main query, queue and resource results below use the **final strict run** in `artifacts/b8-final-burst-latency-failed/`, with its corresponding summary copied into that preserved folder. Its SHA-256 values are `6c66ccb3403589ebebe8f1fe3bddcd7ef84127ec548ff94f78111fd7b69b1532` (`b8-query-profile.json`), `81c1f1908d35d3f85f38d4dc673394ae6b425faac48df11cee78146636339929` (`b8-queue-capacity.json`) and `3395162c14de5cf52b79e831c8d703f6fea8bcc76b873f6848db8bb5dbb64626` (`b8-http-load.json`). Earlier provisional SMTP/profile figures are superseded. The three earlier HTTP artifact hashes, in chronological order, are `756c2d41f5091ede52ec1508ccec5de939d6d8c71483c93dd673fb4c95022546`, `7c4d2544f77009c1330b2c955a9f36076dae85d2c35a4a69cf8e204b1a8afd40` and `b21ea1f806c67bcb43895eed2cb58d89fc2fc61d2afd4a13fd42479619278161`.

## Environment, fixture and safeguards

The requested target is 150 customers, 100 projects and 20 concurrent users on a 2-CPU, 8-GB-RAM, 1-TB-disk VPS. The local Docker host reports eight CPUs, 8,176,410,624 bytes of memory and Docker 29.6.1. All 14 main application/dependency containers share CPU affinity `0,1`; the host load generator and Docker daemon run outside it. This bounds their execution to two logical CPUs but does not reproduce a particular VPS processor, kernel, network, storage IOPS or disk capacity. No 1-TB dataset was tested.

`scripts/verify-performance.py` orchestrates production-image fixture creation, queue isolation/recovery, PostgreSQL profiling, HTTPS load, telemetry and cleanup. Its fixture requires the explicit local-verification profile, localhost, runtime database identity, sandbox SMTP, production environment without debug or PHPUnit, and an explicit invocation marker. Fixtures invoke owner actions and retain immutable history. Temporary credential manifests have restricted permissions and are removed after successful cleanup. The preserved final-run cleanup records 152 disabled fixture identities with immutable history retained; container restoration passed.

Each run adds 150 customers, two staff identities, 100 converted projects with accepted proposals, and 1,000 requests: 100 converted source requests, 600 other submitted requests and 300 drafts. Existing synthetic history remains. The final-run manifest contains **8,061 total requests, 805 projects, 1,278 customers and 41,093 audit events** at fixture-manifest capture. By subsequent profiling, audit-producing queue work had raised the observed audit scan to 41,713 rows; requests comprised 5,749 non-drafts and 2,312 drafts, with 5,770 revisions. The database occupied 84,121,279 bytes. The original run had 4,053 requests, 404 projects and 668 customers; repeated runs add retained history, so the candidate comparison is not a reset-database controlled experiment.

The heavy queue fixture uses 25 valid, customer-visible PDFs, each 500 pages and 78,264 bytes, plus eight deterministic sandbox AI runs. It exercises real object upload/version verification, scanning and structural inspection. It does not establish 10-MiB upload performance or external AI-provider capacity.

## HTTPS load and failed latency target

`performance-http.py` authenticates 18 customers and two staff with MFA, verifies secure cookies/no-store behavior, denies customer access to staff reports/audit and checks bounded filters before timing. The default gate issues 24 waves of 20 simultaneous requests at five-second intervals. Requests cover customer/admin lists, request detail/search, project reads, notification polling, dashboard and audit reads, plus 16 draft submissions. The wave completes before the next scheduled wave is dispatched, so this is a bounded closed workload rather than maximum-capacity measurement.

Separate two-client probes verify exact-key submission replay and competing keys against one ETag. Login/MFA, setup, race probes, uploads and external-provider work are excluded from HTTP latency percentiles. Timing includes HTTPS, session authorization and response reading. Acceptance requires zero unexpected responses, overall **and per-endpoint p95 < 300 ms and p99 < 1,000 ms**. Small endpoint samples limit tail inference; the gate is nevertheless evaluated as written.

| Run | Requests / unexpected errors | Duration | Throughput | p50 | p95 | p99 | Result |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| **Final strict run** | **480 / 0** | **115.502 s** | **4.156/s** | **290.203 ms** | **576.348 ms** | **758.399 ms** | **Failed latency** |
| Original dynamic pool | 480 / 0 | 115.563 s | 4.154/s | 308.838 ms | 559.918 ms | 682.572 ms | Failed latency |
| Static eight workers | 480 / 0 | 115.482 s | 4.157/s | 354.310 ms | 609.631 ms | 713.547 ms | Failed latency; candidate rejected |
| Compiled configuration/routes, dynamic pool | 480 / 0 | 115.679 s | 4.149/s | 308.726 ms | 631.887 ms | 923.249 ms | Failed latency |

The final arrival schedule was valid, with 480 observed starts, zero missed slots, p95 start lag 12.288 ms and maximum 19.637 ms; observed concurrency reached all 20 requests. Every endpoint failed p95 in the final run and the three earlier runs. Final endpoint p95 ranged from 441.509 ms for admin projects to 907.777 ms for audit; dashboard p95 was 872.677 ms and request submission 614.796 ms. Final overall and all endpoint p99 values remained below one second, which does not compensate for failing p95. In the earlier compiled-cache candidate, the 12 audit requests reached p95/p99 1,072.388 ms and the 12 dashboard requests reached 1,014.385 ms; these also failed p99. All four runs completed their state-safety races with exact replay and conflict behavior. Zero errors is a correctness observation, not latency acceptance.

Increasing PHP-FPM's warm workers did not meet the target and was reverted to the original dynamic pool. Production configuration/route compilation remains for bounded bootstrap work and private ephemeral configuration handling; the authenticated-load measurements do **not** demonstrate that it improves this workload. Separate health probes improved the `/health/live` p95 observation from 107.197 to 71.209 ms after both caches, while `/health/ready` remained approximately 261–342 ms across candidates. Readiness has no session middleware/writes, so session WAL flushes alone cannot explain every observed delay.

### Additional staggered-arrival diagnostic

A separate diagnostic on the same compiled-cache runtime image `0f5c0e9e9446f8b4304415bc58da8a8370bda0ec67ee199bd0530b53c0635f56` passed its unchanged latency/error checks. Evidence is preserved in `artifacts/b8-staggered-diagnostic-passed/`; `b8-http-load.json` has SHA-256 `da0a47f254801865be5d33193fd4a29cb84164fc1cb5f3dfda0c563a3f59992b`.

This workload maintained 20 authenticated active users, each scheduled once every five seconds with fixed phases 250 ms apart and at most one outstanding request per user. It offered four requests/second over 120 seconds; it did not send 20 requests simultaneously. The recorded maximum concurrent requests was **one**, despite the configured ceiling of 20. All 480 starts met the arrival schedule: zero missed slots against the 250-ms threshold, p95 start lag 0.116 ms and maximum start lag 2.716 ms. Cold TLS behavior, endpoint mix, authorization and latency/error targets were the same as the burst test.

The measured duration was **119.840 seconds**, achieved throughput **4.005 requests/second**, and unexpected responses **zero**. Overall latency was **p50 47.385 ms, p95 86.406 ms and p99 107.434 ms**; every endpoint met the diagnostic thresholds, and the separate replay/conflict probes passed. This fresh fixture added the same 150 customers, 100 projects and 1,000 requests. Its accumulated manifest contained **1,118 customers, 704 projects, 7,053 requests and 35,806 audit events**, before subsequent workload-generated events.

This observation supports adequate latency under the measured smoothly phased arrival pattern. It does not demonstrate 20 simultaneous in-flight requests, maximum capacity or an approved production traffic model. The assumption that real users arrive with these phases has **not been approved**, and this diagnostic does **not** replace or pass the failed strict burst acceptance gate. The subsequent final strict run retained default simultaneous-wave gating and failed latency; the diagnostic remains separate.

### Earlier handler/edge diagnosis and PostgreSQL observation

Safe correlation-ID matching for the original run found 468 successful GETs with edge p95 511 ms, handler p95 108 ms and p95 of the per-request edge-minus-handler difference 438 ms. The compiled-cache candidate's corresponding 468 GETs had edge p95 558 ms, handler p95 112 ms, SQL p95 78 ms and residual p95 463 ms. These log windows include setup/guard reads and are not the same population as all 480 timed HTTP requests. The residual includes FPM waiting, framework bootstrap and other edge work; it is **not independently measured queue time**. Percentiles are computed for each distribution, not subtracted from each other.

A single read-only PostgreSQL diagnostic sampled the compiled-cache candidate every 50 ms for 180.006 seconds, producing 3,601 observations. The invocation spanned 11:38:06–11:41:18 UTC; HTTP-stage telemetry spanned 11:38:02–11:40:17 UTC. Command startup preceded the measured sampling loop, so counters cover surrounding setup/cleanup and are not attributed to precisely the 480 timed requests. The artifact is preserved as `b8-cached-burst-latency-failed/b8-pg-waits-cached-candidate.json`, SHA-256 `650f509af05a1df59c9e8314d2c5302a8f8c2782448227c04c791b164f95be7e`.

The sampler selected only aggregate state/wait counts for runtime-role backends, never SQL text, session identifiers or private business rows. It observed 57 active `IO/WalSync` backend-samples, eight `LWLock/WALWrite`, three `Lock/transactionid`, 166 active samples without a reported wait, and 280 idle-in-transaction samples. There were zero reported deadlocks and zero database block reads. The largest sampled count in either transaction-lock or WALSync wait was one backend. These are observation counts, not durations; short waits can occur between samples.

Counter deltas showed 492 identity-session updates, 509 framework-session updates, and 1,630 client-backend WAL writes/1,629 fsyncs. They include setup/cleanup and other concurrent local activity; WAL/IO counters are cluster-wide and publication can lag. `fsync`, `full_page_writes` and `synchronous_commit` remained on. Both IO timing settings were off, so zero timing fields cannot establish zero IO wait or quantify flush cost. The observer itself used one connection, added SELECT/transaction work, and recorded 6.945 seconds aggregate query time (median 1.611 ms, p95 3.946 ms, maximum 51.639 ms); it may affect scheduling.

Evidence supports real WAL activity and little sampled transaction-lock contention, but does not establish WAL flushes or SQL plans as the primary burst bottleneck. Most measured tail time lies outside the instrumented handler. A code review identified two potentially removable identity/session SELECTs performed again after the same models have just been freshly locked; no authorization-lock, transaction-lifetime, session-validation or activity-write behavior was changed for this diagnosis.

## PostgreSQL profile and document batching

The final-run profiler listens to owner-query SQL, measures one warm CLI invocation, then runs `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` per distinct shape. It exports only safe plans, counts and timings. SQL text/bindings/private source content are excluded. EXPLAIN is a second execution; warm-cache plans are not the original invocation timing or an HTTP percentile distribution. Report `page_rows` counts top-level sections rather than underlying business records. Requested limits do not prove that many matching rows exist.

| Workflow | SQL queries | Returned rows / scope | CLI elapsed |
| --- | ---: | --- | ---: |
| Customer request list, limit 25 | 1 | 7 requests | 2.055 ms |
| Admin request list, 25 / 100 | 1 / 1 | 25 / 100 | 7.240 / 15.702 ms |
| Admin project list, 25 / 100 | 1 / 1 | 25 / 100 | 1.983 / 7.229 ms |
| Proposal history, limit 25 | 2 | One accepted version | 3.017 ms |
| Project documents, limits 1 / 25 / 100 | 8 / 8 / 8 | 1 / 25 / 25 | 11.723 / 7.509 / 7.556 ms |
| Notification inbox, limit 25 | 3 | 10 notifications | 2.846 ms |
| Audit timeline, 25 / 100 | 1 / 1 | 25 / 100 | 30.966 / 29.112 ms |
| Request search / exact reference | 1 / 1 | 25 / 1 | 36.148 / 0.732 ms |
| Request / audit second cursor page | 1 / 1 | 25 / 25 | 5.629 / 34.335 ms |
| Dashboard | 15 | Four aggregate sections | 42.750 ms |
| Request / project / customer report | 6 / 4 / 2 | Aggregate sections | 25.993 / 3.039 / 1.520 ms |

Document profiling now projects actor permissions once and acquires current identity/parent inside its transaction. Its eight queries include those authorization operations. The earlier 114-query CLI observation counted fixture actor projection per permission and is superseded. The production HTTP regression changed 1/3/25 cleared-attachment pages from **16/18/40 SELECTs to 16/16/16** after the Documents-owned bulk read; mixed quarantine states need at most two metadata queries. Exact-owner and visibility guards remain. See [B8-DOCUMENT-READS.md](B8-DOCUMENT-READS.md). Only 25 attachments exist at requested limit 100, and only one proposal version was profiled; larger histories require separate evidence.

### Index review

The snapshot contains 219 indexes and zero exact duplicate candidates under key/operator/order/expression/predicate comparison. This does not prove optimality, and a short run's zero `idx_scan` is not grounds to remove integrity or infrequently used security indexes. Customer request lists use `intake_customer_created_idx`; reference lookup uses `project_requests_reference_key`; inbox reads use `notifications_inbox`. No growing per-record SQL loop appears in the reviewed bounded lists.

Sequential scans here are not by themselves defects:

- Admin request listing scans 8,061 rows and retains 5,749 non-drafts. One measured scan node took 0.875 ms; its complete plan took 2.372 ms.
- The nonselective search term `portal` matches 5,688 of 5,770 revisions. The existing matching GIN index need not beat a scan; EXPLAIN took 32.097 ms with 304 hit blocks and no reads. Representative larger data, selective terms and Arabic queries remain necessary before changing search.
- Dashboard aggregates intentionally read their selected cohort. The largest individual plan took 9.655 ms; these figures do not justify a materialized aggregate or report cache.
- The unfiltered audit timeline scans approximately 41,713 events, touches 839 hit blocks/no read blocks and sorts. Full plans measured 30.939 ms for limit 25, 28.809 ms for limit 100 and 30.332 ms for the next cursor page. The strongest future candidate is `audit_events(occurred_at DESC, id DESC)` for the exact order/seek path. Measure retained volume/filter mixes, before/after write cost and HTTP latency before adding it; existing actor/time and subject/time indexes serve different prefixes.

No index or report-result cache was added from these observations. Production framework configuration/route compilation is separate from business result caching and retains fresh authorization.

## Queue isolation, age and recovery

The final run used one worker each for default, documents, AI and notifications, plus the singleton scheduler. While only heavy workers were paused for 8.709 seconds, all eight recovery-mail and eight notification operations succeeded as all 33 heavy operations remained pending with no attempts. Initial light p95 completion was 0.528 seconds for recovery mail and 0.091 seconds for notifications.

After resume, all 25 documents became Available and eight sandbox AI runs/attempts succeeded on their first attempt. The observed drain interval was 6.704 seconds, or 4.922 combined heavy jobs/second. Fresh live-drain recovery mail completed in 0.128346 seconds and a notification in 0.094845 seconds, both first attempt and within the 15-second bound. Both were created after heavy workers resumed, after the first document completion, and before the first AI completion. Both completed before the final document and AI completions; this establishes overlap with the heavy-drain interval, not exact CPU simultaneity. This demonstrates independent light-work progress during actual heavy work, not unlimited parallel capacity.

| Batch | Successful jobs | Completion p50 / p95 | First creation to final completion | Batch jobs/s |
| --- | ---: | ---: | ---: | ---: |
| Documents | 25 | 10.935 / 13.332 s | 14.668 s | 1.704 |
| Sandbox AI | 8 | 8.190 / 8.334 s | 8.798 s | 0.909 |
| Initial recovery mail | 8 | 0.510 / 0.528 s | 0.970 s | 8.250 |
| Initial notifications | 8 | 0.071 / 0.091 s | 0.533 s | 14.996 |

Heavy completion intervals include fixture creation and the intentional pause; these are not normal unpaused latency or sustained per-pool throughput. Completion percentiles come from correlated PostgreSQL ledger timestamps, not Redis.

The authoritative paused PostgreSQL snapshot recorded 25 pending/due document jobs with oldest pending creation age 4.629481 seconds, and eight pending/due AI jobs with oldest pending age 3.476243 seconds. The separate correlated pause/drain samples observed maximum document pending creation age 11.497 seconds and running creation age 11.540 seconds; AI pending/running creation ages reached 7.906/7.962 seconds in that distinct sample set. Initial recovery-mail observations reached 0.431 seconds pending creation age and 0.481 seconds running creation age. These scopes have different observation times and must not be treated as one continuous trace. Running creation age includes time before claim and is not execution age. Exact oldest ready-age/claim-age fields were not recorded and remain unavailable rather than inferred.

Redis transport independently showed ready depths 25/8 during the pause. After drain, sampled PostgreSQL pending/due/running counts and Redis ready/reserved/delayed depths were zero. During 16 surrounding HTTP samples, all four queues had no observed PostgreSQL pending/due/running backlog and no Redis ready/reserved/delayed depth. Unobserved age fields are null, not a measured zero-wait guarantee.

The corrected SMTP outage probe passed: a known pre-DATA notification failure retried on its original ledger after backoff, while the B2 recovery attempt remained terminal uncertain and a fresh recovery request succeeded after restoration. Observed recovery was 36.362 seconds. Notification creation-to-completion duration was 48.732 seconds across two attempts; sampled pending creation age reached 46.363 seconds during intentional outage/backoff; running creation age reached 48.729 seconds. This is recovery evidence, not normal notification latency. The earlier harness failure that incorrectly expected B2 to blindly resend remains an earlier failed check, not a behavior change.

Retain the tested initial one-worker-per-pool configuration and measure queue age, service time, CPU, scanner memory, connections and provider limits before raising concurrency. Short ledger-derived completed-job busy fractions were 3.15% default, 34.67% documents, 6.17% AI and 9.44% notifications; their intervals include pauses/fixture work and are not steady-state utilization estimates.

## Resource observations and remaining limits

The final run's 16 surrounding HTTP snapshots reported at most seven PostgreSQL connections of the configured 100, two active connections and zero sampled waiting locks/connections. Sparse sampling can miss bursts, as the earlier 50-ms diagnostic demonstrates. Redis ping observations had p95 3 ms, maximum memory 1,709,096 bytes and no evictions. Maximum sampled combined container memory was approximately 1.900 GB; scanner memory reached approximately 1.018 GB. Sampled app/PostgreSQL CPU maxima were 50.85%/51.63%, respectively. Docker observations at roughly 8–11-second intervals are not continuous peak or saturation measurements, and independently observed maxima cannot be added into a simultaneous utilization claim.

The current blocking acceptance item is authenticated HTTPS latency under the default simultaneous-wave workload. Static warm workers and configuration/route compilation did not resolve it. No authorization checks, transaction locks, durability settings or SLO thresholds were relaxed. The passed staggered diagnostic and independent quality/restore checks or security scans do not turn these failed burst results into a passed B8 release. The final measured image is `ba964a709c7faae3e0b341b16e79d006c8bc7b38dbf5a12128e14aa3d120a2a4`. The gate completed with observed exit code 1 because strict burst performance remains blocked. Independent restore/security scans and the final six AI-disabled/readiness checks completed with their own evidence; these do not override the latency failure. B8 acceptance and a B8 commit remain blocked by the strict-load result. Release evidence must preserve and explicitly resolve or retain this blocker.
