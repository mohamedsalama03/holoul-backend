# Staff onboarding: local dashboard integration

This follow-up is restricted to local activation of the reviewed staff candidate and synthetic E2E fixture support. Role grants, application/security implementation, migrations and OpenAPI remain unchanged from the reviewed staff candidate. The G1 VPS freeze is a separate branch and is not replaced by this work.

## Per-item response

| Item | Reply | Decision |
| --- | --- | --- |
| L1 | done | Apply migration 31 as holoul_migrator during a coordinated local pause, then run web, all queue workers and scheduler on one staff runtime image. Mailpit remains local. Exact runtime identity and activation checks are recorded in the verification receipt. |
| L1 acceptance | use the unchanged staff contract as the dashboard integration baseline after F1 and Overview pass | af3c96ac459717fb3ab4c86ac68513d4516e843e410de36820af2e188eded43d, API 1.2.0-staff-candidate, OpenAPI 3.1.1, 182 operations. A running backend alone does not establish frontend regression acceptance. Keep the contract adoption in its own dashboard commit and record its passing regression. This is not production acceptance and does not merge staff onboarding into the G1 VPS candidate. |
| L2 / C2 | done | Five synthetic identities: Project Manager, Project Manager pending enrollment, customer, Super Admin, Administrator. The three enrolled staff accounts use real MFA. Reset restores only these five exact manifest-owned identities, their fixed roles/enabled state, and revokes old sessions. Existing three passwords/run remain stable during manifest upgrade. New credentials are generated independently. MFA secrets rotate on reset. |
| L3 | use Mailpit at http://localhost:8025 | Windows access and recipient-scoped search are verified. Read the matching message and fragment token in memory; no new token-to-file helper is needed. |
| L4 | use unique synthetic recipients and retain history locally | Yes, per-run invitation history and accepted synthetic accounts may remain in the local database. Reset does not delete/revoke/disable arbitrary recipients by name pattern. A test can revoke its own pending invitation or disable its own accepted test account through the normal guarded API, keeping exact IDs and current ETags. Do not touch human accounts or another run's records. |
| C1 | use the current documented order for this activation | adminCustomerList remains oldest registrations first. Newest-first is not included: it changes an existing contract behavior and therefore requires a separate reviewed contract change/hash. No grant policy or existing operation changes are hidden inside this local enablement. |
| C3 | use exact-ID taxonomy deactivation through the API | Reset does not delete or mutate rows merely because the slug starts with e2e-. Create unique per-run slugs, retain the returned category/subcategory IDs and ETags, and deactivate only those rows through the existing PATCH operations. Re-read a stale ETag rather than silently overwriting a concurrent edit. Historical references and audit remain. Admin lists include inactive entries; tests must not assume an empty database. |

## Run the reset

From the staff worktree in WSL:

```bash
cd /home/mohamed/projects/customers/holoul-staff-invitations
python3 scripts/local-e2e.py --reset --frontend-env '/mnt/d/customers/holoul frontend/dashboard/.env.e2e.local'
```

The default fixture image is holoul-app:staff-local-development. An explicitly reviewed development image can be selected with --image. The actual local application uses the runtime image; fixture tooling remains excluded from runtime. Local-only environment, exact origin, Mailpit, runtime database identity and ownership checks remain mandatory. Do not use the old G1/F1 fixture script for the five-account setup.

Credentials are written only to ignored artifacts/local-e2e-private/frontend.env and the owned ignored dashboard .env.e2e.local. Never copy these files into a handoff or commit. Existing keys stay available. Additional keys are:

- HOLOUL_E2E_SUPER_ADMIN_EMAIL / HOLOUL_E2E_SUPER_ADMIN_PASSWORD / HOLOUL_E2E_SUPER_ADMIN_TOTP_SECRET
- HOLOUL_E2E_ADMIN_EMAIL / HOLOUL_E2E_ADMIN_PASSWORD / HOLOUL_E2E_ADMIN_TOTP_SECRET
- HOLOUL_E2E_MAILPIT_URL and HOLOUL_E2E_RUN (non-secret local test metadata)

Serialize suites that share these identities; reset invalidates active fixture sessions and rotates MFA. Do not reset while another E2E run is using the same file. The shared local database contains other users. The last-Super-Admin refusal requires a controlled identity set; the backend PostgreSQL regression verifies that case. Dashboard tests must not disable unrelated administrators merely to manufacture the condition.

## Mailpit API

1. Generate a unique address such as e2e-invitee-<run>-<case>@example.test.
2. Poll GET http://localhost:8025/api/v1/search?query=to%3A<url-encoded-recipient>&limit=50.
3. Select the exact To address and subject Your HOLOUL staff invitation, scoped to the current run. Read GET /api/v1/message/<ID> and recheck To.
4. Parse only the expected https://localhost:8443/admin/invitation#token= fragment from Text. Keep the token in memory, remove it from browser history after the recipient page reads it, and POST it in the API body after normal CSRF bootstrap.

Do not log response bodies, tokens, cookies, passwords, TOTP keys or recovery codes. Keep traces/screenshots/video off for security journeys. Mailpit is the local sandbox; this workflow grants no access to production mail and never uses a real recipient.

## Local runtime configuration

compose.staff-local.yaml selects the candidate for web, default/documents/AI/notifications workers, scheduler and migrator, and the development image for verification. Pair it with the original local topology so the existing ingress and data volumes remain intact:

```bash
docker compose -p holoul \
  -f /home/mohamed/projects/customers/holoul/compose.yaml \
  -f /home/mohamed/projects/customers/holoul-staff-invitations/compose.staff-local.yaml ps
```

Use this pair for subsequent local service operations; the old checkout's defaults alone still name older images. Never run migrate:fresh on the shared holoul database. Verification uses holoul_test; the local rollout uses additive migration 31 and a private pre-migration backup. This is not a VPS deployment profile.

No frontend source or contract copy is edited by this task. The dashboard owns the contract-adoption commit and its acceptance record. Executed checks and retained failures are recorded below and in STAFF-LOCAL-VERIFICATION.json.

## Verified local result — 28 September 2026

Local staff integration is enabled on https://localhost:8443. This is not a Production Ready release and does not alter the frozen G1 VPS candidate or its pending production performance certification.

- Reviewed source baseline: 0ef734cd9cc2cd3a0794b6b1f92d1d0793914f72 (staff implementation 16da33bdba245d3c7dbbc15dd790bc9cc0e87946).
- Runtime reference: holoul-app:staff-local-runtime.
- Runtime image ID: sha256:d23f7a9292d19102a5e335c4c082b40f1870e019450e56156767b273c5ad7198.
- Development fixture image ID: sha256:7e54a8a2a340b7da0d5a5dafbddee4ece5e513c3afa4c8a369b1db09d1833b87.
- All six PHP services (web, default/documents/AI/notifications workers, scheduler) were verified healthy on the same runtime image, including after Docker recovery.
- Shared database holoul retains 31 migrations; last is 2026_09_27_000000_add_staff_onboarding. Migration ran as holoul_migrator during a coordinated pause, after a private pg_dump backup and archive verification. Runtime continues to use holoul_app. No fresh migration or rollback ran on shared holoul.
- Previous and current authoritative contract SHA-256 are identical: af3c96ac459717fb3ab4c86ac68513d4516e843e410de36820af2e188eded43d. This follow-up adds zero operations and changes zero schemas. The reviewed staff contract remains 182 operations. The image carries the matching contract label and its API routes match the specification; this does not introduce an OpenAPI download endpoint.
- Frontend contract copy remains ae8d0a904a0042b80f9adc1224dedf8677794e2d49ca5cdf41daeb5278780be8. All 291 recorded frontend source files remain unchanged by this task. Its private E2E environment was refreshed; its protected Windows ACL allows only the current user and SYSTEM.
- Original backend checkout HEAD/status remain unchanged. release/g1-vps-candidate remains clean at b6d3f27ee9fbde274b04a75c4d4ece35aa8e4de0.

### Executed verification

| Check | Result |
| --- | --- |
| Python manifest migration and ownership guards | 4 passed |
| Unit suite | 144 tests / 358 assertions passed |
| Focused PostgreSQL feature/security/concurrency coverage | Latest result for 69 cases / 2,122 assertions passed; see retained initial failure below |
| Corrected fixture suite | 5 tests / 130 assertions passed |
| Architecture | 9 tests / 44,995 assertions passed |
| PHPStan/Larastan | Configured application paths, level 10, zero errors |
| Pint | 589 files passed |
| Composer | Strict validation, platform requirements, audit passed; zero advisories |
| Migrations | Fresh 31 and repeat on holoul_test; exact 30-to-31 upgrade regression passed |
| OpenAPI and route drift | Pinned structural validator and deterministic source validation passed; 182/182 routes |
| Source vulnerability and secret checks | Passed, including development dependency scan; zero HIGH/CRITICAL findings |
| Docker | Development/runtime builds passed; runtime is non-root, FPM config valid, fixture tooling/PHPUnit/.env/artifacts excluded |
| Real staff invitation flow | 14 checks passed through 8443, real queue delivery and recipient-scoped Mailpit retrieval; token remained in memory |
| Existing frontend F1 and Overview | 7/7 passed after infrastructure recovery: F1 6/6, Overview 1/1; no retries, test edits or timeout changes |
| Final readiness | /health/live, /health/ready, /admin/login, /api/v1 and Mailpit API return 200 |
| Final fixture state | Five enabled identities, exact roles, three confirmed MFA enrollments, pending-enrollment identity restored; private exports match |

Focused PostgreSQL cases include LocalE2EFixtureTest, StaffInvitationsTest, StaffOnboardingConcurrencyTest, StaffOnboardingUpgradeTest, SessionLifecycleTest, GuestSessionIsolationTest, TaxonomyHttpValidationTest and AdminIntegrationTest. The full inherited backend suite was not rerun for this bounded fixture/local activation follow-up. Its historical verification remains intact.

The live invitation test verified Administrator's Support-only limit and catalogue write, rejection of a Super Admin invitation by Administrator, Super Admin's assignable roles, authenticated public-lookup isolation, anonymous lookup/acceptance without automatic sign-in, replay refusal, real invitee MFA enrollment, and guarded deactivation of only the new synthetic invitee. That invitee is disabled and its invitation history retained; the test category is inactive.

### Failures retained and recovery

The first focused feature run had 69 tests, 2,112 assertions and one teardown error: the new fixture test used rollback cleanup after creating retained invitation history. The migration correctly refused deletion of that history. Only the test fixture was changed to the existing holoul_test-only CommercialDatabase setup. Its five cases then passed with 130 assertions; the combined latest coverage is 69 passing cases. The first run is not reported as passing. No application, migration or security guard was weakened.

The first frontend attempt recorded two passed, two failed and three not run. Windows C: filled and Docker became unavailable around that attempt. A subsequent strict runtime check also failed because the Docker socket was unavailable; its output is retained. After the owner removed the package cache, Docker startup exposed stale zero-byte Windows socket files and a stale WSL disk attachment. The socket directories were preserved by renaming and WSL was restarted. Docker volumes and databases were retained, without factory reset or data recreation. The same runtime image, migration 31, runtime isolation checks and all seven unchanged frontend tests then passed. The final reset was run after the suite so the pending MFA account is ready for the dashboard's next test run.

Raw logs, the private database backup and test credentials remain in ignored private evidence directories. This report and its JSON receipt contain no invitation tokens, passwords, TOTP secrets or recovery codes. Docker recovery used the parent-directory approach described in the [upstream stale socket report](https://github.com/docker/for-win/issues/15064); local post-recovery checks establish the result here.

The dashboard can adopt the unchanged staff contract in its own commit and run its acceptance checks before Team work. The existing F1/Overview regression has passed against the live candidate; acceptance of the frontend contract-adoption commit remains with that team. Production acceptance remains separate.
