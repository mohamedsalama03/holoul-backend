# Temporary 4 GB topology — verification and release status

2026-10-04. **Deployment configuration prepared; controlled deployment BLOCKED pending external inputs/integration.** No VPS deployment, production certificate issuance, SMTP transmission or external AI activation occurred.

## Candidate chain

- Original verified candidate: `c5c7a1335102370a54b93cbdbabdd2cf3c1d0e92`.
- Dedicated application policy commit: `f1567e1ae264e76327e640accc0ca8a35437c48f`, directly parented by c5c7a13; pushed and remote verified identical.
- This report belongs to the subsequent deployment-only commit on `release/unified-vps-candidate`. It adds compose.production.yaml and deploy/production only, plus this verification document. No main merge or production tag.

Application implementation and its complete backend gate are recorded in [TEMPORARY-DOCUMENT-UPLOAD-VERIFICATION.md](TEMPORARY-DOCUMENT-UPLOAD-VERIFICATION.md). The complete suite passed 1,108 tests / 68,355 assertions, including all inherited architecture, PostgreSQL concurrency, identity race, document and upgrade tests. Focused policy coverage: 11 / 151. PublicServices under uploads=false/AI=false: 2 / 107. Python contract tests: 16; parser: 17. Pint, level-10 analysis, Composer and image/source scans passed.

## Production configuration

[Operator runbook, exact budget, secrets/SMTP/TLS, frontends and restoration](../deploy/production/README.md).

The service set is 13 runtime services plus migration and private storage bootstrap jobs. Four isolated queues and the scheduler remain. Only ingress publishes 80/443; Website/Dashboard and PostgreSQL/Redis/storage are private. New uploads=false, AI=false/sandbox, both Gemini approval flags=false; no key required. ClamAV/FreshClam/Mailpit/inspector/local-test services are absent from the temporary profile. All scanner code/states/tests remain in the repository.

Memory cap sum: **2,888 MiB**; target remaining RAM: **1,027.89 MiB**. PHP-FPM two static children; one worker per required queue. PostgreSQL 32 connections/128 MB shared buffers/2 MB work_mem; Redis 128 MB/noeviction. Node old-space 256 MB per frontend. Swap is not application RAM. Disk plan is 15 GiB including image rollback and bounded storage/log allowances; these are planning/monitoring budgets, not silent retention or hard object quotas.

The planning evidence remains the measured main-node simultaneous peak **1,574.25 MiB** after excluding scanner/updater from the prior study. With 1,024 MiB reserve and a conservative 64 MiB allowance: **2,662.25 MiB**. These figures do not certify actual VPS TLS workload, performance or production readiness. Historical failed latency/scan-limit evidence remains intact.

## Checks on the final configuration

| Check | Result |
|---|---|
| Compose config, explicit production inputs | PASS |
| Empty required image/SMTP fields | Correctly rejected before startup |
| Static topology guard | PASS: 13+2 services, isolation, no scanner, policy/AI disabled, private upstreams, limits, immutable image inputs |
| Negative topology sentinels | 5/5 reject: public DB port, scanner service, uploads enabled, AI enabled, application swap enabled |
| Final production-profile validator in rebuilt runtime | PASS, with synthetic file-mounted secrets, no network and no scanner/Gemini key |
| Production PHP-FPM syntax | PASS; two static children |
| Main Nginx syntax | PASS under readonly root and unprivileged user |
| HTTP ACME bootstrap overlay + Nginx syntax | PASS; no real certificate issued |
| Isolated routing/header requests | 14/14: 10 route cases, three permanent redirects, one forged Host rejection |
| Origin / forwarded metadata | Original Origin preserved; forged Forwarded/X-Forwarded metadata discarded |
| Frontend response CSP | Passed through from isolated upstreams |
| Production PostgreSQL bootstrap on fresh isolated storage | PASS, only holoul database, no test/demo database |
| PostgreSQL TLS/SCRAM, runtime role separation | PASS: verify-full connection, no runtime DDL, plaintext denied, both roles non-superuser/non-createdb/non-createrole |
| pg_hba syntax and settings | zero parse errors; ssl=on, 32 connections, 128MB shared buffers |
| Redis production startup/TLS | PASS: real verified TLS connection/authentication and noeviction |
| Shell syntax for DB/Redis startup | PASS |
| Frozen OpenAPI build/hash/source manifest | PASS, unchanged |
| Exact final source secret scan | PASS; pinned Trivy 0.74.0 |
| git diff --check | PASS |

Routing checks use disposable echo upstreams and real Nginx/FastCGI, not a claim of a new Website/Dashboard browser E2E run. The isolated DB/Redis check used real production configurations and TLS fixtures, no production database or real SMTP credentials. Strict production config validation does not prove provider delivery, certificate trust on the public internet, backup readiness or runtime capacity.

Website consumed-operation compatibility remains **36/36**, Dashboard **67/67**, structural/security closure unchanged. The frontend copies and source were not edited.

## Corrections and retained failures

- Initial network choice overlapped an existing local Docker address pool. The final internal networks are 10.203.80.0/24 and 10.203.81.0/24; target-host conflict detection is still a deployment prerequisite.
- Initial synthetic PostgreSQL data directory lacked the required UID 70 ownership. The final profile explicitly runs PostgreSQL as UID 70 with a readonly root, writable private data/tmpfs and documented provisioning. Fresh initialization and verified authenticated TLS health checks passed.
- The initial routing test expected 400 for a forged Host; Nginx securely closed it with its default-vhost 444. The probe now accepts that documented rejection, never a successful response. All inherited identity/security tests were untouched.
- The initial HTTP-only ACME bootstrap config used Nginx's default unwritable temporary directory. Temporary paths now point to the private tmpfs; readonly syntax validation passes.
- The first staged whitespace check identified extra terminal blank lines in newly added files; normalized to one final newline before the successful commit gate.
- These failure logs are retained alongside the successful reruns. No production evidence or failed capacity result was removed.

Private evidence: `/home/mohamed/.local/share/holoul-freezes/upload-policy-20261004/production-validation`. See resolved-final.json, final-static-guard.log, negative-guard-tests.json, transport-client.log, postgres-policy.log, routing-assertions.log, final-production-validator.log and final-source-secret-scan.log. Synthetic certificates/credentials remain private outside Git and must never be reused in production.

## Contract unchanged

OpenAPI 3.1.1 / API 1.8.1-gemini-documents-candidate.
204 operations; 265 schemas.
SHA-256: `3c93a09f08ce199cc1c2867ad846a1ca554fd03b6d934070178c5101646df410`.

No operation, schema, parameter, security scheme or frontend contract copy changed. Only the five reviewed application/config hashes changed in the policy commit's source manifest.

## Remaining blockers

1. **SMTP provider**: host, port, encryption and username are unconfirmed. The owner explicitly requested placeholders only. Real password must be installed directly as a VPS secret. No Mailpit replacement, invented production values or password in source/Git.
2. Approved From address is **info@holoul.com.ly** and desired name **Holoul Platform**. Existing backend hardcodes **HOLOUL**; the display-name setting requires a separate small reviewed application change. Current validator requires SMTPS; a STARTTLS-only provider needs review. Real delivery/SMTP identity acceptance remains pending.
3. **Website deployment flag implementation** and an accepted production image. WEBSITE_DOCUMENT_UPLOADS_ENABLED is an explicit handoff requirement; existing source does not implement it. Both guest and customer documentless flows need real frontend integration acceptance. Dashboard image/base-path/security/history behavior also need deployment acceptance.
4. Contact production mail additionally keeps its existing dashboard/mail acceptance flags false until accepted. Historical recipient info@holoul.ly was not changed by the sender-address answer.
5. Public DNS/certificate issuance and renewal, private CA/secret provisioning, immutable release image inventory, host/subnet/firewall checks, sealed off-host backups and restore evidence.
6. Actual 4 GB VPS capacity/performance and same-origin security acceptance under the final TLS topology, with inherited thresholds unchanged. No B8 certification is claimed.

[Website/Dashboard handoff and safe restoration](TEMPORARY-DOCUMENT-UPLOAD-POLICY.md). Never revert to an image that ignores the upload policy while the scanner is absent. A full 8 GB restoration starts and verifies the real scanner before uploads become available.

**Classification: policy verified; temporary topology candidate prepared; deployment blocked by SMTP, frontend integration and VPS acceptance.**
