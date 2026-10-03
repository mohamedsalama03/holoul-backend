# Direct-staff dashboard admission correction

Date: 2026-10-03. Follow-up to the owner's screenshot after removing the email prerequisite. Local candidate correction, no production release or role-grant change.

## Defect and correction

The affected direct account already had the Project Manager role, an enabled account and completed MFA. The previous change removed the first-factor email rejection but left the email condition in `CurrentCapabilities` and `ReportingApi`. Consequently `/identity/me` withheld `admin.dashboard.view`, and the dashboard correctly displayed its access-denied screen. Changing the user's role or claiming their email was verified would have hidden the defect.

An internal `emailPrerequisiteSatisfied` value now distinguishes admission policy from proof of email ownership. Identity-owned readers derive the direct-staff exception from the existing immutable username marker; it applies only when the identity kind is staff. `verifiedEmail`/`email_verified` remain factual and false until ownership is proven. The optional constructor input defaults to no exception, preserving existing internal callers and unverified legacy/bootstrap accounts.

Capabilities and actual reporting/audit authorization use the same admission value. The remaining staff email prerequisites in public-content/contact management, portfolio durable authority/imports and AI source authorization use it too, preventing another sign-in success followed by a role-permitted section failing solely on email. Customer submission, claim, documents, proposal and completion verification remain unchanged, as do verified-recipient requirements for ordinary notification delivery.

This grants no new permissions: Super Admin, Administrator and Project Manager retain their existing reporting/dashboard access. Support, Reviewer, Sales and Business Analyst do not acquire dashboard/reporting access from direct creation. All roles retain MFA, current-session validation, assignments/resource policies, CSRF, password confirmation, revocation and throttle rules.

No user row, password, MFA credential, role assignment or migration changes are required.

## Regression evidence

The strengthened real browser test failed against the previous runtime because `admin.dashboard.view` was absent. The prior browser test had checked the URL and successful `/identity/me`, but had not asserted the rendered dashboard or report response; its old pass was insufficient for dashboard access. That original evidence remains in the previous report.

The permanent test now asserts dashboard and reports capabilities, the visible reporting controls and rendered figures, a successful real dashboard report response and truthful `email_verified: false`, then completes password recovery. An initial post-fix assertion used an incorrect heading, `Overview`; the rendered page actually uses a personalized greeting and `Figures for this period`. The test was corrected to that observed heading without changing product code or removing the content assertions. MFA/CSRF and Overview regression tests passed in that run.

- Direct staff across all seven roles, legacy/customer negative cases, reporting/audit and ownership-versus-admission unit checks: **44 tests, 932 assertions passed**.
- PHPStan/Larastan level 10: passed. Composer strict validation and locked audit: passed, zero advisories. Pint source inventory: **673 files passed**.
- Dashboard: **629 unit tests / 65 files passed**; TypeScript, ESLint and OpenAPI/Redocly/generated-types validation passed. Dashboard application authorization code is unchanged; this correction is in the backend.

- Broader public-content/contact/import, AI domain/concurrency, inherited session/MFA/guest race, architecture and route/spec checks: **105 tests, 55,644 assertions passed**. AI API/owner-boundary regression: **19 tests, 354 assertions passed**. Total backend verification: **168 tests, 56,930 assertions passed**. Inherited security race files were not weakened or modified.
- Real-browser direct staff creation, MFA, actual rendered dashboard/report, factual unverified-email state and password recovery: **1/1 passed** after correcting the heading selector. Existing MFA/CSRF and Overview journeys: **2/2 passed**. The existing production-built dashboard was used; only its contract metadata/generated declarations and tests changed in this correction.
- Runtime Docker build and PHP-FPM configuration validation passed. All six local application processes were switched together to `holoul-app:staff-email-capabilities-runtime`, image `sha256:e73a74508bf5c6be821e5c489cff4d7dd53bc4f45fe358309b40911fc744db63`.
- Live readiness and `/admin` return 200. A read-only check of the affected account confirms Project Manager, enabled, MFA enrolled, `email_verified: false` and dashboard capability available. No role grant or account field was changed.
- Before/after counts match exactly: 2,546 users, 62 staff, 2,484 customer users, 40 invitations, 72 role assignments and 35 migrations. Runtime remains `holoul_app`. No migration or local fixture reset was run. Isolated test services were stopped; the normal local stack remains running.

Evidence is retained in backend `artifacts/staff-email-capabilities/` and dashboard `.data/staff-email-capabilities/`. Test fixtures run only against isolated PostgreSQL and port 8444. A transient WSL timeout during compatibility verification was retained; the subsequent check passed. The new hash began with a number, exposing an unquoted test map key during formatting; the key was quoted before the final passing typecheck/lint/unit runs.

## Contract

Version: `1.6.2-staff-accounts-candidate`, still 204 operations, 171 paths and 265 schemas.

- Previous SHA-256: `b89513b8b425bebe33e3f425e141037feebd841eddd19b42842bc2258d79bb02`.
- New SHA-256: `32fa258873794513849eb773a4c7bf41e4e2f8dd66ec7ef530877b775284386e`.
- No operation, parameter, response or schema additions/removals. The direct-creation description now explicitly documents the staff admission exception, unchanged role grants/MFA, truthful ownership and preserved customer/legacy controls. The internal admission value is not a new wire field.
- Accepted baseline `9119a094da23cc23c93475be79d076c4c1f2de21` operation definitions/components remain byte-equivalent in the compatibility comparison; the intentional behavioral change is documented for direct staff. Dashboard contract copy, generated types and hash pins match.

No deployment to production, database reset, account promotion, commit or push is part of this correction.
