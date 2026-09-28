# HOLOUL — response to “Connecting the Team section”

Reviewed: 2026-09-27.

“change” means a verified gap and recommended scope for the next authorized backend batch. It does not mean the endpoint exists or that implementation has started. “won’t do” below is limited to this Team integration scope unless stated otherwise. This reply follows the earlier Dashboard API-gap review.

Baseline: accepted backend candidate `acd04c1dcf8d9ec0a66bdf88fe061fe6b7f340f6`; OpenAPI `1.1.0-g1`, 172 operations; SHA-256 `ae8d0a904a0042b80f9adc1224dedf8677794e2d49ca5cdf41daeb5278780be8`. Dashboard HEAD is `400ca8fec2647d4931f5537aa04f05e687dd2535`, with additional existing local edits. Those edits were left untouched.

| Item | Reply | Integration consequence |
| --- | --- | --- |
| T1 | change | Add an authorized staff directory; retain the notice until published. |
| T2 | change | Add staff visibility and discoverable password-confirmable capabilities without role inference. |
| T3 | change; use existing security rules | Add a guarded edit contract and readable denials; preserve the accepted legacy operation. |
| T4 | use existing scoped request/audit APIs; change project filtering | No automatic expansion of Administrator business or audit access. |
| T5 | won’t do in this batch; recommend Option A separately | Keep Invite and target-user security actions hidden. Option B is not an implemented routine provisioning workflow. |

## T1 — change: staff directory

Confirmed: `identityGetStaff` requires an ID, and neither eligible-assignee picker is a directory.

Recommended new operation: `identityListStaff`, `GET /api/v1/identity/staff`.

- Authorization: current enabled staff, completed MFA and `identity.staff.read`; no recent-password requirement. Return staff only, including disabled staff when requested. Read access does not grant management authority.
- Query: `q`, `role`, `enabled`, `cursor`, `limit`. Proposed `q`: trim, reject control characters, 2–100 characters; case-insensitive literal substring over full name and normalized email. Escape `%`, `_` and backslash; no regex, wildcard or fuzzy matching. Match the complete query against either field. No Arabic spelling/diacritic-equivalence promise.
- `role`: one exact staff role; `enabled`: optional boolean. Reject invalid values. Default includes all staff statuses. Bound limit to 1–100, default 25.
- Stable ordering: fixed `id ASC` with an opaque cursor bound to the normalized filters and sort. Names can change, so alphabetical ordering is deliberately not promised for this first operation. This is live pagination, not a snapshot retained across page requests.
- New directory record: existing `id`, `full_name`, `email`, `enabled`, `roles`, plus `created_at` and boolean `mfa_enrolled` derived from confirmed enrollment. Enrollment does not indicate whether that person's current session has completed MFA. Do not expose MFA material or session records.
- Metadata: `next_cursor`, `limit`, `total`. Total means the number of distinct staff matching the same authorized filters before the cursor, including multi-role users only once. Page and total should share one database snapshot. A filtered total is not a global disabled/per-role summary.

Use a new directory schema. Keep the existing strict `IdentityStaffRecord` response unchanged.

**Do not include `last_sign_in_at` yet.** There is no dedicated durable last-interactive-sign-in field. Session `authenticated_at` and `identity.login_succeeded` are also produced during security rotations; sessions are later removed. Neither maximum is an accurate substitute. A future field needs explicitly recorded completed sign-ins and null for unknown history.

The new read must use bounded queries, private/no-store responses and the approved session middleware: no unchanged session-cookie reissue and no recovery of a stale session.

## T2 — change: capability discovery

Add `staff.view` from `identity.staff.read`, available without recent password after the normal staff session/MFA checks.

Add `confirmable_capabilities`, defined as capabilities that the current identity would gain **solely** by confirming its password now. Exclude capabilities already available. Do not suggest confirmation can fix missing grants, incomplete MFA, unverified email, disabled configuration, a revoked session or target-specific restrictions.

For staff authorization, discovery must reflect both effective HTTP prerequisites: `identity.staff.read` and `identity.staff.manage`. The existing controller performs a read check before the mutation. Capabilities remain hints; fresh server authorization and the rules in T3 decide each edit.

**Compatibility recommendation:** introduce `identityGetCapabilities`, `GET /api/v1/identity/capabilities`, returning current `capabilities`, `confirmable_capabilities` and the existing recent-password status. Use the same current-session authorization model as `/identity/me`. Team can load this once with session identity and refresh it after confirmation; no per-row capability calls.

Reason: the accepted `/identity/me` schema has `additionalProperties:false` and a closed capability enum. Unconditionally adding a property or `staff.view` there is not backward compatible for strict clients. A new endpoint preserves the old response exactly. Putting these additions directly on `/identity/me` instead requires a deliberately negotiated/versioned contract and coordinated client migration.

UI behavior after that new contract is delivered:

1. Gate Team on `staff.view` from the new capability response.
2. Offer a management action when `staff.authorization.manage` is current or confirmable; its availability is not permission to edit every target or grant every role.
3. If confirmable only, use `identityConfirmPassword`, refresh capability state and perform the guarded mutation with the normal CSRF handling.
4. On expiry, prompt only for the documented password-confirmation reason. Do not treat every 403 as expired authentication or a request to confirm again.

Current `capabilities` retains its meaning: actionable now, subject to resource policy. No role-code inference or permission grants are proposed.

## T3 — change: readable failures and concurrent edits; use existing self-edit policy

The current rules are stricter than “cannot grant a stronger new role”:

- Security roles are exactly `super_admin` and `administrator`.
- If either the target's existing roles or requested roles contains a security role, the actor must have **both** `super_admin` and `identity.security.manage`. The permission alone is insufficient. This also governs disabling a security-role holder or removing that role.
- The permissions of the union of existing and requested roles must be within the actor's grants. Removing a role does not bypass this check.
- A staff account cannot receive `customer`. A non-staff target is not a valid staff resource.
- An enabled Super Admin cannot be disabled or lose that role unless another enabled Super Admin exists. This guard counts enabled assignments; it does not certify another account's recovery readiness.
- MFA, read/manage grants and password confirmation within 300 seconds remain mandatory. Actor authority is checked again after acquiring the authorization locks.

Thus an Administrator may have the management capability yet be unable to manage another Administrator or assign most business roles. Do not present it as unrestricted staff administration.

For the proposed guarded operation, keep the existing HTTP status and broad `error.code`, and add a bounded, documented `error.reason`:

| HTTP / code | Proposed reason | Meaning |
| --- | --- | --- |
| 409 / CONFLICT | LAST_ENABLED_SUPER_ADMIN | Change would remove the last enabled Super Admin. |
| 403 / FORBIDDEN | ROLE_AUTHORITY_EXCEEDED | Existing/requested role permissions exceed the actor's authority. |
| 403 / FORBIDDEN | SECURITY_ROLE_RESTRICTED | Security-role management requires Super Admin plus the security grant. |
| 403 / FORBIDDEN | STAFF_CUSTOMER_ROLE_MIX | Customer role cannot be assigned to staff. |
| 403 / FORBIDDEN | PASSWORD_CONFIRMATION_REQUIRED | Otherwise authorized action requires recent confirmation. |

These are **proposed reason values, not currently returned values**. Expose specific target reasons only after the appropriate authentication and access checks. Preserve generic 401/403/404 fallbacks and bounded 422 validation errors. Never return raw exception messages or disclose hidden targets. If multiple rules fail, return the first applicable authorized check; no completeness guarantee is needed.

Concurrency currently serializes authorization writes and protects the last-Super-Admin invariant. It does **not** compare the browser's version. A stale full-array edit can overwrite an earlier edit if it remains authorized. That is current behavior, not the intended Team editing experience.

Requiring `If-Match` on the accepted PATCH would break existing callers. Recommended compatible addition:

- New `GET /api/v1/identity/staff/{user}/authorization`: read the authorization snapshot and its strong ETag.
- New `PUT` at that path: replace roles/enabled with mandatory `If-Match`; missing header => 428 `PRECONDITION_REQUIRED`; stale version => 412 `STALE_VERSION`.
- Check the expected version inside the existing transaction/authorization locks. Use a monotonic authorization revision; both legacy PATCH and new PUT must advance it on real changes. No-op writes do not revoke sessions or advance it.
- Keep the existing GET staff response and PATCH wire behavior unchanged. Give the new operation its own documented error schema, because the legacy error envelope also rejects additional properties.
- Team uses the guarded PUT. After 412, reload and ask the operator to review; never silently reapply the stale full array. Legacy PATCH can still overwrite a newer edit, so this is not system-wide lost-update prevention while old writers remain. Retiring legacy writes needs a separate coordinated contract decision.

Self edits remain allowed subject to exactly the same rules. A real self-change revokes the current session and other sessions; an unchanged submission does not. The proposed guarded response should explicitly report whether a change occurred and whether the initiating session was revoked, so the UI can explain the result accurately. A delayed response must not sign out a newer login: scope UI clearing to the initiating session, do not dispatch a cleanup logout against whichever session is current, and preserve the server's no-cookie-on-revoked-response behavior. A disabled account cannot sign in again until re-enabled.

## T4 — use scoped APIs; change project filter; won’t broaden audit access

Use `intakeStaffList?assigned_staff_id={userId}` where the viewer has the relevant intake capability and resource access. Add `member_id={staffUserId}` to `projectStaffList` with earlier gap #3. It should mean active membership by staff user ID, not the membership-row ID, and intersect the caller's existing project scope. It must not grant access to every project that colleague can see.

Use `auditEvents?actor_id={userId}` only when the viewer has `audit.investigate` and meets its session/verification requirements. Actor means **performed by**, not every change **about** that colleague. Changes about a staff identity may have another actor and must not be described as that colleague's own activity.

Do not grant Administrator the audit permission as a shortcut. A staff filter on a broad audit reader is not a privacy boundary. A future narrow security-history feed needs explicit allowed events/fields and its own authorization review; gap #8 display enrichment does not authorize that feed.

Correction to the fallback page: the single `administrator` role currently has neither `intake.read`, `projects.read` nor `audit.investigate`. For that role alone, show identity/roles/access; show assigned requests or projects only when additional actual capabilities and scope permit them. Role combinations may change this. Restricted panels are not “zero results.”

## T5 — won’t do invitations or target-user security tools in this batch

Keep Invite hidden. Recommend **Option A as a separate approved staff-onboarding feature**, not a prerequisite for the directory. This is a recommendation, not a completed or authorized invitation lifecycle. Do not treat Option B as a permanently decided product policy.

The existing `identity:bootstrap-super-admin` command provisions only the initial Super Admin and refuses when any Super Admin assignment already exists. It is not routine staff creation. Customer registration and direct role-table edits are not valid substitutes.

A later invitation batch must cover issue/list/resend/revoke/accept, recipient-bound expiring single-use tokens stored as hashes, fresh grant/security-role checks at issue and acceptance, verified ownership and required MFA before staff access, and audit evidence. Resend supersedes previous capability; pending invites must not count as enabled administrators or prevent lockout safeguards. Do not email passwords.

`identity.security.manage` currently participates in security-role authorization; it does **not** expose another person's MFA reset or session-revocation endpoint. Existing `identityListOwnSessions`, `identityRevokeOtherSessions` and MFA operations are self-service, not target-user tools. A real role/status change already revokes the target's sessions, but do not toggle roles/status solely to simulate a dedicated revoke operation. Another person's MFA recovery or forced sign-out requires a separate reviewed, audited operation. No such UI should be offered yet.

## Delivery boundary and evidence

Recommend T1 + T2 + T3 together before enabling Team editing. A directory can ship read-only earlier if necessary. T4 project filtering can accompany #3; #8 retains its own disclosure rules. T5 remains separate.

The proposed minimum adds four operations: staff list, capability discovery, authorization snapshot and guarded authorization replacement. These names/paths are proposals only. Keep all existing operations and strict response shapes compatible; update the authoritative contract only after implementation and verification. No frontend contract copy was modified.

Review evidence: source and OpenAPI inspection; all 13 selected identity/authorization/project/audit/contract/test files matched the accepted candidate by Git blob hash. Reviewed existing role-foundation, real PostgreSQL authorization-concurrency and session-lifecycle tests. No application tests were rerun for this documentation-only review; it is not a new passing verification gate.

An implementation must verify directory/filter/count authorization, capability expiry and discovery, security-role/last-admin/self-edit rules, real PostgreSQL stale-edit and concurrent legacy-write cases, and inherited session-race tests. Run the required pinned Docker/PostgreSQL quality gates, contract/drift checks and same-origin/dashboard regressions before declaring integration readiness. Preserve all previous performance and security evidence.

Only this response and review metadata were created. Backend code, database accounts/grants, candidate refs, authoritative OpenAPI and frontend files were not changed. B8 production performance certification remains pending the target VPS; this review does not change release classification.

Source anchors in the backend repository:

- `app/Modules/Identity/Authorization/Actions/ChangeStaffAuthorization.php`
- `app/Modules/Identity/Authorization/Http/Controllers/StaffAuthorizationController.php`
- `app/Modules/Identity/Authorization/Role.php` and `StaffAccess.php`
- `app/Modules/Identity/Actions/CurrentCapabilities.php`
- `app/Modules/Identity/Security/SessionSecurity.php`
- `app/Modules/Identity/Authorization/Actions/BootstrapSuperAdmin.php`
- `app/Modules/Projects/Actions/ProjectRead.php` and `ProjectStore.php`
- `app/Modules/Audit/Queries/InvestigateAudit.php`
- `docs/openapi.json`; role catalogue `artifacts/dashboard-gap-review/role-catalog.json`
