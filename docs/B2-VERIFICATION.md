# B2 verification evidence

**Status: B2 Identity & Customer Isolation is implemented and the complete inherited B1 gate plus B2 checks passed. B3 is not started.**

The final `bash scripts/verify.sh` run started **2026-09-16T16:51:17Z**, finished **2026-09-16T16:57:35Z**, and exited **0**, ending with `All B1 and B2 verification gates passed.` It took 6 minutes 18 seconds. Evidence is recorded in ignored `artifacts/b2-verification.log`, `b2-verification-time.jsonl` and `b2-verification.exit`. Only this complete successful run is counted below. Documentation proofreading and this evidence report followed the executable-code gate.

## Baseline and scope

Implementation began on clean `main` at approved B1 commit **`a8881df992758e765c4a9d1f8b360b6f7ef71b79`**, with no configured remote or `origin/main`. [The baseline receipt](B2-BASELINE.md) was written before implementation. The final B2 commit and its parent are reported in the handoff rather than embedding this document's own commit hash.

[B0 architecture](B0-ARCHITECTURE.md), all three B1 migration files, the static-analysis configuration and inherited B1 verification checks remain intact. Architecture scope now explicitly permits Identity/Customers and exactly their approved tables/routes; later modules remain empty. The previous test asserting that the CSRF initialization route was absent was updated to assert the approved HTTPS route and rejection over HTTP. Its request-ID check and the absent `/login` route remain covered. No test was skipped, authorization bypass added, analysis baseline introduced, or vulnerability suppressed.

The implementation contract and full route catalog are in [B2-IMPLEMENTATION.md](B2-IMPLEMENTATION.md). [B2-VERSIONS.md](B2-VERSIONS.md) records four new locked Composer packages and the sandbox mail image. Existing B1 product pins remain PHP **8.4.25**, Laravel **13.32.0**, Sanctum **4.3.3**, PostgreSQL **18.6**, Redis **8.2.9** and Nginx **1.30.5**. Verification used Linux/amd64 Docker containers with real PostgreSQL and Redis, not SQLite or host PHP.

## Exact quality results

Every row below passed in the final run. The shared [verification script](../scripts/verify.sh) is also the [CI entry point](../.github/workflows/ci.yml).

| Gate | Result |
|---|---|
| Docker Compose configuration | Valid |
| Development, service and production image builds | Passed |
| `composer validate --strict` | Valid |
| Locked Composer installation | Nothing to install, update or remove |
| `composer check-platform-reqs` | All requirements satisfied |
| `composer audit --locked --no-interaction` | No security vulnerability advisories |
| Pint | **164 files passed** |
| PHPStan/Larastan level 10 | **0 errors**, no baseline or broad ignores |
| Test database guard | Isolated `holoul_test` / `holoul_migrator` confirmed |
| Unit and feature PHPUnit suite | **251 tests, 1,098 assertions**, 111.563 seconds |
| Architecture PHPUnit suite | **9 tests, 10,737 assertions**, 0.279 seconds |
| Total PHPUnit | **260 tests, 11,835 assertions**, no failures/errors/skips |
| Fresh migrations | All **8** migrations passed; reapplied after tests |
| Repeat migrations | Two no-op runs passed |
| Exact B1 upgrade | **1 test, 60 assertions**, included in the feature suite |
| Config cache, route list, config clear | Passed; **30 total route entries**, including **27 B2 additions** |
| Inherited HTTP, logging, queue and scheduler regressions | Passed |
| Exact production-image HTTPS identity smoke | **16 checks passed** |
| Source secret scan | Exit **0**, no secrets reported |
| Five final runtime image scans | All exit **0**, **0 HIGH / 0 CRITICAL** reported per image |

PHPUnit class accounting from `artifacts/quality.log`:

| Class (namespace abbreviated) | Tests | Assertions |
|---|---:|---:|
| Unit / Async / OperationInputTest | 7 | 7 |
| Unit / Audit / SafeAuditMetadataTest | 17 | 17 |
| Unit / Customers / InternationalPhoneTest | 9 | 10 |
| Unit / Http / ApiErrorTest | 4 | 12 |
| Unit / SafeLogProcessorTest | 3 | 6 |
| Feature / Async / DurableOperationsTest | 17 | 78 |
| Feature / Audit / AuditFoundationTest | 29 | 50 |
| Feature / Authorization / AuthorizationConcurrencyTest | 2 | 10 |
| Feature / Authorization / RoleFoundationTest | 23 | 49 |
| Feature / Console / SafeExceptionHandlerTest | 7 | 13 |
| Feature / Customers / CustomerIsolationTest | 26 | 47 |
| Feature / HttpFoundationTest | 10 | 46 |
| Feature / Identity / IdentityAtomicityTest | 3 | 52 |
| Feature / Identity / IdentityHttpTest | 34 | 300 |
| Feature / Identity / MfaConcurrencyTest | 2 | 11 |
| Feature / Identity / MfaTest | 19 | 105 |
| Feature / Identity / MigrationUpgradeTest | 1 | 60 |
| Feature / Identity / RecoveryTest | 15 | 100 |
| Feature / Identity / RecoveryTransportTest | 9 | 23 |
| Feature / Identity / RegistrationConcurrencyTest | 1 | 23 |
| Feature / Identity / SessionPruningTest | 5 | 29 |
| Feature / Queue / SafeFailedJobProviderTest | 8 | 50 |
| Architecture / FoundationArchitectureTest | 9 | 10,737 |

## Security and database evidence

The 34 HTTP feature cases use real encrypted cookies, persisted PostgreSQL sessions, the Sanctum guard, exact-origin middleware and the production CSRF check. They do not use `actingAs` or disable middleware. Tests cover registration normalization and forged fields, generic login/recovery behavior, fixation and cookie replay, logout, CSRF omission, invalid origin, idle/absolute expiry, stale authorization generations, password changes/reset, session revocation, staff MFA and Redis failure without an authentication bypass.

Ownership tests cover direct, nested, list, search, malformed and forged identifiers in both customer directions. Staff permissions are explicit and cannot cross into the customer persona. Tests reject customer promotion, excess grants and invalid role/persona assignments. Last-enabled-Super-Admin demotion/disable, duplicate registration, password reset, TOTP replay and recovery-code consumption use independent-process concurrency where required by PostgreSQL semantics. Atomicity tests prove identity/profile/role/recovery/audit rollback together. Runtime database grants reject catalog mutation and append-only audit modification.

Five additive migrations introduce eleven tables and strengthen the existing sessions relationship:

| Migration | Added tables / invariant |
|---|---|
| `2026_09_16_010000_create_identity_core.php` | `users`, `identity_sessions`; UUIDv7, normalized unique email, persona/version constraints and session foreign key |
| `2026_09_16_010100_create_customers_table.php` | `customers`; one profile per customer user, immutable ownership and E.164 constraint |
| `2026_09_16_010200_create_identity_authorization_tables.php` | `roles`, `permissions`, `role_permissions`, `user_roles`; persona consistency and read-only runtime catalogs |
| `2026_09_16_010300_create_identity_recovery.php` | `identity_recovery_tokens`, `identity_recovery_mail`; hashed tokens and encrypted durable delivery intent |
| `2026_09_16_010400_create_identity_mfa.php` | `identity_mfa`, `identity_mfa_recovery_codes`; staff-only factors and atomic single-use recovery |

The upgrade test verifies hashes of the exact three approved B1 migration files, constructs that schema, preserves representative anonymous session/audit/async records, applies all five additions, confirms a subsequent no-op, and rechecks privileges and constraints. This is an exact B1-to-B2 schema/data upgrade check, not just another fresh installation.

Email verification/reset tests prove purpose, identity, email, authorization-generation and expiry binding, safe repeated verification, reset single use, token invalidation and session revocation. Recovery transport tests cover durable commit/rollback, lost Redis delivery, stale tokens, handler fences and uncertain SMTP delivery. No credential, token, MFA secret or recovery code is written to audit, ordinary logs or operation references.

## Production-mode smoke and image evidence

The gate replaced app, queue and scheduler with the exact same `holoul-app:b2-runtime` image and verified image identity equality. All three reported `environment:production`, `debug:false` and `testing_dependencies:false`. Seven long-running services were healthy: app, queue, scheduler, PostgreSQL, Redis, Nginx and Mailpit. PostgreSQL, Redis, PHP-FPM and SMTP had no published host ports; only Nginx published loopback ingress ports.

Inherited B1 checks passed for liveness/readiness/API, safe request IDs and headers, a 12 MiB + 1 byte request returning JSON 413, and absence of a private query marker in service logs. The actual scheduler → Redis → worker → PostgreSQL probe passed for operation `01a0ab25-a38b-7042-b276-851983145c16`, reached the expected `handler_missing` terminal outcome and removed its reserved fixture.

The HTTPS smoke explicitly trusted the generated local SAN certificate and exercised real cookies against the production image. Its 16 checks were: secure cookie flags; missing CSRF rejection; two customer registration/login flows; isolation in both directions; sandbox verification and replay; generic forgot-password response; reset single use and session revocation; replacement-password login and logout-cookie replay; wrong-origin rejection; staff password-only denial; TOTP enrollment; staff denial of customer access; TOTP challenge; and recovery-code consumption/reuse rejection. Actual queued mail was read from the private Mailpit sandbox. The check removed **3 temporary users** and retained append-only audit records. See `artifacts/runtime-identity-http.json` and `runtime-identity-cleanup.json`.

Final Docker Engine image identities (these may identify OCI manifest indexes; they are not all image-configuration digests):

| Image | Final identity | HIGH / CRITICAL |
|---|---|---|
| Application B2 | `sha256:3481f1145105d0e894e03765576dff2f88ebf7a6e78cca2725ce4107a44b9282` | 0 / 0 |
| PostgreSQL B1 runtime, rebuilt unchanged | `sha256:817a9dd1112b4c1ce866e8afbe73321f4284e2805de19c094dedc8e8553efe41` | 0 / 0 |
| Redis B1 runtime, rebuilt unchanged | `sha256:034441b508747f4f6eb47f0250a32fd51f2343d55e01459497def08b1bcd0fd4` | 0 / 0 |
| Nginx 1.30.5 Alpine | `sha256:73c75df4075c918f91017fdda46ad81e55e5af77ba3a64ca3d5014bd9244fe7f` | 0 / 0 |
| Mailpit 1.31.1 | `sha256:98b916bd3c8d61f7633a52d3ea2f58d00620cb01ca57ab59edde68c347a95365` | 0 / 0 |

Application build configuration digest: `sha256:4d13a6f228b7f22699c6ac4a07b28684459056e2c29290e9c31796ba7fa27104`. The source and image scans used pinned Trivy **0.74.0**, normal database freshness checking and no ignore or unfixed-finding exclusions. Database metadata remained UpdatedAt `2026-09-16T07:08:16Z`, NextUpdate `2026-09-17T07:08:16Z`. Source scanning excludes `vendor`, `artifacts` and `.git`; image scanning includes installed production dependencies. Dated zero HIGH/CRITICAL results are not a guarantee against every severity or future advisory.

## Findings fixed and limits

Review and negative tests led to the following corrections before the passing gate:

- Revalidate the locked authorization generation when completing authentication or revoking other sessions, preventing stale requests from regaining authority.
- Preserve the original recent-password timestamp through delayed MFA completion.
- Explicitly hash client password text even when it resembles an existing hash; enforce the same boundary in initial Super Admin provisioning.
- Use per-token recovery throttle buckets instead of one shared invalid-account bucket; keep atomic Redis failure closed.
- Enforce CSRF despite same-origin Fetch metadata and the framework's testing shortcut.
- Prune idle/expired metadata without making Redis authoritative.
- Defer recovery-handler resolution until runtime secrets are available and parse boolean environment values correctly.
- Proxy the internal-only Mailpit UI through Nginx so the real email smoke can reach it without giving Mailpit external egress.

An earlier complete-gate attempt stopped at strict Composer validation because exact new dependency constraints triggered warnings; narrow patch constraints now retain the identical reviewed lock versions and pass. Earlier exploratory HTTP tests included an obsolete B1 route expectation and a test-database overlap between agents; the expectation was updated for the approved route, database execution was serialized, and the complete suite passed afterward. An initial email smoke could not reach the internal-only Mailpit UI; the final proxy topology passed all 16 real checks. Those attempts are not presented as successful verification.

The final scans retain the inherited upstream metadata warnings: Alpine 3.24 is absent from Trivy's EOL table although its 3.24 vulnerability repository is used; vendor-severity fallback is reported; PostgreSQL's scan lacks details for `CVE-2026-80256`. The [curl advisory](https://curl.se/docs/CVE-2026-80256.html) describes a Medium-severity Windows-only wcurl issue, outside these Linux containers. Trivy can retain or substitute an unknown severity when [detail lookup fails](https://raw.githubusercontent.com/aquasecurity/trivy/v0.74.0/pkg/vulnerability/vulnerability.go), so zero reported HIGH/CRITICAL findings alone do not resolve this record. This is an upstream platform-applicability assessment, not a claim that the older libcurl package is patched; [Alpine's package record](https://raw.githubusercontent.com/alpinelinux/aports/3.24-stable/main/curl/APKBUILD) lists 8.22.0-r0 as fixed. No warning was hidden or suppressed.

No unresolved B2 functional defect was found in the completed checks. Remaining integration/launch work is explicit: the Next.js verification/reset fragment pages are not included; no real external SMTP delivery, production deployment, production database TLS, hosted GitHub Actions or remote branch protection was exercised. Local TLS is a self-signed fixture and Compose PostgreSQL remains private-network plaintext; production defaults require verified database TLS. Email verification state is available for future sensitive actions but is not a B2 login gate. SMTP acknowledgement loss remains terminal delivery uncertainty with a safe resend path, not an exactly-once guarantee.

Production operations still require reviewed certificate/proxy and secret configuration, key rotation/recovery, time synchronization, monitoring, backup/restore drills, retention and load-based tuning. These dated local gates establish the B2 implementation and its tested security boundaries; they do not substitute for deployment acceptance. Categories, Project Intake, Documents and every other B3+ workflow remain unimplemented.
