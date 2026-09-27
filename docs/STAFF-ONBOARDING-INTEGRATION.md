# HOLOUL staff onboarding — backend integration candidate

Baseline: `acd04c1dcf8d9ec0a66bdf88fe061fe6b7f340f6` (accepted G1). This batch implements T1–T3 and invitation items I1–I5. It does not implement dashboard pages, change frontend contract copies, add project/audit features from T4, or certify production performance.

The candidate is isolated on `codex/staff-invitations`. Existing accumulated work and historical B8 performance evidence remain in the original checkout. The new contract is `1.2.0-staff-candidate`, with 182 operations: the previous 172 plus ten additions. Existing operation definitions and strict response schemas remain unchanged. New reasons/fields appear only on the new operations.

## Replies and product defaults

| Item | Reply | Implemented behavior |
| --- | --- | --- |
| T1 | change | Staff directory with server filtering, cursor, filtered total, and truthful MFA/onboarding fields. |
| T2 | change; use GET `/identity/capabilities` | Current/confirmable capabilities and assignable roles; existing `/identity/me` stays compatible. |
| T3 | change; use the new GET/PUT authorization pair | ETag/If-Match, fixed refusal reasons, no-op detection and explicit self-session revocation metadata. Legacy PATCH is retained. |
| D | use the current grant policy; confirm with the adjustments below | Seven days, fixed offered roles, separate staff work email, backend email, explicit normal login and MFA. |
| I1 | change | Idempotent issue with recent password; one pending invitation per normalized email. |
| I2 | change | Authorized list with status/search/cursor/total, inviter name snapshot, delivery progress and record ETag. |
| I3 | change | Explicit guarded resend/revoke, immediate invalidation of previous link, persistent resend limits. |
| I4 | change | Anonymous same-origin CSRF-protected lookup/accept; generic unusable-token response; no automatic login. |
| I5 | change | `assignable_roles` in the new capability response; both actor and target restrictions remain authoritative. |
| I6 | won’t do in this batch | Inviter acceptance/expiry notifications are deferred, as the requested ordering permits. Use the invitations list for status; lifecycle audit evidence is implemented. |

Confirmed: current Super Admin can invite any staff-role combination; current Administrator can invite Support only. This is derived from grants and security-role policy, not a hard-coded role-name shortcut. All issue/resend/revoke operations require completed MFA, staff read/manage grants and password confirmation within 300 seconds. Original inviter authority is checked again at acceptance; resending also rechecks it. Disabled/demoted inviters cannot authorize a later acceptance beyond their current authority.

Adjustments:

- A repeated issue with the **same idempotency key and canonical input** returns the same invitation in its current state, with no extra mail. A different issue key for an already pending email returns `INVITATION_ALREADY_PENDING` and `error.resource_id`. Resend is explicit and version-checked; it never happens as a side effect of a duplicate click.
- Offered roles, recipient, name and inviter are immutable. Revoke and issue a new invitation to change offered roles. A resend retains them and starts a new seven-day window. Expired invitations require a new issue, not resurrection. Accepted accounts use normal guarded role/status editing, including before their first MFA enrollment.
- Acceptance proves possession of the invited email token, creates verified staff credentials and records the offered role grants. It does not log in. No staff access is permitted until the existing MFA flow succeeds. An accepted invitation with incomplete first MFA does not count as the alternate enabled Super Admin when removing/disabling the last usable one. Pending invitations create no user and never count as enabled staff.
- `accepted_at` means credentials were created; `activated_at` means first MFA completed. A completed acceptance is not retroactively undone when the inviter later loses authority; disable or edit that employee through T3 if required.
- **Support still lacks `admin.dashboard.view` under the accepted role catalogue.** It can complete onboarding but the dashboard must show its existing access-denied state. Onboarding does not grant reporting or business permissions. A Super Admin must select an appropriate existing role for staff who need dashboard access. This batch does not change role grants or entry policy.
- Email uses the existing backend plain-text HOLOUL mail transport/style, with an invitation fragment link and absolute expiry. No passwords appear in mail. No production SMTP provider or domain deployment is configured by this feature.

## Operations

All paths below are relative to the same HTTPS origin. Standard session/CSRF and origin requirements remain. UUIDs are staff/invitation IDs, never customer IDs.

| Operation ID | Method/path | Access |
| --- | --- | --- |
| `identityListStaff` | GET `/api/v1/identity/staff` | Staff read + MFA |
| `identityGetCapabilities` | GET `/api/v1/identity/capabilities` | Current authenticated identity; completed MFA for staff |
| `identityGetStaffAuthorization` | GET `/api/v1/identity/staff/{user}/authorization` | Staff read + MFA |
| `identityReplaceStaffAuthorization` | PUT `/api/v1/identity/staff/{user}/authorization` | Read/manage + MFA + recent password + If-Match |
| `identityIssueStaffInvitation` | POST `/api/v1/identity/staff/invitations` | Same management policy + Idempotency-Key |
| `identityListStaffInvitations` | GET `/api/v1/identity/staff/invitations` | Staff read + MFA |
| `identityResendStaffInvitation` | POST `/api/v1/identity/staff/invitations/{invitation}/resends` | Management policy + If-Match |
| `identityRevokeStaffInvitation` | POST `/api/v1/identity/staff/invitations/{invitation}/revocations` | Management policy + If-Match |
| `identityLookupStaffInvitation` | POST `/api/v1/auth/staff-invitations/lookup` | Anonymous CSRF session, body token |
| `identityAcceptStaffInvitation` | POST `/api/v1/auth/staff-invitations/accept` | Anonymous CSRF session, body token/password/confirmation |

Use the authoritative OpenAPI for exact schemas. The existing PATCH authorization operation keeps its wire behavior. New Team editors should use the snapshot GET and guarded PUT. Both writers advance the authorization revision on real changes; old unguarded PATCH writers can still overwrite newer values. Do not claim system-wide stale-write protection while legacy clients remain.

## Directory, capabilities and edits

Staff list: `q`, `role`, `enabled`, `cursor`, `limit`. Invitation list: `q`, `status`, `cursor`, `limit`. Limit defaults to 25 and is capped at 100. Fixed `id ASC`; opaque cursors bind filters. Search is trimmed literal case-insensitive substring across name/email, not fuzzy or full-text search; `%`, `_` and backslash are literals. Total counts matching rows before pagination. It is a filtered count, not per-role/global counters.

Directory fields add `created_at`, `mfa_enrolled` and `onboarding_pending` in a new schema. No fabricated `last_sign_in_at`: current session creation times also reflect security rotations and cannot truthfully serve that purpose.

Load the new capabilities endpoint after full authentication. Gate Team with `staff.view`; offer an invitation action when `staff.invitations.manage` is current or confirmable. Password confirmation should happen on action, followed by capability refresh. `confirmable_capabilities` contains only currently missing capabilities that recent confirmation would unlock. Confirmation cannot fix missing grants, MFA, verification or resource authority.

`assignable_roles` is an actor-level hint. For an existing target, all existing roles and all requested roles must be within the actor’s grant authority. In particular, an Administrator cannot edit another Administrator or itself merely because Support is assignable. Both `super_admin` and `administrator` are security roles; changing an existing/requested security role needs Super Admin plus `identity.security.manage`. The last-admin check remains dynamic.

Read the authorization snapshot ETag, send both full `roles` and boolean `enabled` with If-Match. Missing header is 428; malformed is 422; stale is 412. Reload and ask the operator to review on stale state; never silently retry with a fresh ETag. A no-op does not revoke sessions. PUT returns `meta.changed` and `meta.current_session_revoked`. Scope clearing of UI identity to the initiating session; a late response must not sign out a newer login. Do not send a cleanup logout after self-edit against whichever session happens to be current.

## Invitation form and recipient page

Issue body: `email`, `full_name`, `roles`. Use a UUID idempotency key, retain it for an uncertain retry, and generate a new key for a genuinely new command. Current accepted key syntax is 16–128 characters from letters/digits/underscore/hyphen. Successful issue is 201 and returns the record and ETag, never a token or plaintext password.

The record includes email/name/offered roles/status, inviter ID and name captured at issue, creation/expiry, acknowledged send count/time, accepted time/user ID, activation time, revoked time, delivery status and ETag. Delivery status is `pending`, `sending`, `sent`, `uncertain` or `discarded`. “Sent” means the SMTP transport acknowledged acceptance, not proof of inbox delivery. Delivery progress changes the ETag; refresh before resend/revoke.

Email link: `/admin/invitation#token=<secret>`. The frontend recipient page is not implemented by this backend batch. Read the fragment into memory, remove it from browser history, and POST it in the body. Do not put it in a query string, analytics, logs, screenshots or persistent browser storage. Use the standard `/sanctum/csrf-cookie` bootstrap before POSTs. Lookup returns invited name, masked email, offered role codes/labels, inviter name, expiry and password policy.

Password policy is the existing Identity policy: 12–128 characters, lowercase and uppercase letters and a number; matching `password_confirmation`. The recipient cannot override email, name, roles, ownership or verification fields. Acceptance returns `next_step: sign_in`. Continue through the existing password login and MFA enrollment. Do not probe protected identity endpoints while MFA is pending.

If a browser is already authenticated or has pending MFA, both public operations refuse with `INVITATION_SIGN_OUT_REQUIRED`, preserving that session. Explain the choice to the user. A fully signed-in user can explicitly sign out, initialize CSRF again and continue; for pending MFA, finish the existing flow or use a separate private browser context. The invitation flow never silently changes the current identity.

Invalid, expired, revoked, consumed and no-longer-authorized tokens share 422 `INVITATION_UNAVAILABLE`. Token validity is not disclosed by differing password-validation errors. An email taken in the meantime also makes public acceptance unavailable. A valid token with an invalid password returns bounded form field errors.

## Limits and error handling

- Issue: 20 attempts per authenticated actor/hour.
- Resend/revoke: 30 attempts per actor/minute.
- Resend: at least 60 seconds between requests and at most five mail generations per invitation in a rolling 24 hours, counting the first issue. These limits are persisted in PostgreSQL.
- Public lookup/accept share IP limits of 30/minute and 120/hour. The Redis limiter fails closed with 503 if unavailable. Existing edge and security limits also apply.

The new error envelope retains broad `error.code` and adds optional fixed `error.reason`. Middleware failures can omit the reason, so keep a generic fallback. Never parse exception messages or interpret every 403 as session expiry.

Useful reasons: `ROLE_AUTHORITY_EXCEEDED`, `SECURITY_ROLE_RESTRICTED`, `STAFF_CUSTOMER_ROLE_MIX`, `LAST_ENABLED_SUPER_ADMIN`, `PASSWORD_CONFIRMATION_REQUIRED`, `EMAIL_ALREADY_STAFF`, `EMAIL_ALREADY_CUSTOMER`, `INVITATION_ALREADY_PENDING`, `IDEMPOTENCY_KEY_REUSED`, `INVITATION_ALREADY_ACCEPTED`, `INVITATION_ALREADY_REVOKED`, `INVITATION_EXPIRED`, `INVITATION_RESEND_LIMIT`, `INVITATION_SIGN_OUT_REQUIRED`, and `INVITATION_UNAVAILABLE`. Email-account distinctions and pending invitation IDs are exposed only through authorized staff commands, not public token lookup.

## Persistence and delivery

Migration 31 adds an authorization revision and four Identity-owned tables. Offered roles use relational staff-role FKs and are sealed at commit. Recipient/inviter identity and terminal history are protected by database guards. Runtime permissions prevent deleting invitation history or modifying offered role rows/idempotency mappings. An unused migration can roll back; retained invitation history requires forward recovery.

Apply migration 31 with the separate migrator identity before activating this image. Switch web, default queue workers and scheduler to the same candidate during a coordinated pause; do not let older workers consume the new invitation operation kind. Resume intake only after the new workers and routes are healthy. This handoff does not switch the existing local application or any production deployment.

Only token hashes are stored. The worker generates a token in memory when preparing one mail attempt, commits its hash and sending fence, then sends outside the transaction. PostgreSQL records durable intent; Redis carries identifiers only. Resend/revoke/accept invalidate the previous capability transactionally. A crashed or uncertain SMTP attempt is not blindly retried: the operator explicitly resends. An old message already in transport may arrive after revoke/resend, but its link is invalid.

The existing default queue worker handles `identity.staff_invitation_mail`. The scheduler runs `identity:expire-staff-invitations` every minute in batches of 100. Expiry is enforced against the database clock even before reconciliation. Audits cover issue, resend, revoke, acceptance, first MFA activation, expiry, and sent/uncertain/discarded delivery without storing tokens, passwords or recipient content in audit metadata.

No target-user MFA-reset or dedicated forced-sign-out endpoint is added. Do not simulate one by toggling roles/status. I6 user notifications are deferred; audit events and invitation records remain the source of lifecycle evidence.

Verification results and the final candidate/contract hashes are recorded separately in `STAFF-ONBOARDING-VERIFICATION.md` when the gates finish. Production performance certification remains pending the target VPS.
