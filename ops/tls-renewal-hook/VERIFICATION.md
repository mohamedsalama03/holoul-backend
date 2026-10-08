# TLS renewal hook — local verification

Date: **2026-10-08**. Classification: **ready for controlled installation**, not
installed, not deployed, and not production certification.

## Source and scope

- Operational branch: `ops/tls-renewal-hook`, created directly from the frozen
  application commit `8b1ffea5c75dc4adb68f795a73d1c6c513cbbd8c`.
- `release/unified-vps-candidate` remains at that exact commit. No merge, rewrite,
  push, VPS connection, application edit, image build or image publication occurred.
- All changes are new files in `ops/tls-renewal-hook/`: `renew.py`,
  `50-holoul-tls`, `nginx.versioned.conf`, `compose.tls-versioned.yaml`,
  `test_renew.py`, `README.md`, and this verification report.
- The operational commit is reported in the final handoff and its separate
  `IDENTITY.txt`. It is intentionally not embedded as a self-referential hash in
  this committed report. Recover it with `git log -1 --format=%H -- ops/tls-renewal-hook`.
- Frozen OpenAPI SHA-256 remains
  `3c93a09f08ce199cc1c2867ad846a1ca554fd03b6d934070178c5101646df410`.

## Environment actually tested

Local non-production Ubuntu 24.04.3 under WSL, Python 3.12.3, OpenSSL 3.0.13 and
Docker Engine 29.6.1. The pre-existing reviewed Nginx image was used with
`--pull never`:

```text
sha256:893488317a0b87ecb34a07b8255c484e978b2bb640aec2f95555686ec0cb1185
nginx version: nginx/1.30.5
runtime UID:GID = 101:101
```

This is a local image identity, not a newly published registry digest. All tests
used synthetic root/intermediate/leaf certificates in root-only temporary
directories and randomly named disposable containers, bound to loopback ephemeral
ports. Containers were read-only, capability-dropped and resource-limited. No real
production keys, user data, domains' public services, VPS or ACME CA were accessed.

Certbot is not installed on this build host. Certbot 2.9's deploy-hook interface and
dry-run behavior were reviewed against its versioned upstream source; no actual
Certbot renewal or dry-run is claimed. See the primary references and controlled
dry-run instructions in `README.md`.

## Results

**39 distinct tests passed**, aggregated from the final completed test groups and
targeted reruns described below. This is not a claim that every preliminary run
passed or that one uninterrupted invocation ran all 39 successfully.

| Area | Verified result |
|---|---|
| Valid production renewal | New certificate served and trusted for both names; new Nginx worker generation; only captured ingress reloaded |
| Invalid input | Wrong/extra SAN, mismatched key, expired/not-yet-valid/near-expiry leaf, malformed PEM, untrusted/incomplete chain all blocked |
| Filesystem safety | Intended archive boundary, private key modes, root ownership, generated fragment integrity and symlink-lock rejection |
| Ownership after publication | Version files `root:101` / `0440`; version directories `root:101` / `0750`; state `0600` under root-only directory |
| Scope | Other lineage skips without Hook construction or value logging; wrong renewed domains blocked without side effects |
| Concurrency | Concurrent Hook invocation rejected; kernel lock interoperates with external `flock` |
| Partial update | Simulated failure between certificate and key staging never changes the active pointer |
| SIGTERM | Interruption after pointer switch immediately rolls back and confirms old served certificate |
| SIGKILL | Kill after switch, after reload, and after verified-state write; subsequent recovery restores the verified previous version |
| Recovery failure | Journal retained and failure reported; explicit recovery succeeds after fault removal |
| Identity change | Restarted disposable ingress blocks journal recovery; no reload sent to changed instance |
| Idempotency | Repeated renewal, initialization and deliberate rollback do not cause extra reloads or version oscillation |
| Bootstrap | Staging/rollback without Nginx test or reload; final file on disk while Bootstrap is still loaded is blocked |
| Production detection | Reviewed config/include hashes plus live HTTP/TLS checks; legacy final config and unknown config blocked |
| Nginx config failure | Real invalid directive makes `nginx -t` fail; old version restored/tested/reloaded |
| Reload failure | Real invalid reload signal rejected; old version restored and confirmed |
| False reload success | Simulated exit zero without signal is rejected; also tested with identical leaf but changed chain, requiring a new worker generation |
| HTTP-01 | Both names return the synthetic challenge in Bootstrap and production, and continuously throughout renewal |
| Existing TLS rejection | Unknown SNI still rejected by the server after moving certificate directives to HTTP scope |
| Key leakage | Synthetic key bytes absent from subprocess arguments, captured output, both Docker log streams, state and generated config; failed command output suppressed; core dumps disabled |
| Configuration delta | Proposed Nginx file differs only by one HTTP-scope include replacing the four per-server certificate directives |

Additional checks:

- Python AST validation and shell wrapper syntax: **PASS**.
- Merged Compose compared structurally against the frozen base: **exactly one
  change**, ingress `/etc/nginx/nginx.conf` bind source becomes
  `/etc/holoul/nginx/nginx.versioned.conf`. No image, service, port, secret mount,
  routing, network, resource or security configuration change.
- Frozen topology validator passed: **13 runtime + 2 preparatory services**,
  unchanged **2888 MiB** total runtime memory limits and public ports **80/443**.
  These are configuration checks, not new capacity measurements.
- Trivy **0.74.0**, filesystem secret scanner, offline (`--network none`):
  **0 findings**. Scanner image pinned to
  `sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969`.
- No application changes: PHP, database migration, application architecture,
  OpenAPI generation and image vulnerability suites were not rerun. Their frozen
  release evidence is preserved, not replaced by these operational tests.

## Preserved run history

Evidence is local, outside Git, under
`/home/mohamed/.local/share/holoul-freezes/tls-renewal-hook-20261008/`.
Only sanitized logs/reports, never temporary PKI directories, belong in the handoff.

| Evidence | Result and interpretation |
|---|---|
| `group-1.log` | Initial group: 13 passed |
| `group-2.log` | Initial group: 10 run, 3 errors; preserved |
| `group-3.log` | Initial group: 8 run, 1 failure and 1 error; preserved |
| `round-2-group-1.log` | 18 passed in 166.713 s |
| `round-2-group-2.log` | 10 passed in 122.147 s |
| `round-2-group-3.log` | 8 run in 159.519 s, 7 passed; 1 fixture port-readiness error, preserved |
| `extra-security-tests.log` | 4 passed in 19.275 s: near expiry, symlink lock, failed-output privacy, strengthened leakage test |
| `round-3-readiness.log` | Both affected false-success test and valid production renewal passed in 50.124 s |
| `round-3-restarted-ingress.log` | Restarted-ingress recovery test passed with the corrected port-readiness helper |
| `verification-summary.json` | Enumerates all 39 distinct tests, latest successful result and source log; records file/log hashes |
| `static-verification.json` | Structural Compose comparison, topology guard and unchanged release/contract identities |
| `secret-scan.json`, `secret-scan-final.json` | Zero secret findings, including the completed documentation |

Preliminary work also found and corrected an OpenSSL empty-passphrase-input issue
(send a newline to `fd:0`, no passphrase/key in arguments). The initial harness had
a 3-second reload confirmation deadline under parallel Docker load while the
production implementation used 20 seconds; tests now use the same 20 seconds.
An initial WSL launch failed before tests started. Docker can temporarily report
empty or reassigned ephemeral bindings after restarting a disposable fixture; the
harness now waits for bindings instead of indexing an empty list. These were fixed
and relevant cases rerun; no security assertion was removed or bypassed.

## Installation conditions and remaining limits

There is **no unresolved implementation/test failure**. The following are still
required before actual installation or a later production transition:

1. Owner-approved controlled installation; no command in the runbook has been run
   on the VPS. Verify exact mounted config/include hashes, local port bindings,
   container identity, root-owned directory layout, trusted CA bundle and real
   lineage files there. Unknown drift blocks the hook.
2. Privately confirm Certbot uses the existing HTTP-01 webroot with no installer
   or other hook that reloads/stops services; review saved/global/directory hooks.
   The provided dry-run procedure overrides saved hooks and disables directory
   hooks until this hook is deliberately selected.
3. Adopt/validate the existing certificate while Bootstrap remains active. The
   hook can be installed in Bootstrap and stage renewed versions safely, but it
   deliberately does not enable product routes or switch Nginx configuration.
4. A separately approved ingress-only transition must adopt the new immutable-path
   include/config mount. The old flat-file final configuration cannot safely
   activate two PEM files atomically and is explicitly blocked. Do not combine
   the proposed override with the ACME Bootstrap override.
5. Execute the documented live renewal dry-run after approval. Using
   `--run-deploy-hooks` is a real invocation against the current lineage, not an
   inert simulation. Certbot process success alone does not prove deploy-hook
   success; monitor the hook's event codes, pending journal and served certificate.

The interruption tests simulate SIGTERM/SIGKILL and failed file writes, not a
physical disk/power-loss experiment. Files and parent directories are fsynced and
the active pointer is switched using same-filesystem atomic rename. Root-owned
published versions are immutable by convention and permissions against the Nginx
runtime; a compromised root or concurrent uncoordinated root edit is outside that
boundary. Manual ingress/TLS operations must share the hook lock.

Installation, exact permissions, Bootstrap/production behavior, dry-run, monitoring
and rollback/recovery procedures are provided in `README.md`.
