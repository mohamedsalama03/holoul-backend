# HOLOUL F1-E2 — Local Integration Stability

**LOCAL INTEGRATION STABLE — FRONTEND ACCEPTANCE MAY RESUME.** This is a bounded local-environment result. It does not certify F1-R2 frontend acceptance, G1, production readiness, or target-VPS performance.

Production Next.js 16.3.5 was built successfully and started on Windows **127.0.0.1:3001**. PID **77160** remained alive, without restart or port loss, throughout the probes. The real browser origin remains **https://localhost:8443**.

## Preserved scope

Backend HEAD remains `96445baded70ddd2e4d8b8617793c78a4a3e1816`; the earlier G1 candidate/history and blocked report remain intact. Among 730 starting backend files, only `docker/nginx/nginx.conf` and its reviewed source-manifest hash changed. All authentication implementation and inherited race tests are byte-for-byte unchanged. This report is additional documentation; raw evidence is private under `artifacts/f1-e2/`.

All **258 frontend source files** match the F1-E2 starting snapshot; HEAD remains `3420d6c91503376c2039cc2c47ed304157dadaad`. No frontend source, test, timeout, retry setting or contract copy changed. Requested `.next` build output was regenerated; no dependency installation/upgrade occurred. Backend OpenAPI also remains unchanged: SHA-256 `ae8d0a904a0042b80f9adc1224dedf8677794e2d49ca5cdf41daeb5278780be8`.

## 1. Nginx → Next root-cause assessment

The three observations were kept separate:

- **~62.65-second connect failure:** the previous local location inherited Nginx's 60-second connect timeout, forced `Connection: close`, and had no reusable upstream pool. These settings explain stall amplification and connection churn. The initiating historical TCP failure was **not reproduced**; a specific Docker forwarding, Windows scheduling or firewall cause is not established.
- **Three ~11.2-second navigation 499s:** no equivalent document stall or Nginx 499 occurred during the measured window. These historical requests cannot be retrospectively attributed to Laravel. Brief current browser RSC cancellations were independently diagnosed below.
- **4.92-second Laravel login:** recovered original telemetry confirms 4,920 ms, HTTP 200, 10 SQL queries totaling **66 ms**, at 2026-09-27 10:23:38.895 UTC, request `e9537919-ca5d-4630-8286-3501545acbdc`. Most elapsed time was outside SQL execution. Historical FPM/hash/Redis/host timing is insufficient to assign the remaining time to one component.

Configuration behavior is supported by the official [Nginx proxy timeout documentation](https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_connect_timeout) and [upstream keepalive documentation](https://nginx.org/en/docs/http/ngx_http_upstream_module.html#keepalive). These sources do not prove the trigger of the past intermittent fault.

## 2. Docker Desktop gateway evidence

Probes ran **inside the actual Nginx container**. Docker DNS `127.0.0.11` resolved `host.docker.internal` to **192.168.65.254**; the container default route was `172.24.0.1`. Both the DNS name and raw resolved address were exercised. Before correction, **120 fresh IPv4 connections all returned 200**. IPv6 control returned curl exit 6 because no AAAA record was available; the existing Nginx resolver already disables IPv6, so no IPv6-fallback delay was demonstrated.

A two-request upstream probe showed one new TCP connection for the first request and zero for the second, confirming Next supports reuse. Windows Node owned port 3001 exclusively on loopback. No public bind, DNS override, host-file workaround, firewall change, CORS or Origin rewriting was introduced. [Docker's documented host-service route](https://docs.docker.com/desktop/features/networking/) explains the topology.

## 3. Nginx changes retained

| Setting | Local integration policy |
|---|---|
| Upstream | Named `local_admin`, same host/port, dynamic Docker DNS and shared zone |
| Connection reuse | HTTP/1.1; ordinary Connection header cleared, Upgrade preserved; 8 idle connections per worker, 4-second idle lifetime, 100 requests/connection |
| Connect timeout | **2 s**, replacing inherited 60 s |
| Read timeout | **15 s between reads**, replacing 3,600 s; not an overall-response deadline |
| Send timeout | **15 s between writes**, replacing 60 s |
| Retry policy | **Off**; upstream failures are not hidden by automatic retries |
| Diagnostics | Safe route family, upstream address/status, connect/header/response durations and request correlation; no URL/query/body/cookie/credential logging |

Existing Host/Origin/Referer restrictions, forwarded-metadata sanitization, TLS/CSP separation and Laravel FastCGI behavior remain unchanged. These settings are local, not future production capacity decisions. Detailed live error logging retains the existing privacy policy; controlled secret-free fault tests captured detailed errors separately.

An isolated loopback-only Nginx instance used the same timeouts: unreachable TCP returned 504 after **2.134 s**; an accepting but silent server returned 504 after **15.994 s**. Neither the main ingress nor production Next was stopped/repointed. Intentional fault responses are excluded from the acceptance window. Temporary listeners/processes were stopped afterward.

## 4. Production Next stability

`next build` exited 0; acceptance diagnostics used `next start -p 3001 -H 127.0.0.1`, never `next dev`. A backend-owned diagnostic preload sampled memory/event-loop delay without modifying frontend code or responses.

Window: **2026-09-27 10:56:20–11:07:08 UTC**, including activity and repeated 10/30/45-second idle periods. One PID: **77160**. Maximum event-loop delay **215.7 ms**; maximum interval p99 **83.8 ms**. No multi-second event-loop stall occurred. RSS warmed from **103.4 MiB** to **197.3 MiB**, peak **209.4 MiB**, then fell to approximately 53 MiB in later idle samples. This finite observation shows warm-up and collection, not proof against a long-term leak.

## 5. Host-load correlation

The shared Windows host has 8 logical processors and approximately 16 GiB RAM; Docker reports about 7.6 GiB for its VM. CPU reached **100%**; available host memory fell to **401.5 MiB**. Processor-queue, memory, Docker utilization and Linux pressure/cgroup samples are retained. These establish host contention, but cannot prove it caused the earlier 4.92-second request.

During the stability window, sampled Docker CPU peaks were app **71.74%**, PostgreSQL **11.36%**, Redis **5.60%**, Nginx **21.00%**. Memory peaks were respectively 17.43%, 47.45%, 11.31%, 19.97% of their container limits. There were **76 FPM kernel-listen-backlog samples, maximum zero**, and no sampled active PostgreSQL waits. Kernel backlog is not the entire FPM internal queue; sampling can miss short waits. Existing upstream/Laravel timings do not isolate FPM scheduling/bootstrap exactly. No public FPM status endpoint was enabled.

The six new login samples include nearby Windows CPU/memory readings in `login-results.json`. That small sample and absent historical host traces do not establish a causal correlation for the old outlier. No other application was stopped or resource/security setting weakened.

## 6. Dedicated stability results

| Probe | Result |
|---|---|
| Windows: direct Next and HTTPS edge, `/admin/login` and `/admin` | **640/640 HTTP 200**; median 12.8 ms, max 184.5 ms |
| Inside Nginx: upstream pages/asset, edge API and CSRF | **656/656 expected 200/204**; max response 222.4 ms, max connect 63.2 ms |
| Main Nginx acceptance window | **2,226 access events; zero 499, 502 or 503** |
| Next process/port | One continuous PID, no loss or restart |

The probes used no response mocks or automatic retries and consumed no login budget. Auth measurements were separate. Intentional later Host/Origin/body-limit negatives and isolated timeout controls are not mixed into these counts.

Additional Chrome smoke completed **72 document navigations** across twelve three-tab groups. Its strict diagnostic flag remained **false** because it counted 12 aborted fetches; the result was not rewritten. A separate instrumented diagnostic completed 18 additional document navigations and identified **RSC fetch** cancellations after HTTP 200, lasting **43–88 ms**, not failed document navigations or 11-second upstream stalls. Matching Nginx requests remained 200, with no 499. This diagnostic is not a replacement for the owner's F1-R2 suite.

## 7. Authentication latency

Six real logins used the existing synthetic customer, spaced **16 seconds** apart, with explicit CSRF bootstrap and successful logout. Rate limits were neither cleared nor bypassed. Credentials/cookies were kept in memory and not printed. No fixture credentials or real administrator account were changed.

| Sample | Windows HTTP client ms | Laravel ms | SQL ms | Nginx upstream-header ms |
|---|---|---|---|---|
|1|660.7|601|46|609.0|
|2|827.6|705|35|711.0|
|3|1062.9|932|35|960.0|
|4|965.2|889|17|895.0|
|5|650.2|597|39|601.0|
|6|747.5|684|23|688.0|

Client p50 **787.5 ms**, max **1,062.9 ms**. Laravel p50 **694.5 ms**, max **932 ms**. None of these requests materially exceeded the existing 10-second E2E action budget; a complete browser action is still for F1-R2 to verify.

Read-only component probes used the actual unchanged **Argon2id / 65,536 KiB / time cost 4 / one thread** policy. Hash verification: **172.9–218.3 ms**; PostgreSQL round trip: **0.39–0.67 ms**; Redis ping: **0.09–2.41 ms**, read-only Lua: **0.07–1.31 ms**. A 550 ms sleep took **550.064–550.089 ms**. These microbenchmarks do not decompose the historical login.

The security throttle intentionally includes a 450 ms base plus 100 ms per login attempt and jitter. It explains much of the ordinary 0.6–0.9-second range and was **not reduced**. No password, rate-limit, session or authentication optimization was made from one slow sample. The unmeasured part of the old 4.92-second request remains an attribution limit.

## 8. Preserved security and 9. regressions

- Nginx configuration validation passed. Same-origin ingress: **40 checks / 13 assets**, including Host/Origin/spoofed forwarding/CSRF/cookies/CSP boundaries.
- Unit + Architecture: **153 tests / 42766 assertions**, zero failures/errors/skips; nested PostgreSQL concurrency classification remains covered.
- Focused Identity HTTP/session lifecycle/races/pruning/HTTP foundation/fixture safety: **72 tests / 1279 assertions**, zero failures/errors/skips.
- Route/contract checks: **2 tests / 1280 assertions**, passed separately after connecting the required read-only docs fixture. The initial 74-test run had **one setup error**, missing `docs/openapi.json`; its unmodified JUnit/log remains preserved. No passing security test was weakened or rerun to hide it.
- Pint: **570 files** clean. PHPStan/Larastan **level 10**, Composer strict validation/platform/locked audit passed; no advisories.
- Both pinned Docker targets built; FPM validation and production-image exclusion of PHPUnit/local fixtures passed. The live app/worker images were not changed; Nginx configuration is the existing bind-mounted local infrastructure.
- Fresh migrations were confined to dedicated `holoul_test` with the migrator identity. Live local schema/data were not reset or migrated.

No Sanctum, CSRF, Host/Origin policy, MFA, session rotation/race behavior, authorization, hash strength or rate limit changed. The full frontend fingerprint and all backend security source files match the starting state. The only contract-manifest update acknowledges the reviewed Nginx source hash; API contents are identical.

## 10. Handoff and remaining limits

Production Next remains available on **127.0.0.1:3001**, with same-origin ingress on **8443**. The synthetic fixture mechanism is ready. The **frontend owner may now run the existing F1-R2 acceptance sequence**, preserving its timeouts and retry settings. This task did not edit or execute that acceptance sequence on their behalf.

Historical gateway-trigger and slow-login attribution remain incomplete because those per-component traces were not captured at the time. Current bounded, loaded/idle measurements support resuming frontend acceptance, not guaranteeing every future shared-host load condition. If acceptance fails, use request correlation plus the newly retained safe connect/header/response times to distinguish gateway connection, Next response and Laravel processing failures.

G1/website work stays stopped. Production targets and failed B8/P1 evidence remain intact; no target-VPS performance certification or Production Ready claim is made.

Evidence: `artifacts/f1-e2/` contains baseline fingerprints, task-only diff, build/runtime logs, DNS/TCP probes, load traces, real login/component timings, preserved diagnostic setup failures, timeout controls, ingress/JUnit results and source-preservation proof. The first monitor launch had a path error; the first isolated Nginx diagnostic lacked writable temporary paths; a diagnostic copy into the read-only root filesystem was rejected. All were setup issues corrected before their corresponding measurements, without relaxing container isolation.

**F1-E2 LOCAL INTEGRATION STABLE — FRONTEND ACCEPTANCE MAY RESUME**