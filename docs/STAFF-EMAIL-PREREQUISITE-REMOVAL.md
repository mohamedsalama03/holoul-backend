# Staff sign-in without an email prerequisite

Date: 2026-10-03. Explicit product-owner request: remove email verification from administrator-created team onboarding. This supersedes the email prerequisite in `STAFF-DIRECT-ACCOUNTS.md`; the original evidence remains intact. Local candidate only; no production release or VPS certification.

Follow-up: [the dashboard admission correction](STAFF-EMAIL-CAPABILITIES-FIX.md) fixes a remaining email condition in capabilities and reporting that this first pass missed. The historical test passes below did not establish that an unverified direct staff account could see the actual dashboard; the follow-up adds that assertion and supersedes the runtime/contract identity below.

## Behavior

- New and existing administrator-created staff can use their username or recovery email with their password, then enroll or challenge MFA. Email verification is no longer a first-factor rejection.
- Creating staff no longer generates an email-verification token or durable verification-mail intent. Account, roles, immutable creation receipt and audit still commit atomically. Idempotency, duplicate handling and grant policy are unchanged.
- Recovery email remains required and password recovery still delivers its capability through the existing mail transport. Ownership is not fabricated: `email_verified_at` remains null and the API reports `email_verified: false` until ownership is independently proven.
- Direct-account `onboarding_pending` ends after confirmed MFA. The last-Super-Admin guard counts a direct Super Admin as usable only after MFA enrollment; an unenrolled account cannot replace the last usable Super Admin.
- The dashboard removes the verification instruction after creation and the verification-resend choice from account help. Existing verification URLs and APIs remain compatible for previously issued links. Customer verification and historical invitation acceptance are unchanged.
- MFA, CSRF bootstrap, password confirmation, session rotation/revocation, throttling, role restrictions and the previously reviewed cookie-race tests remain in place.

No migration or account-data backfill is required. Existing staff accounts benefit from the policy change without changing credentials or setting false verification timestamps.

## Contract

Authoritative contract: `docs/openapi.json`, version `1.6.1-staff-accounts-candidate`.

- Previous SHA-256: `055361e334d8f1838052530acb13542a020c10f79bedf28acd70571941bed9c5`.
- New SHA-256: `b89513b8b425bebe33e3f425e141037feebd841eddd19b42842bc2258d79bb02`.
- Still 204 operations, 171 paths and 265 schemas; no operations or schemas added or removed.
- Only `identityCreateStaffAccount` and `identityLoginWithUsername` descriptions change; the latter removes `email_verification` from its documented validation fields. Component schemas, role grants and response shapes remain identical.
- Dashboard contract copy, generated types and hash pins match. The comparison to accepted candidate commit `9119a094da23cc23c93475be79d076c4c1f2de21` also confirms its existing operations/components remain unchanged.

## Verification and local activation

Evidence: backend ignored `artifacts/staff-no-email-gate/`, dashboard ignored `.data/staff-no-email-gate/`. All application/integration tests use PostgreSQL. Browser fixtures and Mailpit run against isolated port 8444, never the user's local Team records.

- Direct staff, staff contention, recovery, invitations, session lifecycle, MFA contention and architecture: **98 tests, 53,809 assertions passed**. Includes truthful ownership, both login aliases, password recovery, atomic rollback, current-session/CSRF enforcement and usable last-Super-Admin coverage.
- PHPStan/Larastan level 10, Composer strict validation and locked audit: passed, zero advisories. Pint over the repository PHP source inventory: **672 files passed**.
- Dashboard: **629 unit tests in 65 files passed**; TypeScript, ESLint, generated contract/Redocly validation and production build passed. Changed files pass Prettier.
- Real browser journeys: **4/4** direct staff and retained Team/invitation flows; **8/8** F1 authentication, Overview and inherited MFA/CSRF recovery. The direct-staff test reaches MFA without visiting a verification link and confirms `email_verified: false` after successful MFA.
- Runtime Docker build and PHP-FPM configuration check passed.

- Additional authorization/real PostgreSQL contention, guest session isolation, route/spec drift and nested-concurrency classification: **57 tests, 1,826 assertions passed**. Total backend verification for this change: **155 tests, 55,635 assertions**. Inherited session/MFA/guest race test files have no diff.
- All six local application processes switched together to `holoul-app:staff-no-email-gate-runtime`, image `sha256:8e03998163f97d8505ab3e84cf844ff305e249d42ea71625c75eacc01758f4dc`. Dashboard build: `Kb3FAnHyc6D1R33FjtoBz`.
- Local readiness and `/admin/login` return 200; running authentication/creation source hashes match the verified checkout. The runtime identity remains `holoul_app`.
- Before/after database counts are identical: 2,546 users, 62 staff, 2,484 customer users, 40 invitations, 72 role assignments and 35 migrations. No local migration, fixture reset, account deletion or email-ownership backfill was run. Isolated test services are stopped after verification; the ordinary local stack stays running.

Local entry point: `https://localhost:8443/admin/login`. Staff can return directly to sign-in from an old verification page. Existing credentials work; first use enrolls MFA and subsequent sign-ins require MFA. Password-recovery mail remains in local Mailpit at `http://localhost:8025`.

Historical/non-product failures are retained: Docker initially returned 500/hung with about 110 MB free on C. Two inactive npm execution caches were preserved on D, freeing about 1.2 GB; stale Docker socket directories were renamed and retained, and Docker was restarted without deleting volumes. Pint's `--dirty` mode was unavailable inside the verification container, which has no Git metadata; a broad scan then found only an ignored diagnostic helper, and an explicit-directory scan also included generated bootstrap cache files. The repository source inventory passed. The full dashboard Prettier scan flags two pre-existing `.impeccable/surfaces/staff-direct/` records; changed product/test files pass. No source/security check was disabled to obtain a pass.
