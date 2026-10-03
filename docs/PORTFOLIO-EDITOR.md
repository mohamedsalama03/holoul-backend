# Portfolio Editor candidate — locally verified and activated

Date: 2026-10-03. User-approved scope: staff who add, edit and directly publish the public website portfolio. No private delivery-project or customer-management grant.

## Resumed verification and local activation, 2026-10-03

**LOCAL FEATURE READY.** Migration 36 was applied through the migrator identity. App, default queue, scheduler, document queue, AI queue and notification queue now run the same image `holoul-app:portfolio-editor-runtime`, SHA-256 `1fae82f3b1602e2cf7a857c2d66a6dee749e166cc65f607c1c0ee7e65ab4da6b`; all six are healthy. This is a local candidate, not a production release or VPS performance certification.

The role is available in Team for an authorized administrator to assign. No real account was promoted. Read-only live verification confirms 2546 users (62 staff, 2484 customers), 40 invitations and 72 role assignments are retained; migration count is now 36. The new role has seven grants and zero live assignments. Runtime DB identity remains `holoul_app`. AI remains disabled.

Dashboard build `Hx692UVP-i2lF5upiCA3L` runs on local port 3002; the existing 8443 ingress now points to it while the website stays on port 3100. The normal `Start-HOLOUL.ps1` launcher was updated to port 3002 and its idempotent warm-start check passed. An earlier combined process-stop/restart command was rejected by automatic approval review without a detailed reason; the safer alternate starts the candidate on a separate port and switches ingress. No unrelated process was stopped. Dashboard, website and readiness URLs return HTTP 200.

Completed evidence:

- Focused PostgreSQL role/staff/portfolio/upgrade suites: **77 passed, 1532 assertions**, including fresh and exact 35-to-36 migration checks.
- Inherited architecture, unit, identity/session and MFA concurrency, staff onboarding concurrency, guest/public session isolation, document security, AI/assistance and reporting: **307 passed** initially; one historical B6-to-B7 upgrade test failed because the current contact projection selected the later username column. Its fixture now uses a test-only reader for the exact historical schema, retaining real DB locks/writes and all original history/grant assertions. That upgrade plus route/spec checks then passed **3/3, 2187 assertions**. No runtime identity/session guard was relaxed.
- New-role contract capture tests: **3 passed, 102 assertions**. OpenAPI validation replays retained historical response evidence plus these new samples; this is explicitly mixed-age response evidence, not a fresh full-system response capture. No schema failures or missing required successful-operation coverage.
- PHPStan/Larastan level 10, Pint across **682** source/test PHP files, Composer strict validation and audit passed.
- Runtime Docker build, PHP-FPM validation, isolated migrated runtime and live health passed.
- Real same-origin Chrome: **6/6 F1 authentication**, **1/1 Overview**, **1/1 direct staff/recovery**, and **1/1 editor creation → MFA → restricted navigation → image → publication → anonymous public visibility → unpublication → recovery**. The first image check failed because the isolated stack lacked storage connectivity; after restoring it, the second exposed its missing document worker. Both isolated dependencies were restored and the final journey passed. These failures remain in the logs.
- Dashboard 630 unit tests/65 files, final focused AuthGate 16/16, TypeScript, ESLint, formatting, contract/type checks and optimized build passed as recorded below.

Docker recovery used a WSL shutdown to release a stale device reference, preserved stale socket directories by renaming, then restarted Docker. PostgreSQL recovered its WAL automatically. No database, Docker volume, image or project data was deleted during recovery.

Before Gemini implementation, the candidate source was preserved independently in ignored `artifacts/portfolio-editor/pre-gemini-candidate.tar.gz`, SHA-256 `fd5c254597daaf3137032852180103c5047e58e4a26cd0aba022098ade44c252`, with a 1131-file hash manifest and Git base. The live portfolio image is likewise retained independently; later Gemini source work is not in that image. No commit, push or production deployment was performed.

## Candidate baseline

Worktree: `/home/mohamed/projects/customers/holoul-staff-direct-create`, branch `codex/staff-direct-create`, Git base `9119a094da23cc23c93475be79d076c4c1f2de21` plus the pre-existing uncommitted direct-account and staff-email remediation candidate. Previous contract `1.6.2-staff-accounts-candidate`, SHA-256 `32fa258873794513849eb773a4c7bf41e4e2f8dd66ec7ef530877b775284386e`. Baseline diff/status and original contract are preserved in ignored `artifacts/portfolio-editor/`. Earlier batch evidence remains unchanged.

## Initial implementation checkpoint (before recovery)

- Add `portfolio_editor` (Portfolio Editor / محرر المعرض), with exactly seven grants: `identity.self.read`, `identity.self.update`, `notifications.self.read`, `notifications.self.manage`, `portfolio.read`, `portfolio.manage`, `portfolio.publish`.
- New migration `2026_10_03_000000_add_portfolio_editor_role.php` adds this role and its own grant rows, preserving all existing grants. Staff-role validation and the deferred invitation-completeness guard expand from seven to eight. Existing assigned-role/invitation foreign keys prevent removing retained role history on rollback.
- Existing grant-subset rules allow Super Admin and Administrator to assign Portfolio Editor; no security-role grant rule changes.
- Staff dashboard admission allows `portfolio.read` OR `reporting.read` after the existing staff admission/MFA requirements. Reports still require `reporting.read`. Customer, staff-management, contact, taxonomy, internal projects, documents, AI and audit permissions are not granted.
- Dashboard lists the role in Team, opens `/portfolio` for portfolio-only users, and gates Overview/Activity on reporting access. Existing portfolio image processing, publication, unpublication, recent-password checks, CSRF, authorization, immutable publications and session rotation remain in force.
- At the initial pause, no local migration, fixture reset, deployment, commit or push had been performed. The resumed activation and isolated synthetic testing are documented above; existing user accounts have still not been assigned the role.

## Contract delta

Candidate `1.7.0-portfolio-editor-candidate`, SHA-256 `ef0a849656234b937a1d76e7258835de5438bcb7bbd57a87f38b4ce011275761`: 204 operations, 171 paths, 265 schemas. No operation or schema additions. Eleven role-bearing schemas extend their role enums and/or array limits; staff-directory role filter includes the new enum member. Direct-account description documents Administrator's authority for the new role. Existing operation security schemes and authorization requirements are retained. Clients with exhaustive role unions must adopt the new enum; dashboard contract and generated declarations were updated together. This is not a claim that the old wire schema is entirely unchanged.

`docs/contracts/portfolio-editor-delta.json` records the exact structural delta; `scripts/contracts/verify-portfolio-editor.py` reverses it and verifies the canonical 1.6.2 baseline hash. The earlier direct-account-only verifier remains historical and is superseded by this delta check for the new role extension.

## Verification recorded before Docker recovery

- Dashboard: 630 unit tests / 65 files passed. After the final home-route error-handling adjustment, its focused AuthGate suite passed 16/16.
- Dashboard TypeScript, ESLint, scoped Prettier, production build and OpenAPI/Redocly/generated-types checks passed.
- Backend OpenAPI assembly and exact recorded-delta verification passed.
- Permanent backend coverage added for direct editor MFA/admission and denied reports/contact; Administrator create/reassign restrictions; all-eight-role invitation sealing; full portfolio create/image/publication/unpublication lifecycle under editor authority with denied unrelated domains; exact 35-to-36 migration retaining existing rows/grants and repeat/rollback checks. These new PHP tests have NOT run yet.

## Historical blocker and remaining gates at the initial pause

Docker Desktop crashed after Windows C: reached zero free bytes. Several inactive cache/install files were moved to `D:\HOLOUL-recovery\20261003-portfolio-editor`, retaining their contents. The inactive Puppeteer cache was moved there with a junction at its original path. A locked VS Code installer was left in place. Stale Docker runtime socket directories were renamed, preserving them. No Docker volumes, images, databases or project data were deleted.

Docker subsequently failed to open its data disk. Its automatic startup attempted to provision a device that it could not identify, and the system refused formatting because the device was in use. Docker Desktop was stopped to prevent continued automatic attempts. No manual filesystem repair, format, factory reset or data-disk relocation was attempted. User chose to free 5–10 GB on C: and reboot Windows before continuing. Database integrity has not been re-verified after this host incident.

Pending: restore/inspect Docker after reboot, verify PostgreSQL health and retained data, run new/affected PHP tests and inherited identity/session/public-intake race tests, architecture and route/spec checks, PHPStan/Larastan level 10, Pint, Composer validation/audit, fresh and exact upgrade migrations, Docker image build/runtime checks and real-browser editor/portfolio plus authentication regressions. Only after those pass apply migration 36 with the migrator identity, switch all six local backend processes together, restart the built dashboard and check same-origin runtime. Do not reset live fixtures or promote a real account implicitly.

Status: **IMPLEMENTED CANDIDATE — BACKEND VERIFICATION AND LOCAL ACTIVATION BLOCKED**. Not production-ready. Resume from this checkpoint after the user's reboot; do not redo or discard previous candidate work.
