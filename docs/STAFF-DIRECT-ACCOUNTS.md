# Direct staff accounts — local integration candidate

Date: 2026-10-02. Scope: administrator-created **team members**, with a separate username, recovery email, initial password, and existing staff roles. This is not a production release or VPS performance certification.

Update, 2026-10-03: the product owner removed the email-verification prerequisite for direct staff. [The follow-up report](STAFF-EMAIL-PREREQUISITE-REMOVAL.md) supersedes that policy and the creation-time verification mail described below; this original implementation and verification history is retained.

## Candidate and compatibility

Backend branch: `codex/staff-direct-create`, isolated checkout `holoul-staff-direct-create`.
Baseline: `9119a094da23cc23c93475be79d076c4c1f2de21`.
Existing `holoul-api-reference` changes to the local website ingress and previous cleanup evidence were retained. No commit, push, release tag, production migration or production deployment was performed.

Contract: `docs/openapi.json`, `1.6.0-staff-accounts-candidate`, 204 operations, 171 paths, 265 schemas.

- Previous SHA-256: `e90a431d1c662e7fb2f2eb680694678d20c294ef3a53e17dec92fcaeba87c50f`.
- New SHA-256: `055361e334d8f1838052530acb13542a020c10f79bedf28acd70571941bed9c5`.
- Added operations: `identityCreateStaffAccount` (`POST /api/v1/identity/staff`), `identityLoginWithUsername` (`POST /api/v1/auth/username-login`).
- Added schemas: `StaffAccountCreateInput`, `StaffAccountRecord`, `StaffAccountResponse`, `StaffAccountErrorEnvelope`, `UsernameLoginInput`.
- `scripts/contracts/verify-staff-accounts.py` checks all previous operations and components against the exact baseline: none changed. Existing invitation APIs, historical invitation records and email login remain supported.
- The dashboard adopted this contract and regenerated its types. Existing capabilities and role grants are unchanged. Direct creation uses the same management authority as invitations.

## Account lifecycle

The Team primary action is **Add staff**. It collects full name, username, recovery email, password/confirmation and roles. Username is normalized to lowercase, unique and immutable: 3–40 ASCII characters, begins with a letter; letters, digits, `.`, `_`, `-` thereafter. Password follows the existing 12–128 character mixed-case/number policy. Passwords are hashed; they are never returned, mailed, audited or retained in browser storage.

The recovery address must be verified by its owner. The first login then enrolls MFA; later logins challenge MFA. Username and email aliases share the account throttle. Existing accounts continue to sign in with email; this batch does not assign usernames to historical accounts or change customer registration.

Super Admin may create any existing staff role. Administrator may create Support only. Support does not gain dashboard entry merely from account creation. All roles keep their existing permissions. An unfinished new Super Admin cannot satisfy the last-active-Super-Admin safeguard.

The acting administrator's session is retained. Creation performs authorization, role assignment, audit, an immutable idempotency receipt and durable verification mail intent in one transaction. Retries return the same identity; conflicting input, usernames, existing emails or pending invitations are rejected without account conversion. Email verification and password recovery links use `/admin/verify-email` and `/admin/reset-password`; capability tokens stay in the fragment until hydration, then in memory only. Verification does not auto-login. Password recovery revokes previous sessions.

## Migration and local operation

Migration 35: `2026_10_02_000000_add_staff_usernames.php`. It adds a nullable username column, shape/immutability constraints and append-only creation receipts. It does not rewrite existing users. Run with the migrator identity before switching web and workers together. Runtime identity can insert/read receipts but cannot mutate or truncate them. Downgrade is refused once direct account history exists; use a forward migration.

The normal Windows launcher remains `C:\Users\Mohamed\Documents\HOLOUL\Start-HOLOUL.ps1` (desktop shortcut: تشغيل HOLOUL). Dashboard: `https://localhost:8443/admin/team`. Local recovery mail: `http://localhost:8025` (Mailpit; no external delivery).

## Verification evidence

Backend evidence is in ignored `artifacts/staff-direct/`; dashboard evidence is in ignored `.data/staff-direct/`. Synthetic credentials stay in private ignored files. Browser tests use an isolated PostgreSQL/Redis/Mailpit edge on 8444; they do not repopulate the user's cleaned Team data.

- Contract compatibility and frontend OpenAPI structural/reference/generated-type checks: passed.
- PHPStan/Larastan level 10: passed. Pint: 671 files passed.
- Frontend TypeScript, ESLint and production build: passed.
- Frontend unit suite: 608 passed initially; two old hash pins failed, then the contract/role-catalog group passed 29/29 after pinning the additive contract. No role expectations were relaxed.
- Desktop/mobile creation UI: 2/2 passed; screenshots inspected; scoped finish review: `ship`, no material fixes. Design detector: no findings. Scoped design record is in dashboard `.impeccable/surfaces/staff-direct/`.
- Real same-origin F1 authentication: 6/6 passed; Overview: 1/1 passed.
- Composer validation: passed. Initial audit found two advisories in `league/commonmark` 2.10.1 (GHSA-97jj-33gv-5xf9 and GHSA-3q6v-r5mr-hxv8). Updated only that transitive dependency to 2.10.2; locked audit passed with zero advisories.

Final backend suite, migration, contract response and local activation results are recorded below when complete. Initial harness failures are retained: wrong fixture bind resolved before the valid suite; synthetic isolated database hostnames require a separate unchanged production-configuration test run; new browser test selectors were made unambiguous, and test setup now waits for successful MFA and refreshes CSRF before legacy invitation commands.

### Final gate and activation

- Full PostgreSQL run: 1,036 tests, 66,573 assertions, 1,029 passed and seven initial failures. Four were isolated-hostname configuration/readiness failures; two were explicit architecture inventories missing the newly authorized table/routes; one historical 31-to-34 upgrade test accidentally included migration 35. The history test now explicitly selects its original three migrations and retains every original preservation assertion. The architecture allowlists add only the approved table and two routes.
- Final corrected regression run on the installed 2.10.2 dependency: **143 tests, 53,974 assertions, all passed**. Includes all seven failed gates, exact 34-to-35 upgrade/repeat/empty rollback, fresh migrations, direct creation, real PostgreSQL staff/registration/MFA contention, recovery, inherited session lifecycle races and architecture guards. No session race test was weakened. A second complete 1,036-test run was not performed after these fixture/inventory corrections and the dependency patch.
- Contract response validation: **3,092 actual responses**, 179 observed operations, 156 operations with success samples; all 148 explicitly required successes covered, including both additions; zero schema failures or undocumented successes. Validator negative tests: **7/7**. Route inventory and origin/CSRF/session architecture checks passed in the final regression run.
- Real browser tests against the final production-mode application build on isolated 8444: **4/4** direct creation/recovery and retained invitation/role-edit journeys; **6/6 F1 authentication + 1/1 Overview** after resetting only the isolated synthetic fixture. An earlier repeat correctly found the enrollment fixture was already enrolled; reset and rerun passed. The new test helpers wait for hydration and completed MFA before reading state or issuing commands; no application security guard was relaxed.
- Frontend full unit run had 608/610 passing before the two contract pins were refreshed; the two affected contract/catalog files then passed **29/29**, and all four changed authentication/team test files passed **65/65**. TypeScript, final ESLint, API check and optimized Next build passed. ESLint initially also scanned a temporary code-edit helper under ignored `.data`; that evidence was preserved with a `.txt` suffix, outside JavaScript discovery.
- Migration 35 applied successfully to the **local** `holoul` database through `holoul_migrator`, then all six application processes switched together. All six are healthy and use image **`sha256:f74fba7fc61d93498f3eeb8d0387e85bcc9a52b70632fd2c3c8b9a825c96949f`**. Their runtime database identity is `holoul_app`.
- Before/after local counts were unchanged: 2,545 users, 61 staff, 2,484 customer users, 40 invitations and 71 role assignments. Only the migration count changed, 34 → 35. No local account or invitation fixture was inserted during activation.
- Final live checks: `/health/ready` and `/admin/login` return 200. The new username route rejects a request without CSRF with the normal 403 envelope. Existing ingress configuration, Mailpit and startup shortcut remain intact.

The C drive became nearly full during verification, and Docker Desktop's host proxy stopped responding. The npm download cache was preserved under dashboard `.data/staff-direct/preserved-npm-cache` on D. Docker's stale zero-byte socket directories were renamed and retained; Docker Desktop was restarted. No database volume, project history or user document was deleted. Normal and isolated services were restored before final verification.

Classification: **LOCAL STAFF ACCOUNT CREATION READY FOR REVIEW**. No production readiness claim.
