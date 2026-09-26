# HOLOUL B8-P3 — Admin integration contract

This candidate closes the three authorized B8-P2 API gaps: scoped customer administration reads, contextual assignee/team pickers, and current-session capabilities. Production performance certification remains pending the unavailable target VPS. No frontend, CRM editing, generic staff directory, new business domain, external AI or deployment is included.

Use [OpenAPI 3.1.1](openapi.json), candidate version `1.0.0-b8-p3`, and the [current endpoint matrix](API-ENDPOINT-MATRIX.md). They cover 164 frontend operations. B8/P1/P2 reports and the P2 matrix are historical evidence; the capability/customer/picker descriptions here supersede their missing-API statements. Existing B2–B7 journeys remain as documented, except that current-user responses now include the additive capability projection. Regenerate client types from this candidate specification.

## New endpoints

All paths below start with `/api/v1`, require an authenticated staff session with completed MFA, and return `Cache-Control: private, no-store`. Existing exact-origin, session-generation, CSRF middleware and error handling remain. These six GET operations have no request body, mutation precondition or idempotency key. Unknown fields return 422. The shared per-identity limit is 120 calls per minute.

| Frontend feature | GET endpoint | Explicit authorization |
|---|---|---|
|Customer directory|`/admin/customers`|`customers.directory.read`, plus existing intake or Project read authority and scope|
|Customer detail|`/admin/customers/{customer}`|Same; invisible customer is 404|
|Customer request summaries|`/admin/customers/{customer}/project-requests`|Directory authorization and `intake.read`; existing request scope|
|Customer Project summaries|`/admin/customers/{customer}/projects`|Directory authorization and `projects.read`; existing Project scope|
|Request assignee picker|`/admin/project-requests/{projectRequest}/eligible-assignees`|Visible non-draft request and `intake.assign`; assignable state|
|Project team picker|`/admin/projects/{project}/eligible-staff?role=contributor`|`projects.read`, `projects.team.manage`, active manager membership on this Project; nonterminal Project|

## Customer visibility and projection

The new `customers.directory.read` permission is granted to Super Admin, Project Manager, Business Analyst, Sales and Reviewer. It is not granted to Customer, Administrator or Support by default. No role receives broader intake or Project access. An existing combined role can provide its existing scope; an unauthorized staff role cannot enumerate profiles through this API.

For staff with restricted business scope, a customer appears only if at least one non-draft Project Request or Project is visible under existing policies:

- Intake: assigned to the current staff member; a staff member with `intake.assign` can also see unassigned requests; `intake.read_all` retains its existing broad scope.
- Projects: active team membership, or existing `projects.read_all` authority.
- Counts and summaries independently apply the corresponding scope. Seeing one customer's request does not expose that customer's other requests or Projects. Customer drafts remain excluded even for unrestricted staff.
- Profiles without any visible submitted work are discoverable only when the caller has the directory grant and both complete unrestricted read pairs: `intake.read` + `intake.read_all` and `projects.read` + `projects.read_all`. This is currently the seeded Super Admin scope; there is no automatic role-name bypass.

Directory/detail return: `id`, `full_name`, `email`, `email_display`, `phone_e164`, `phone_display`, `account_status` (`active`/`disabled`), `email_verified`, `customer_since`, `request_count`, `active_project_count`, `completed_project_count`, and `on_hold_project_count`.

`customer_since` is the profile creation time in UTC. Counts mean **visible records**, not customer-wide totals unless the caller has the relevant broad scope. Active Projects are planning/design/development/testing/deployment; on-hold is separate, and cancelled/completed are not active. A missing domain read permission produces zero for that domain's directory counts; its child summary endpoint remains forbidden. Current contact data does not rewrite immutable submitted contact snapshots.

Detail adds `links.project_requests` and `links.projects`, pointing to the scoped child pages. Child pages contain only `id`, `reference`, `state`, `created_at`. Follow those IDs through the existing authorized request/Project endpoints. Existing document and activity permissions are still required; these new reads do not expose content or grant document access. Directory/detail access is audited without search text or contact data in audit metadata.

No password, MFA data, sessions, recovery material, authentication version, private descriptions, proposal content, storage locator or unrelated customer content is returned. There are no staff customer create/update/delete endpoints in P3.

### Search and pagination

All new collection endpoints use `limit` (default 25, range 1–50) and exclusive UUIDv7 `after`, fixed `id` ascending order. Response metadata is `{ "next_after": null-or-UUID, "per_page": 25 }`. Follow the returned cursor with the same endpoint/context/filters until null. Access is reevaluated on each request; a previously visible row can disappear after reassignment or revocation. No total, offset, arbitrary sort or SQL/wildcard filter is provided.

Directory `q` searches name, normalized/display email and normalized/display phone. Picker `q` searches display name only. Search is trimmed, 2–100 characters, rejects control characters, and treats `%`, `_` and backslash literally. Text comparison is case-insensitive; callers should use the normalized `+` phone form when appropriate. Directory filters are only `status=active|disabled` and `email_verified=true|false` (literal query strings).

No Redis business cache or new index was added. SQL scopes and grouped counts execute as set queries; candidates are filtered by SQL permission existence, without a query per staff member. Verification compares SELECT counts at page sizes 1 and 50, with bounded total query counts. These structural checks do not replace the pending VPS performance acceptance.

## Eligible assignees and team members

Picker data contains only `{ "id": "UUID", "display_name": "…", "capability": "…" }`, with pagination metadata and `context` (`type`, resource `id`, requested team `role` or null for intake). Both pickers return the current parent `ETag`.

Request assignment eligibility requires enabled staff, `intake.read`, and at least one existing actionable permission: intake assign/review/information/discovery/reject, discovery manage, or proposal create/approve/issue. This is the command's shared rule, not an independently invented role list. The current assignee is excluded. Requests must be submitted, under_review, information_required, discovery, proposal or approved; converted/rejected/withdrawn produce 409, and hidden drafts produce 404.

Project picker requires a `role` query:

| Requested membership role | Candidate requirements | Picker capability label |
|---|---|---|
|`project_manager`|`projects.read`, `projects.transition`, `projects.team.manage`|`projects.team.project_manager`|
|`business_analyst`|`projects.read`, `projects.manage`|`projects.team.business_analyst`|
|`contributor`|`projects.read`|`projects.team.contributor`|

Candidates must be enabled staff; permissions can be satisfied across multiple current roles. Existing active members are excluded. A removed member can become eligible again. Completed/cancelled Projects reject the picker with 409; on-hold Projects retain the existing team-management behavior. Even Super Admin or a caller with `projects.read_all` must be an active manager member to manage this team's membership.

After selection, invoke the existing assignment/membership command with its normal CSRF, ETag and, for Project commands, Idempotency-Key. Never assume the picker reserves eligibility. The command independently locks/rechecks the candidate and resource. If the staff account was disabled or required permissions were revoked, the command returns 422 without assignment/membership changes. A changed parent can instead produce 412 or 409; lost caller scope can produce 403/404 or a revoked session 401. Refresh the picker and parent after these results.

No global staff enumeration endpoint was introduced. A resource UUID cannot be replaced by a client-supplied scope or a forged query context; route parent policy checks remain authoritative.

## Current-user capabilities

GET `/api/v1/identity/me` and the response to PATCH of that endpoint retain `id`, `full_name`, `email`, `email_display`, `kind`, `email_verified` and add:

```json
{
  "roles": ["project_manager"],
  "capabilities": ["admin.customers.view", "project_requests.assign", "projects.team.manage"],
  "mfa_required": true,
  "mfa_satisfied": true,
  "recent_password_confirmation": {
    "required": false,
    "expires_at": "2026-09-21T20:00:00+00:00"
  }
}
```

This is a field excerpt, not an exhaustive capability list for the example role. `kind` remains the persona (`customer`/`staff`); there is no redundant alternate persona field. `roles` contains safe stable role codes, not the permission catalog. OpenAPI enumerates every supported capability string.

The capabilities are UI hints for navigation and action composition:

| Capability family | UI use and constraints |
|---|---|
|`profile.*`, `sessions.manage`, `security.password.*`|Own profile/session/security surfaces; password change hint requires current recent confirmation. Sensitive session commands still apply their own recent-password check.|
|`customer_profile.*`, `categories.view`, `project_requests.create`|Customer persona self-service; unverified customers can still create drafts.|
|`project_requests.submit`, `project_requests.documents.upload`|Customer email verification is required.|
|`admin.customers.view`|Directory grant plus at least one business read grant; rows remain scoped.|
|`project_requests.view/assign/review/request_information`|Staff intake grants; actions still depend on assignment and request state.|
|`discovery.*`, `proposals.view/create`|Relevant intake/domain read and action grants; existing workflow rules remain.|
|`projects.view/manage/team.manage/milestones.manage/updates.publish`|Relevant Project grants; manager/member/resource-state requirements remain.|
|`proposals.accept`, `projects.completion.confirm`|Customer grants plus verified email and recent password confirmation; resource conditions still apply.|
|`categories.manage`|Taxonomy management grant.|
|`admin.dashboard.view`, `reports.view`, `audit.investigate`|Corresponding reporting/audit grant and verified email.|
|`staff.authorization.manage`|Staff management grant and recent confirmation; privilege escalation and last-admin protections remain.|
|`notifications.view/manage`|Own notification read/manage grant.|
|`ai.request`|Relevant staff/customer AI grant, verified email, and enabled AI configuration; budgets/context can still reject a run.|

Capabilities do not duplicate every domain precondition or every rare command. Existing endpoint permissions/state rules remain the final contract. In particular, possessing a string does not mean a particular resource is visible or actionable. The server accepts no capability/role update through the own-profile endpoint; client headers cannot manufacture authority.

`mfa_required` is true for staff. `mfa_satisfied` means the current session satisfies applicable MFA requirements; it is true for customers who have no mandatory staff MFA requirement. A pending staff login cannot call this authenticated endpoint and gets 401. It does not expose the presence of an MFA secret or recovery codes.

Recent-password status uses the same expiry predicate as protected commands. `required=true` means missing, future-dated or expired confirmation. `expires_at` is null for absent/invalid confirmation and may be a past instant when expired. Frontend can prompt for confirmation and reload current user; it must also handle a later 403 if time expires during an interaction.

The projection reads live authority under the same identity transaction used by protected operations. Authorized role/status changes invalidate the previous session generation, so stale browsers receive 401. Live role-permission changes are reread on the next authenticated request. Use browser-managed HttpOnly session cookies; do not persist roles/capabilities as authorization tokens. Refresh on login, tab focus, privilege changes and 401/403. Do not cache this endpoint in Next.js server/shared caches. `HOLOUL_AI_ENABLED=false` remains effective and ordinary submission remains independent of AI.

## Verification and preservation

See [B8-P3 verification](B8-P3-VERIFICATION.md) for measured results, earlier failed test assumptions and coverage limits. The contract source manifest, route inventory, deterministic assembly and actual-response validator remain enforced. The required successful-response inventory grows from 102 to 108 operations, including all six additions.

Run the independent gate against the initialized local PostgreSQL/Redis stack with `HOLOUL_CONTRACT_BATCH=p3 bash scripts/verify-contracts.sh <unique-run-name>`. This creates P3 evidence/images without replacing P2 evidence or the running P1 application stack. It never executes the blocked WSL benchmark. Application migrations must be deployed later through the existing migrator workflow; this phase verifies them only in the dedicated test database.

B8 remains an unaccepted candidate. The approved B1–B7 Git baseline, P1 failed performance results and P2 preservation bundle remain intact. Production readiness, design parity and actual Next.js screen implementation are separate from the API integration decision.
