# B8-P2 — Identity, Customers, Categories and Project Intake contract review

Scope: the current working tree, including the uncommitted B8 candidate. This is a frontend contract review, not production acceptance. It does not change the failed performance gate, certify the VPS, create a commit, or introduce a new business feature.

The canonical maintained fragment is `docs/contracts/identity-intake.json`: 55 distinct paths, 65 operations, 69 explicit schemas. Its one-time authoring helper is archived under ignored artifacts and is not a second maintained contract source. It covers all 26 operations in `routes/identity.php`, all 38 in `routes/intake.php`, and the root `/sanctum/csrf-cookie` framework exception. Shared error responses and header parameters are supplied by the assembled specification. The fragment uses explicit bounded object DTOs, OpenAPI 3.1 null types, exact operation permissions and public resource identifiers; authentication secrets are limited to the intended one-time MFA enrollment/recovery responses.

## Findings and corrections

| Finding | Frontend consequence | Decision |
|---|---|---|
| Invalid taxonomy `cursor` returned validation field `after` | The client could not associate the error with the parameter actually sent | Corrected only the error field key to `cursor` in `TaxonomyCatalog`; added an HTTP regression covering all four category/subcategory listing variants, malformed and overlength opaque cursors |
| `/customers` is an owner-scoped profile lookup, not an admin customer directory | A staff dashboard must not call it as a CRM list; staff receives 404 | Documented. Staff customer list/detail/search/edit is a missing API feature; not implemented in this batch |
| Staff authorization APIs require a known staff UUID; no staff enumeration or eligible-assignee lookup exists | Assignment/team pickers cannot populate themselves from the existing API | Documented as an integration gap; no staff CRUD or search added |
| `identity/me` returns only `id`, `full_name`, `email`, `email_display`, `kind`, `email_verified` | No current-user permission/role list, MFA-enabled flag, recent-password expiry, or `customer_id` is available for a capability-driven UI | Documented. Read the customer profile through `/customers`; never infer authorization from customer-supplied data. A future capability endpoint/change needs its own review |
| Request collection summaries omit project name, contact, and description | A rich request table needs detail reads to show these fields; clients must not expect a flattened CRM DTO | Preserved. Only scoped detail exposes the snapshotted revision/contact; collection DTO remains exact |
| Taxonomy pagination uses `limit`/`cursor`; intake child history uses `limit`/`after`; intake lists return `meta.per_page` | A single adapter must map input and output names correctly | Preserved existing behavior and froze it explicitly. Do not send `per_page` to these routes |
| Session listing silently caps at 100 without continuation metadata | A session-management page cannot enumerate a theoretical 101st active session | Bounded, not unbounded. Documented limitation; revoke-others affects all other sessions, not just displayed rows |
| Logout returns 200 and a message; B0's preferred generic HTTP table mentioned 204 | A client expecting an empty response could misparse logout | Existing tested behavior preserved and specified as 200; no stylistic HTTP change |
| Direct and nested customer/profile/request reads duplicate access paths | Client generators otherwise present redundant choices | Existing parent-checked aliases retained, marked `x-alias-of`. Prefer direct resource paths after obtaining an owned ID |
| Identity/profile writes do not use ETag; taxonomy and request writes do | Applying If-Match globally would imply concurrency protection which does not exist | Documented per operation. No new concurrency mechanism or weakening of existing locks |
| Generic `FORBIDDEN` covers CSRF, wrong origin, missing permission and recent-password requirements | The frontend cannot distinguish all 403 causes from the error code alone | Canonical error preserved. Confirm password before documented sensitive actions; reinitialize CSRF once where appropriate; show a safe generic denial instead of parsing text |

No unbounded frontend collection was found in this scope. A scoped search for `TODO`, `FIXME`, `HACK` and deprecation markers returned no matches in the four modules, intake application layer or their route files. No source deletion is justified by that search: absence of a marker is not proof of universal correctness, and no apparently unused code was removed.

No cross-customer data exposure was identified in these explicit response DTOs. Customer request summaries omit assigned staff; customer details include their own draft and immutable submitted contact; staff details exclude drafts; customer state history omits actor identity. Staff history includes `actor_id`, which is null for automated commercial transitions such as proposal expiry. Staff reads are permission- and assignment-scoped and audit sensitive request views. Documents are referenced only by public UUID here, never by a storage key or private URL.

## Exact same-origin Next.js authentication flow

The approved deployment is one HTTPS public origin serving the Next.js UI, `/api/v1`, and `/sanctum/csrf-cookie`. `APP_URL` defines the exact trusted origin, including any port. Merely using the same registrable domain or a separate development frontend port is insufficient under the current `ExactOrigin` middleware. A proxy must preserve the browser's intended public host and HTTPS scheme through the trusted proxy configuration. Splitting frontend/backend origins is a separate architecture/configuration change.

1. Browser requests `GET /sanctum/csrf-cookie` with same-origin credentials. It returns 204 and no JSON body. Safe GET can omit Origin/Referer; any supplied Origin or Referer must match the exact public origin. Every SPA request must be HTTPS and target that origin. `Sec-Fetch-Site: cross-site` is denied.
2. The browser stores `__Host-holoul_session`: host-only, Secure, HttpOnly, Path `/`, SameSite=Lax, no Domain attribute. Application code must never read this authentication cookie. The separate `XSRF-TOKEN` cookie is intentionally JavaScript-readable. URL-decode its value and send `X-XSRF-TOKEN` on every POST/PATCH/DELETE (also PUT on the file routes). A same-origin Fetch Metadata header does not bypass CSRF.
3. Use JSON and `Accept: application/json`. For mutations, a matching Origin **or** a same-origin Referer is required. If either is present and mismatched, the request is denied even if the other matches. A normal same-origin browser request supplies the relevant browser-controlled origin/referrer. Do not attempt to set forbidden browser headers manually.
4. `POST /auth/register` accepts `full_name`, `email`, `phone`, `password`, `password_confirmation`; all other fields are rejected. Password is 12–128 characters with letters, uppercase/lowercase and a number. Phone is international with a leading `+`, normalized to a validated E.164 number. Registration returns generic 202, including an already registered account, and **does not sign in**.
5. `POST /auth/login` accepts email/password. Email is trimmed/lowercased. A customer receives 200 `{data:{next_step:"authenticated"}}`; a staff account receives 202 with `mfa_enrollment` or `mfa_challenge`. A pending staff session has a 600-second lifetime and cannot access protected APIs yet.
6. Staff enrollment starts with `POST /auth/mfa/enrollment`, returning a one-time setup secret, `otpauth_uri`, and `expires_in:600`. Confirm a current six-digit TOTP through `/auth/mfa/enrollment/confirm`. The successful response presents ten one-use recovery codes. An enrolled staff member uses `/auth/mfa/challenge`, or `/auth/mfa/recovery` with a one-use recovery code. Do not retain MFA secrets/codes in localStorage, analytics, caches, logs, or a global application store. Enrollment confirmation/challenge/recovery establishes the full staff session.
7. Read `GET /identity/me` after authentication. Refresh the readable XSRF cookie value after login, MFA completion, password change, session revocation and logout: session IDs and CSRF tokens rotate. Never permanently cache a CSRF token captured before login.
8. Email verification links target `/verify-email#token=<64 lowercase hex characters>`. The SPA reads and removes the fragment from browser history, initializes CSRF, then posts the token to `/auth/email/verify`. No authenticated session is required. Tokens expire after 60 minutes. A previously consumed, still-valid verification token is harmlessly repeatable; expired/revoked tokens return 422 with `error.fields.token`. Resend through `/auth/email/resend` with email returns generic 202. Verification does not sign the user in. Refresh `identity/me` after success.
9. Password reset links target `/reset-password#token=...`. After CSRF initialization, POST token/password/password_confirmation to `/auth/password/reset`. Reset tokens are single-use, expire after 60 minutes and supersede earlier outstanding reset links. Success returns 200, revokes all sessions and outstanding recovery links, and requires a fresh login. The token fragment never belongs in a query string or server request target.
10. `POST /auth/password/confirm` makes recent password confirmation valid for 300 seconds. Required for revoke-others, MFA recovery-code regeneration/disable, staff authorization changes, and withdrawal in proposal/approved phases. Wrong password returns 401. Sensitive operations return generic 403 when confirmation is absent/stale.
11. `POST /auth/logout` returns 200 `{data:{message:"Signed out."}}`, invalidates the current session and rotates CSRF. Clear frontend private state and redirect. It does not revoke other sessions; `/identity/sessions/revoke-others` is the separate action.

Authenticated customer sessions expire after 2 hours idle or 7 days absolute; staff after 30 minutes idle or 12 hours absolute. The 7-day session-cookie/database storage lifetime is only an upper bound: server persona deadlines, current auth generation, enabled status and MFA checks control access. Password reset revokes all sessions; password change preserves a newly rotated current session while revoking others; a real staff role/enabled change revokes all subject sessions. There is no first-party bearer-token or remember-me flow.

Browser fetches should use relative paths and `credentials:"same-origin"` (or an explicitly equivalent same-origin credentials policy). Authenticated Next.js rendering/fetching must remain private and uncached; server rendering must forward a user's cookies only to the trusted same-origin backend and must not put them in client props. No browser-facing environment variable contains an application key, session secret, database credential or external provider key. This scope needs no separate cross-origin API hostname.

### Authentication errors and throttling

- 401 `UNAUTHENTICATED`: missing/expired/revoked session, staff not yet MFA-complete, wrong first-factor/password confirmation. Prompt login without treating a 202 pending-MFA response as failure.
- 403 `FORBIDDEN`: prohibited origin/CSRF or visible forbidden action, wrong persona on intake/admin operations, unverified submission, missing recent password, or insufficient permission. Never parse the message to infer a permission.
- 404 `NOT_FOUND`: absent/malformed or foreign resources, wrong parent-child relationship, invisible staff-assigned request, and the customer-profile surface called by staff. Do not distinguish existence from lack of visibility.
- 422 `VALIDATION_FAILED`: `error.fields` contains safe field names and generic bounded messages, including `input` for unsupported fields and `token` for expired recovery links. Field text is not the original validator explanation. Use the documented schema to explain expected format.
- 429 `RATE_LIMITED`: honor `Retry-After`, using the canonical safe envelope. Login permits 5/account/minute and 30/IP/minute; registration 10/IP/hour; resend/forgot 3/account/hour and 30/IP/hour; verify/reset 5/token/minute and 30/IP/minute; other protected security actions 5/identity/minute and 30/IP/minute. These are shared purpose buckets, not isolated counters per browser tab.
- 503 `SERVICE_UNAVAILABLE`: authentication throttling fails closed when required Redis rate limiting is unavailable. This does not make Redis the authority for sessions or business commits.

## Customer intake journey and exact statuses

Discover the customer profile through `GET /customers`. Read active `/categories` and `/categories/{category}/subcategories`. Create a draft with `POST /project-requests`; it can be incomplete and the email can still be unverified. Save partial changes through `PATCH /project-requests/{id}/draft` with the latest ETag. The mutable draft and immutable submitted revisions are separate records.

For the effective merged draft, category and subcategory must be selected together; the subcategory must belong to an active selected category. Money is a decimal **string**: `USD` supports at most two fractional digits and `LYD` three, backed by signed BIGINT minor units. If `budget_unknown:true`, amount and currency must be null; if false, amount/currency must be supplied together, and both are required for submission. Text and money input are normalized; a client cannot supply identity snapshots, owner, status, reference, assignment, minor units, or lock version.

After email verification and completion of required fields, submit with `POST /project-requests/{id}/submissions`, empty body, the original current `If-Match` and a unique `Idempotency-Key`. Documents and AI are optional for a text submission. An attached document may be finalized `quarantined` **or** `available` when the immutable submission reference is created; `uploading`, `rejected`, `deleting`, and `deleted` attachments conflict. Quarantine does not authorize download: only `available` content can be downloaded. The response is 201 with request/revision IDs, allocated `REQ-YYYY-...` reference, revision number, resulting version/state, ETag and revision Location.

The key accepts 16–128 characters from `A–Z a–z 0–9 _ . : -`, is scoped to actor and `intake.submit`, and retains replay records for 72 hours. Exact replay requires **the same original If-Match**, same request and empty body; it returns the same 201 receipt even if the resource has advanced. Changing the request or precondition while reusing that key returns 409. An absent/invalid key returns 422 on `idempotency_key`, not 428. A fresh request with a missing If-Match returns 428; stale/malformed If-Match returns 412. Read a fresh resource after successful replay before the next mutation because its replayed ETag is historical.

The intake API exposes the same `state` enum to customer and staff; it has **no separate customer-friendly status field**:

| Wire state | Customer meaning | Notes |
|---|---|---|
| `draft` | Unsubmitted idea | Staff intake views never expose it |
| `submitted` | Submitted for review | Reference and first immutable revision now exist |
| `under_review` | Under review | Staff review action enters this state |
| `information_required` | More information requested | Customer response increments the version but does not clear the state |
| `discovery` | Discovery | Reached through staff handoff; not itself a Project |
| `proposal` | Issued proposal awaits a decision | Internal proposal drafting/approval is a separate commercial lifecycle |
| `approved` | Customer accepted the proposal | Does not mean merely internal staff approval |
| `converted` | Converted to a separate Project | Read Projects APIs for delivery lifecycle |
| `rejected` | Request rejected | Reason appears in customer-visible state history |
| `withdrawn` | Customer withdrew the request | Terminal intake outcome |

The frontend may translate labels, but must branch on these exact values. Proposal and Project enums are separate contracts. When an information request is active, read `/information-requests` or `information_request_id` from request detail. Information rows use `response`, `responded_at`, `resolution`, `resolved_at`; they have no `status` field. Submit one response to the current question. Only staff acknowledgment returns the parent to its persisted `under_review` or `discovery` origin. A question closed by another terminal workflow has resolution `closed`; a handled response has `acknowledged`.

Amendments can be opened only during `submitted`, `under_review`, or `information_required`, and only if no draft is already open. Saving/submitting an amendment never overwrites the original revision. The request remains in its current phase. Ordinary withdrawal is supported from `submitted`, `under_review`, `information_required`, `discovery`; proposal/approved withdrawal uses the commercial workflow and recent password. Draft, converted and terminal states cannot be withdrawn through this action.

## Admin intake and taxonomy integration

Staff session requires MFA. Reading intake requires `intake.read`. Without `intake.read_all`, staff can see assigned requests; `intake.assign` also permits visibility of unassigned requests. This is a scope, so a hidden request returns 404, not a visible read followed by client filtering. Customer routes and staff routes are distinct surfaces.

| Action | Route suffix on `/admin/project-requests/{id}` | Additional permission | Required state / behavior |
|---|---|---|---|
| Assign/reassign | `POST /assignments` | `intake.assign` | Enabled staff with `intake.read` and at least one actionable intake/discovery/proposal permission; submitted/under_review/information_required/discovery/proposal/approved; assigning same person conflicts |
| Begin review | `POST /reviews` | `intake.review` | submitted → under_review |
| Ask information | `POST /information-requests` | `intake.information` | under_review/discovery → information_required |
| Acknowledge answer | `POST /information-requests/{information}/acknowledgements` | `intake.information` | Current answered question → persisted origin phase |
| Handoff | `POST /discovery-handoffs` | `intake.discovery` | under_review → discovery |
| Reject | `POST /rejections` | `intake.reject` | under_review/information_required/discovery → rejected; message required |

Every action above needs the parent's current If-Match, and action permission does not replace read visibility. Except assignment, the actor must be current assignee or have `intake.read_all`. State/assignment changes, submission and sensitive views retain existing database locks, audited writes, durable intent and current identity authorization; this review made no security shortcut.

Taxonomy administration requires `taxonomy.manage`, returns active and inactive categories, and uses mandatory ETag/If-Match on PATCH. A category/subcategory record exposes `lock_version`, whereas request summaries expose `version` plus `etag`; the frontend should copy the ETag header rather than derive versions from mismatched property names. Immutable slugs/parent relationships cannot be edited; deactivate entries through `active:false` instead of deleting them. Public active taxonomy listing still requires an authenticated session. There is no taxonomy search, custom sort, explicit active filter, delete, translation table, or bulk reorder API.

Staff authorization read requires `identity.staff.read`; update requires both it and `identity.staff.manage`, completed MFA and recent password. Security-role changes additionally require the super_admin role and `identity.security.manage`; a manager cannot grant a permission they do not possess. Removing the last enabled super_admin returns 409. The allowed staff roles are `super_admin`, `administrator`, `project_manager`, `business_analyst`, `sales`, `reviewer`, `support`; customer persona cannot be assigned to staff. Role membership is not a substitute for the action-specific permission catalog.

## Collection audit and aliases

| Collection | Input | Output metadata | Ordering / bound |
|---|---|---|---|
| `/customers` | optional `search` (literal phone substring, max64) | none | unique owner profile; 0–1 records, additional query cap25 |
| `/identity/sessions` | none implemented | none | active sessions by last activity descending; cap100 |
| active/admin categories and subcategories | `limit`1–100 default25; opaque `cursor` | `meta.next_cursor` | display_order, id ascending; cap100 |
| customer/admin request list | `limit`, `cursor`, `sort`, `state`, `reference`, `category_id`, `assigned_staff_id`, `q` | `meta.next_cursor`, `meta.per_page` | created_at/id, default newest first; cap100 |
| request revisions | `limit`, integer `after` revision number | `meta.next_after` integer/null | revision_number ascending; cap100 |
| information requests, state history, staff assignments | `limit`, UUIDv7 `after` | `meta.next_after` UUID/null | id ascending; cap100 |
| staff roles in one record | no independent collection API | none | at most7 staff roles |
| MFA recovery-code response | one-time action | none | exactly10 codes |

Only request lists support `q` (2–200 characters) full-text search, with PostgreSQL `simple` tokenization of the latest submitted project name/description, or of an unsubmitted draft. Only request lists support sort (`created_at`, `-created_at`). Reference filters are exact; supplied IDs/filters never expand ownership scope. Preserve current filters and sort when replaying any opaque cursor. No collection accepts unrestricted `include`, arbitrary fields, arbitrary sort columns, or unbounded page size.

`GET/PATCH /identities/{identity}/customers/{customer}` are parent-checked aliases for the direct customer profile routes. `GET /customers/{customer}/project-requests/{projectRequest}` is the parent-checked customer request alias. Reference lookup is an intentional alternative lookup key, not a second write API. Do not remove these routes merely to simplify generated clients.

## Evidence and verification responsibility

Review sources: route files; HTTP request validators/controllers; `IntakeApi`; explicit customer/taxonomy/revision DTOs; identity session/origin/CSRF/MFA/recovery and authorization actions; customer ownership/policy; intake policy/store/draft/submission/information/assignment/transition actions; bounded read queries; money/taxonomy value objects; and existing Identity/Customers/Authorization/Categories/ProjectIntake HTTP, workflow, isolation and concurrency tests.

The narrow new regression is `Tests\Feature\Categories\TaxonomyHttpValidationTest::test_invalid_cursor_errors_identify_the_public_query_parameter`. Its expected failure before the correction is the wrong `error.fields.after` key; it asserts canonical 422/VALIDATION_FAILED plus `error.fields.cursor` and absence of `after` for all four listing surfaces. Full PHPUnit, architecture, Pint, PHPStan, Composer and assembled OpenAPI/actual-response contract checks are run centrally by the coordinating review; this sub-review does not claim those checks passed before their captured results exist.

The generated fragment parses as JSON and accounts for the complete scoped route inventory. Central contract verification remains the release gate for the assembled artifact. These findings do not certify performance or production readiness; target-VPS acceptance and all existing B8 operational blockers remain separate.
