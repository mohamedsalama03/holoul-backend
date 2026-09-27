# Staff onboarding — verification and handoff

Date: 27 September 2026. Classification: **BACKEND FEATURE READY FOR DASHBOARD INTEGRATION**.

This is a local functional/security candidate, not a Production Ready release. B8 production performance certification remains pending the target VPS. I6 inviter notifications are deferred as permitted by the requested ordering. No invitation UI, website/frontend feature or frontend contract-copy update is included.

## Candidate and history

- Accepted G1 baseline: `acd04c1dcf8d9ec0a66bdf88fe061fe6b7f340f6`.
- Implementation checkpoint: `16da33bdba245d3c7dbbc15dd790bc9cc0e87946` on `codex/staff-invitations`.
- Isolated checkout: `/home/mohamed/projects/customers/holoul-staff-invitations`.
- The verification document/evidence is a separate documentation checkpoint after that implementation commit. Existing B8/P1/P2/P3/F1-E1/G1 and failed performance evidence remains in the original history/checkout. Nothing was reset, squashed or labeled an accepted production release.
- The original application and edge remained healthy on the same image identities. Only the isolated candidate on `https://localhost:8444` used the disposable `holoul_test` database. Candidate containers were stopped after verification; the existing local application was not switched to this feature.

## What is available

T1 staff directory, T2 capability discovery, T3 versioned role/status editing, and I1–I5 invitation issue/list/resend/revoke/lookup/accept and assignable-role hints are implemented. See [the integration guide](STAFF-ONBOARDING-INTEGRATION.md) for a per-item response, confirmed defaults, precise refusals, and frontend sequencing.

Seven-day recipient-bound invitations are single use and stored only as hashes. Issuing authority is checked at issue and acceptance. Idempotent retries do not create another invitation/mail intent. Resend invalidates the old token and requires the current version. Existing staff/customer addresses are refused. Acceptance verifies email ownership and creates staff credentials; normal login and completed MFA remain mandatory for access. Unactivated invited administrators cannot satisfy the last-Super-Admin safeguard.

Support retains its approved grants and **does not gain dashboard access** through onboarding. Administrator can invite Support under the current grant rules. A Super Admin must choose another appropriate existing role when dashboard access is needed. No new target-user MFA-reset/session-revocation utility is added.

## Verification results

| Gate | Result |
| --- | --- |
| Full Unit + Feature suite on PostgreSQL | **958 tests / 12,916 assertions**, zero failures/errors/skips, before the final review refinements below. |
| Initial full Architecture suite | **9 tests / 45,007 assertions**, passed. |
| Final reviewed source: first focused run | 70 tests / 1,494 assertions; 69 passed, one test-harness synchronization failure. This failed attempt is retained, not represented as a passing run. |
| Corrected staff/revision/concurrency/upgrade run | **24 tests / 602 assertions**, zero failures/errors/skips. Includes the corrected boundary test and all staff onboarding tests. |
| Final focused coverage across those two runs | **70 distinct cases all passed in their latest execution**. This is combined coverage, not a claim that the earlier 70-test run passed. |
| Final Architecture suite | **9 tests / 44,995 assertions**, passed. |
| PHPStan/Larastan | **Level 10**, zero errors on final application source. |
| Pint | Full original gate passed; final review formatting applied, then the 16 affected files and corrected test were checked successfully. |
| Composer | Strict validation, installation/platform checks and locked audit passed; no advisories reported. No dependencies added or updated. |
| Migrations | Fresh **31 migrations**; exact accepted 30-to-31 upgrade, old values preserved, repeat migration, unused rollback and re-upgrade passed. Retained invitation-history rollback refusal tested. |
| OpenAPI | OpenAPI 3.1.1 valid; **182 operations**, **2,945 captured responses** validated, 155 observed operations, 131 operations with successful responses; all **126 required success samples** present. This is not a claim that every documented operation had a success sample. |
| Contract negative tests | **7 passed**, covering missing ETags, unexpected fields/media/body, legacy-error compatibility, token leakage and unreviewed staff reasons. Five rejection sentinels and one positive staff-reason sentinel also passed. |
| Route/spec drift | Actual runtime **182 = documented 182**; no missing or undocumented operations. Source manifest and deterministic build check passed. |
| Docker | Pinned development/runtime builds passed; actual non-root FPM and edge healthy, FPM configuration valid. Runtime uses `holoul_app`; migrations used the separate migrator identity. |
| Real same-origin HTTPS | **9 checks passed** with verified local TLS, real session/CSRF/MFA, durable queue delivery to local Mailpit, token acceptance, guarded editing and session revocation. |
| Existing frontend regression | **F1 auth 6/6 + Overview 1/1 = 7/7**, zero skips, retries or flaky cases; real backend, installed Chrome, traces/screenshots/video disabled. |

The corrected test hook originally looked for `count(*) as aggregate`; the pinned framework wraps the alias in quotes. The correction matches the count expression while retaining the actual delay and every state/count assertion. Application behavior was not relaxed to make that test pass.

Final review refinements after the full 958-test run were limited to five application/migration files: consistent PostgreSQL transaction-time expiry in list reads, the established two-attempt snapshot policy for the two new lists, canonical target-ID comparison for self-revocation metadata, preservation of the original revocation timestamp, date annotations, and removal of an unused local variable. The final focused checks cover these changes, including independent PostgreSQL connections that force a real session-touch serialization conflict on both new lists. The source manifests retain the exact before/after boundary.

The original identity session response/security files, wrapped-concurrency classifier, race tests and worker fixtures remain byte-identical to the accepted baseline. MFA enrollment adds the coordinated onboarding activation record; it keeps the existing session rotation/revocation path. Ordinary reads/public acceptance do not issue unchanged/replacement cookies; logged-in and pending-MFA browsers are refused without identity switching.

## Real runtime exercise

The actual runtime image was exercised through an isolated edge on port 8444, using generated identities only. The checks covered:

1. Super Admin password login, actual MFA enrollment, secure cookie flags and ordinary reads without replacement cookies.
2. Authorized invitation issue and idempotent replay with the runtime database role.
3. A real queue-worker send into local Mailpit and extraction of the fragment token in memory.
4. Anonymous CSRF enforcement, masked lookup and preservation of an already authenticated browser.
5. Single-use acceptance without login or cookie issuance, followed by normal invitee login/MFA and verified email.
6. Versioned authorization no-op, disable, stale-write rejection, and revoked-session failure without cookie clobbering.
7. Logout and rejection of the old authenticated cookie.

These are API/mail/runtime checks of the invitation workflow. The new recipient page and Invite dialog are not implemented or claimed to have browser coverage. The browser suite exercised the existing auth/overview screens against this candidate.

No secrets, raw tokens, synthetic MFA outputs or private HTTP capture files are published in this handoff. Detailed local logs remain under `artifacts/staff-invitations`; [the sanitized evidence](STAFF-ONBOARDING-EVIDENCE.json) records counts, hashes and scope.

## Frontend observation boundary

Dashboard HEAD was `400ca8fec2647d4931f5537aa04f05e687dd2535`, with existing working changes. Of 281 fingerprinted files, four category/status UI files changed concurrently during the run: `src/app/(dashboard)/categories/page.tsx`, `src/components/categories/category-catalog.tsx`, `src/components/dashboard/categories-board.tsx`, and `src/components/dashboard/status-badge.tsx`. This backend task did not edit them. The full frontend tree was therefore **not a frozen release snapshot**.

All 32 checked F1/E2E/auth sources and the frontend contract stayed unchanged during the test. The seven observed journeys passed against the running local dashboard; this is integration evidence, not certification of a complete frontend release. The frontend contract copy remains at `ae8d0a904a0042b80f9adc1224dedf8677794e2d49ca5cdf41daeb5278780be8`.

## Authoritative contract change

- Previous version: `1.1.0-g1`, 172 operations.
- Previous SHA-256: `ae8d0a904a0042b80f9adc1224dedf8677794e2d49ca5cdf41daeb5278780be8`.
- New version: `1.2.0-staff-candidate`, 182 operations.
- New SHA-256: `af3c96ac459717fb3ab4c86ac68513d4516e843e410de36820af2e188eded43d`.
- Existing operation/component definitions changed: **zero**. Existing Admin Dashboard wire contracts remain compatible. Metadata/version changes and the separate new schemas describe the additions.
- Schema additions: **17**. Existing schemas changed: **zero**.

New operations:

- `POST /api/v1/auth/staff-invitations/accept`
- `POST /api/v1/auth/staff-invitations/lookup`
- `GET /api/v1/identity/capabilities`
- `GET /api/v1/identity/staff`
- `GET /api/v1/identity/staff/invitations`
- `POST /api/v1/identity/staff/invitations`
- `POST /api/v1/identity/staff/invitations/{invitation}/resends`
- `POST /api/v1/identity/staff/invitations/{invitation}/revocations`
- `GET /api/v1/identity/staff/{user}/authorization`
- `PUT /api/v1/identity/staff/{user}/authorization`

Added schemas: `StaffAuthorizationReplaceInput`, `StaffAuthorizationReplaceResponse`, `StaffAuthorizationSnapshot`, `StaffAuthorizationSnapshotResponse`, `StaffCapabilitiesResponse`, `StaffDirectoryRecord`, `StaffDirectoryResponse`, `StaffErrorEnvelope`, `StaffInvitationAcceptInput`, `StaffInvitationAcceptResponse`, `StaffInvitationInput`, `StaffInvitationListResponse`, `StaffInvitationLookupResponse`, `StaffInvitationRecord`, `StaffInvitationResponse`, `StaffInvitationTokenInput`, `StaffPageMeta`.

The existing unguarded PATCH authorization operation is retained. Its successful mutations advance the revision, so new guarded PUT callers detect those writes; a legacy PATCH client can still overwrite a newer value. The guide explicitly avoids claiming system-wide optimistic concurrency while old writers remain.

## Runtime image identities

- Development: `sha256:ab0d6f816148a69f65c2e300c40200e7d43b011a48612ee54ee007378f49c104`.
- Runtime: `sha256:5e5517044af9c3a9e9dd814fcbef6c80ef2e4b53ca608783acdd4327acc188b2`.
- Offline contract validator: `sha256:978401473245f5ad792a821dc37dd3e685d1dc600d9bd6f4f14edc96a1f38202`.

## Remaining scope and rollout

I6 inviter acceptance/expiry notifications remain deferred; authorized list state and append-only audit provide lifecycle evidence. Production SMTP/domain deployment and VPS performance certification are not performed by this feature batch.

Apply migration 31 with the migrator identity before activating the candidate. Coordinate web/default-queue/scheduler replacement during a pause so an older worker cannot consume the new operation kind. The integration guide defines public-page CSRF, fragment handling, explicit sign-out conflicts, normal login/MFA, and capability/version handling. The frontend team can now implement against the authoritative contract; its copy has intentionally not been changed here.

**Candidate awaiting review and dashboard integration. Production performance certification remains pending the target VPS.**
