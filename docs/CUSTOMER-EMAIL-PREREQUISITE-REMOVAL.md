# Customer registration and intake without email confirmation

Owner-authorized change, 3 October 2026. Local candidate only; not a production release.

The owner requested removal of the email-verification barrier shown to a signed-in customer on the website's idea-submission form. This explicitly supersedes the customer intake/upload/AI email prerequisite in B0 section 4; the original architecture and historical verification evidence are retained. The existing staff admission policy, MFA and role grants are unchanged.

## Behavior

- Registration atomically creates the customer identity, profile, role and audit entry. It sends no verification mail and creates no verification token. Its generic HTTP 202 response remains indistinguishable for an existing address, and does not automatically authenticate the caller.
- New and existing enabled customers may sign in, submit their own canonical project requests, upload their own intake documents and request permitted AI assistance without proving email ownership. Current profile contact data and ownership are derived server-side. Existing version, idempotency, immutable revision, document scanning, rate-limit and AI-consent safeguards remain.
- The stored verification timestamp is not fabricated. `email_verified` stays false until an actual verification succeeds. No account backfill or database migration is needed.
- The website removes verification controls from ordinary account/intake views and enables Continue using the saved customer profile. Registration tells the customer they may sign in immediately. The guest-request claim view retains its explicit verification instructions and guard.
- Claiming a previously submitted guest request still requires the matching verified email and the private claim capability. Email proof for commercial acceptance, project completion and email notification delivery is unchanged. Password recovery remains available through the recorded email address.

## API contract

| Item | Value |
| --- | --- |
| Previous candidate | `1.7.0-portfolio-editor-candidate` |
| Previous SHA-256 | `ef0a849656234b937a1d76e7258835de5438bcb7bbd57a87f38b4ce011275761` |
| New candidate | `1.8.0-customer-intake-candidate` |
| New SHA-256 | `ca3608446def45a245ead525d69b83ca345b3202fb1deb1f7d4be081c8d13e63` |
| Operations | 204; none added or removed |
| Schemas | None added; registration message constant changed; current-user verification description clarified |

The registration message is now `Registration request received. You can sign in with your credentials.` Consumers that validate the old message literal must adopt the updated schema. The HTTP status, response structure, identity security scheme and all other operation/schema structure remain unchanged. The website contract, generated types and pinned hash are updated. The dashboard contract copy is unchanged. The exact allowed delta is checked against the saved previous candidate in `artifacts/customer-no-email/contract-delta.json`.

## Verification

- Focused PostgreSQL suite: **124 tests, 1,454 assertions passed**. Covers registration and real concurrent duplicate registration, existing/new unverified customers, private AI runs, immutable intake submission, disabled identity rejection, cross-customer isolation, document authorization and staff admission.
- PHPStan/Larastan level 10 passed. Composer strict validation and locked audit passed with no reported vulnerabilities.
- Pint passed for all **660 source/test files**. An initial repository-root run also inspected seven ignored historical diagnostic scripts and failed their formatting; a second explicit-directory run included two generated bootstrap cache files. Those are retained untouched. The final source-only check passed; no checks were weakened.
- Website focused regression: **26 tests passed**, including ordinary unverified intake and verified-email-only guest claiming. TypeScript, ESLint and the production build passed. SwaggerParser validation and the backend source/contract drift check passed.
- Docker candidate built without downloading packages, using the retained pinned runtime and matching production Composer lock. PHP-FPM configuration validation passed. This is a local packaging check, not a new clean-install dependency build or VPS certification.

Security/session/guest/ownership/Gemini/architecture/route-spec regression passed **114 tests, 55,479 assertions**. This includes the unchanged session lifecycle and guest session race tests, actual PostgreSQL MFA concurrency, atomic registration, unverified guest-claim rejection, customer isolation and wrapped 40001 classification. Combined with the focused suite: **238 tests, 56,933 assertions**. Test databases are isolated from the local website database; fresh migrations run through the existing PostgreSQL test fixtures. No new upgrade migration is required.

## Live local acceptance

The six application, default queue, scheduler, document queue, AI queue and notification queue processes now use `holoul-app:customer-intake-no-email`, image identity **`sha256:914a0a169c98754af7bb1dde7cb09b8a1efd1c2b0f86f2e7be88102df3f90d38`**. All six are healthy. Readiness returns HTTP 200, and runtime configuration reports no violations. Gemini remains enabled with the existing private key, model and budget limits.

The website build is **`HWtLuRZUDPTa6fhbranRf`**, served internally on loopback port 3102 through the existing same-origin `https://localhost:8443` ingress. Nginx validation passed. The owner's `Start-HOLOUL.ps1` launcher now starts/checks this port and its warm-start test passed; the prior launcher and nginx configuration are retained for rollback. No unrelated application process was stopped.

A real headless Chrome session registered a new synthetic customer, signed in without mailbox proof, continued from the saved-profile step, observed an enabled AI action, submitted the canonical request, opened its detail, signed out and signed back in as an existing unverified customer. Every step passed. The registration created **zero verification tokens and zero recovery emails**. The account still has `email_verified=false`; the request is `submitted` with **one immutable revision**. This browser run made no additional Google generation request; the new unverified AI workflow is covered with the provider fixture, and the earlier real Gemini generation evidence remains in its own report.

Evidence: `artifacts/customer-no-email/security-regression.log`, `contract-delta.json`, `live-database-proof.json`, and `live-website-result.json`; website build and focused-test logs remain under `.data/customer-no-email`. Synthetic user `01a10290-f897-729f-af41-b667a9117d7a` and request `01a10291-03cd-73f0-8840-72f05b7524c1` are local test records, clearly named as synthetic. No real account's verification state or role was altered. No production deployment, Git release, or VPS performance certification was performed.
