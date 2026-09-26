# HOLOUL frontend integration package — B8-P2

This package describes approved B1–B7 plus the **uncommitted B8 candidate**. Production performance certification remains pending the unavailable target VPS. Contract verification does not change that result. See the [review decision](B8-P2-REVIEW.md), [OpenAPI 3.1 specification](openapi.json), and [complete endpoint matrix](B8-P2-ENDPOINT-MATRIX.md).

## Base path and Next.js deployment

Use relative `/api/v1` URLs on the same HTTPS public origin as Next.js. The one frontend route outside that prefix is `GET /sanctum/csrf-cookie`. Health checks, queue commands, scanner/storage access, metrics and operational configuration are not frontend contracts. GET routes also support HTTP HEAD through Laravel; it is not a separate application operation.

Serve Next.js and the API behind the same ingress, preserving the public host and HTTPS scheme with the approved trusted proxy configuration. Two localhost ports or sibling subdomains are different origins and are not supported by the current session contract. The existing backend-only local ingress does not already deploy Next.js; frontend routing/hosting is future integration work.

No Next.js environment variable is inherently required when the API base is the fixed relative path. If the frontend uses environment-driven configuration, adopt these explicit **frontend conventions**; they are not existing backend settings:

| Variable | Value / treatment |
|---|---|
| `NEXT_PUBLIC_HOLOUL_API_BASE` | `/api/v1`; optional alternative to a source constant |
| `NEXT_PUBLIC_HOLOUL_AI_ENABLED` | `false`; optional UI flag, never authorization or proof of provider availability |
| `HOLOUL_API_ORIGIN` | If Next.js performs server-side fetches, a server-only fixed allowlisted absolute HTTPS public origin; combine it with `/api/v1`. Do not derive an upstream URL from an untrusted Host header. |
| Backend `APP_URL` | Exact shared public HTTPS origin; server/deployment configuration, not a public credential |
| Backend `HOLOUL_AI_ENABLED` | `false`; keep external assistance disabled |

No database/storage/provider/session/application secrets belong in `NEXT_PUBLIC_*`. No bearer token, API key or public backend storage origin is required. Use `credentials: 'same-origin'` and `cache: 'no-store'` for private fetches; authenticated pages and server-rendered data must not enter shared caches. Server rendering may forward a user's cookies only to the trusted backend and must never serialize them into client props.

## Authentication and CSRF

The [identity guide](B8-P2-IDENTITY-INTAKE.md) gives the exact payloads, expiry and recovery rules. Browser flow:

1. GET `/sanctum/csrf-cookie` →204. The browser stores the Secure, host-only, HttpOnly `__Host-holoul_session` cookie automatically. **Never read this authentication cookie in JavaScript.**
2. Read and URL-decode the separate JavaScript-readable `XSRF-TOKEN` cookie. Send it as `X-XSRF-TOKEN` on POST/PUT/PATCH/DELETE, including login, registration and logout. Read its latest value for each mutation because authentication/session operations rotate it.
3. Mutations need a matching browser Origin or same-origin Referer. Any supplied mismatching Origin/Referer is denied; HTTPS and exact public host/port are mandatory. Do not manually set browser-forbidden Origin headers.
4. Registration returns generic202 and does not sign in. Login returns200 for an authenticated customer or202 with staff `mfa_enrollment`/`mfa_challenge`. Complete staff MFA before protected API calls. GET `/identity/me` establishes the displayed identity; it does not return roles/permissions/customer_id.
5. Read the owned customer profile with GET `/customers`. Email verification is required for submission and document mutations, although an unverified customer can save an idea draft.
6. Verification and reset links put tokens in URL fragments. Read/remove the fragment, initialize CSRF, and POST the token to the documented endpoint. Never send token fragments as query parameters or log them. Verification does not sign in; password reset revokes all sessions.
7. Before sensitive actions, POST `/auth/password/confirm`; use the operation's documented recency rule. Staff MFA completion does not replace password confirmation where it is required. Logout returns200 and a message; clear private frontend state.

Customer expiry:2hours idle /7days absolute. Staff:30minutes idle /12hours absolute. Pending staff MFA:10minutes. Server revocation/disabled identity takes effect before those limits. There is no first-party bearer-token refresh flow.

Do not persist MFA enrollment secrets, otpauth URI, recovery codes, session cookies, reset/verification tokens, private document content or AI source text in analytics, localStorage or shared caches. One-time recovery responses are shown only to the user completing that action.

## Errors, concurrency and retries

Canonical JSON error shape:

```json
{"error":{"code":"VALIDATION_FAILED","message":"The submitted fields are invalid.","fields":{"email":["This field is invalid."]}},"request_id":"019961a0-0000-7000-8000-000000000001"}
```

`error.fields` is present only for field-validation exceptions, always an object, possibly empty after unsafe field names are removed. Other422 failures may omit it. Messages are deliberately generic. Branch on `error.code`, display schema-informed field guidance, and retain `request_id` for support. The response `X-Request-ID` matches the body. All required status examples are embedded in [OpenAPI shared responses](openapi.json).

| HTTP | Stable code | Frontend handling |
|---|---|---|
|400|`MALFORMED_REQUEST`|Correct malformed JSON/request; do not automatically retry.|
|401|`UNAUTHENTICATED`|Return to login or finish the known pending MFA flow. Wrong password confirmation also uses401.|
|403|`FORBIDDEN`|Safe denial; can mean Origin/CSRF, permission, verification, MFA or recent-auth requirement. The code does not distinguish them.|
|404|`NOT_FOUND`|Missing or invisible resource, wrong owner/parent, or unavailable persona surface; do not infer existence.|
|409|`CONFLICT`|Refetch the current state and explain the operation cannot currently proceed; may also mean conflicting key reuse or stale AI source.|
|412|`STALE_VERSION`|Refetch, show changed data and obtain a new human decision before retry.|
|413|`REQUEST_TOO_LARGE`|Enforce the documented body/file limit.|
|415|`UNSUPPORTED_MEDIA_TYPE`|Send JSON or the documented binary upload content type.|
|422|`VALIDATION_FAILED`|Map safe field names; unknown input may use `input`; invalid/missing idempotency key may use `idempotency_key`.|
|428|`PRECONDITION_REQUIRED`|Read the resource/parent and supply its ETag.|
|429|`RATE_LIMITED`|Honor Retry-After if present; bounded backoff otherwise.|
|503|`SERVICE_UNAVAILABLE`|Show temporary unavailability; retry reads with backoff. For uncertain writes follow that operation's idempotency rules.|

405 `METHOD_NOT_ALLOWED` and500 `INTERNAL_ERROR` use the same envelope. The edge normalizes its own malformed-request errors to400 and upstream failures to503; application-generated errors retain their documented status. CSRF is403, not419. A403 is not proof that retrying with new CSRF will succeed. Reinitialize CSRF at most once for a known stale client session and do not blindly replay a non-idempotent mutation.

ETags are quoted strong values. Preserve the exact header, including quotes. Identity/profile writes have no If-Match requirement. Taxonomy uses its own ETag; intake/discovery/proposal/document commands generally use the request ETag; Project commands use the project ETag; AI decisions use the run ETag. **A document's version and a child milestone/proposal lock_version are not the parent's ETag.** The conversion command consumes a request ETag and returns the newly created Project ETag. OpenAPI and domain guides identify exceptions.

Idempotency keys are16–128 characters from `[A-Za-z0-9_.:-]`; a fresh UUID is a practical client choice. Reuse the same key, original payload and required original precondition only for the same uncertain logical command. Do not reuse a key for an edited request. A successful replay may return an old receipt/ETag: refetch before the next mutation. Receipt scope/retention and replay ordering differ by endpoint; there is no blanket guarantee that a retry with a fresh ETag replays successfully. Not every mutation supports an idempotency key.

## Pagination and DTO handling

Collections are bounded. Keep cursor and filter/sort values together; do not mix pagination styles:

| Collection family | Request | Continuation / bound |
|---|---|---|
|Categories/subcategories|`limit`, `cursor`|`meta.next_cursor`; default25, max100; fixed display order|
|Project request lists|`limit`, `cursor`, documented filters and `sort`|`meta.next_cursor`, `meta.per_page`; default25, max100|
|Request child lists; discovery/proposals; projects/milestones/updates/team/evidence; AI/notifications/deliveries|`limit`, `after`|`meta.next_after`; default25, max100. Revision cursors are integers; most others UUIDs.|
|Project document lists|`page`, `per_page`|Documented page metadata; default25, max100 per page|
|Audit|`limit`, opaque `cursor`, documented filters|`meta.next_cursor`; encrypted cursor bound to the exact date window/filters; default25, max100|
|Reports|`from`, `to`, allowed taxonomy filters|Date window, fixed state groups/top100 category groups; no arbitrary row dump|
|Own profile/sessions|Profile phone `search` where documented; sessions no pagination|Owner profile0–1; sessions capped100 without continuation|

Embedded lists are separately bounded (requirements100, proposal items100/deliverables50, decisions2, delivery attempts500). Do not invent `per_page`, search, sort, include or expanded objects on routes that do not declare them. Exact bounds/filter enums are in OpenAPI and the three domain guides.

Some timestamps are ISO8601; raw PostgreSQL projections use `YYYY-MM-DD HH:mm:ss[.fraction]+00`. Normalize those explicit forms in a client adapter before date parsing. Calendar deadlines remain date-only strings. Money strings must stay strings; use exact decimal/minor-unit arithmetic with USD exponent2 and LYD exponent3. Reports return minor-unit strings per currency and accepted proposal values, **not revenue**.

Command receipts are intentionally smaller than read DTOs. Refresh the relevant parent/detail/list after a successful mutation instead of treating every `data.id` as the parent ID. OpenAPI uses separate schemas for customer/staff and command/read projections.

## Customer journey

Profile → active category/subcategory → editable draft → optional document/optional AI → verified submit → request and immutable revisions → answer information request → read issued proposal → confirm password and accept/decline → read converted Project → visible milestones/updates/documents → deployment completion confirmation → notifications.

Submission needs complete current fields, If-Match and Idempotency-Key. It does not require a document or AI run. Attached documents must be finalized in `quarantined` or `available`; scanning may continue after submission, but downloading requires `available`. An information answer does not itself leave `information_required`; staff acknowledgment does. Accepting a proposal creates the approved request decision; Project conversion is a separate staff action. Customer completion confirmation records consent; the PM advances Project state.

Exact request states: `draft`, `submitted`, `under_review`, `information_required`, `discovery`, `proposal`, `approved`, `converted`, `rejected`, `withdrawn`. There is no second customer-facing request status field. Translate labels without inventing wire values.

Customer proposals expose `issued`, `accepted`, `declined`, `expired`, `superseded`, `withdrawn`, `rescinded`; staff additionally sees `draft`, `internally_approved`. Discovery's `draft`, `in_progress`, `completed` are staff-only. Projects expose `planning`, `design`, `development`, `testing`, `deployment`, `on_hold`, `completed`, `cancelled`; visible milestones expose `upcoming`, `in_progress`, `completed`, `delayed`. See [commercial/project guide](B8-P2-COMMERCIAL-PROJECTS.md) for other enums and evidence/confirmation rules.

## Upload integration

The [file guide](B8-P2-FILES-ASYNC-REPORTING.md) is authoritative for domain-specific permissions and replacement/removal.

1. Read the editable parent and retain its ETag. Accept PDF or DOCX,1byte–10MiB; calculate exact size and SHA-256.
2. POST the reservation `{filename,bytes,sha256}` with parent If-Match, CSRF and Idempotency-Key. Staff Project reservations additionally specify visibility. Retain the returned document ID and new parent ETag.
3. PUT raw bytes to the returned resource's `/content` endpoint using **exact** `Content-Type: application/octet-stream`, CSRF and parent If-Match. PUT performs finalization; there is no extra finalize endpoint or presigned upload URL.
4. Poll metadata and render `uploading`→Uploading, `quarantined`→Quarantined, `available`→Available, `rejected`→Rejected, `deleting`→Deleting, `deleted`→Removed. Polling is a client policy, not a processing SLA. Show retry only when `retryable` is true and mutation is authorized.
5. Download through the authenticated API as an attachment only when available. Storage keys and private signed storage URLs are not API fields. A temporary browser Blob URL may be used to save downloaded bytes; revoke it afterwards and do not persist it.
6. Request draft replacement creates a fresh reservation; removal detaches that draft link and preserves immutable historical references. Project DELETE only cancels pending uploads: finalized Project evidence cannot be replaced/removed by this contract. Proposal attachments select existing available historical intake files rather than raw-uploading new proposal files.

Reservation expiry is10minutes. Re-read the parent after expiry/conflict, reserve again and obtain a new human decision where needed. A failed/uncertain PUT is not permission to silently create duplicate documents. The server still checks bytes, checksum, scope, file structure, antivirus and private object version.

## Optional AI and notification polling

With `AI_ENABLED=false` in frontend configuration and backend `HOLOUL_AI_ENABLED=false`, ordinary draft/save/submission works unchanged. Do not gate Submit behind AI availability. There is no capabilities endpoint; the frontend's flag is a deployment choice, not a server authorization claim.

AI creation is POST `/ai-runs` with parent/purpose/consent, parent If-Match and Idempotency-Key. A valid request with disabled AI returns **202**, `state:unavailable`, `failure_code:provider_unavailable`. Budget/concurrency limits can likewise produce durable202 unavailable results; distinguish those from429 rate limiting and503 dependency failure. External AI remains disabled.

Run states: `pending`, `processing`, `succeeded`, `failed`, `unavailable`, `cancelled`. A successful suggestion separately has `pending`, `applied`, `dismissed`. Apply/dismiss/cancel use the run ETag and a fresh logical-command key. Apply rechecks source version/ownership/document checksum; stale suggestions normally fail409 and have no invented persisted `stale` state. Refetch and allow an explicit human decision. Render validated suggestion text as plain data; never HTML/instructions. Detailed purpose/output/error rules are in the [AI guide](B8-P2-FILES-ASYNC-REPORTING.md).

Poll `/notifications/unread-count` and paginated `/notifications` while the application is visible; pause in background and back off on429/503. A15–30second initial polling interval is a client default to validate later, not a measured performance guarantee. Notifications are a separate inbox projection; absence/delay must not override a freshly read authoritative request/project state. Mark-one uses the notification ETag; read-all uses the inbox ETag from list/unread-count; preference changes use the preference ETag. Keep these resource ETags separate even when inbox and preference headers happen to have identical text. Each write also requires its documented idempotency key. There is no WebSocket/SSE/push subscription contract. Staff delivery replay is a restricted investigation workflow, not a customer resend button.

## Admin workflow and gaps

The [complete generated matrix](B8-P2-ENDPOINT-MATRIX.md) maps every feature to method/path/persona/named permission. Permission alone never bypasses assignment, ownership, MFA, recent authentication, contributor separation or workflow state.

| Dashboard area | Implemented surface | Gap / limitation |
|---|---|---|
|Overview|GET `/admin/reports/dashboard`|Operational cohorts; no revenue or historical-state snapshot|
|Requests|GET `/admin/project-requests`; scoped detail/history/revisions/information and transition commands|Summaries omit rich contact/project fields; fetch authorized detail|
|Customers|GET `/admin/reports/customers` aggregate|No staff customer directory/detail/search/edit. `/customers` is owner-only.|
|Discovery|Nested `/admin/project-requests/{id}/discovery`|No global discovery inbox|
|Proposals|Nested `/admin/project-requests/{id}/proposals`|No global proposal inbox|
|Projects|`/admin/projects` and nested lifecycle/milestones/updates/evidence|No arbitrary search/sort; terminal records do not reopen|
|Team/assignment|Request assignments and Project team membership commands|No staff/eligible-assignee enumeration; known UUID needed|
|Categories|`/admin/categories`, nested subcategories and `/admin/subcategories/{id}`|No bulk reorder/delete/translation/search surface|
|Documents|Parent-scoped metadata/download/upload commands|No global library; finalized Project evidence immutable|
|Notifications|Own inbox; `/admin/notification-deliveries` investigation/replay|No push transport or arbitrary message composition|
|Reporting|Dashboard/requests/projects/customers|Bounded dates and explicit cohort semantics|
|Audit|GET `/admin/audit-events`|Restricted redacted investigation; no arbitrary export or mutation|

There is also no current-user capabilities/roles payload for a permission-aware admin navigation system. No approved visual dashboard artifact was present in the repository; B0 architecture and the supplied B8-P2 feature list are the available references. Visual design parity cannot be certified. These gaps are reported for scope decisions; no missing business feature was implemented in this batch.

## Verification and maintenance

Canonical reviewed schemas live in `docs/contracts/*.json`; `scripts/contracts/build.py` generates OpenAPI and the endpoint matrix. `source-manifest.json` records reviewed implementation hashes. After intentionally reviewing an implementation change, update relevant schemas/examples/tests, explicitly refresh the source manifest and regenerate. Never refresh hashes automatically in CI to silence a failure.

Run `bash scripts/verify-contracts.sh <new-run-name>` against the initialized local PostgreSQL/Redis stack. It builds the pinned PHP/Composer development and candidate runtime images, verifies Docker/FPM/nginx, runs the existing full quality suite, records real test responses, and validates schemas offline with hash-pinned tooling. It does **not** run performance acceptance, deploy a runtime, enable AI, or commit. The ordinary full `verify.sh` also invokes contract checks before its unchanged performance gate; do not use it for this P2-only phase.

Response samples come exclusively from disposable synthetic PostgreSQL tests. They can contain synthetic MFA/recovery material and are private ignored artifacts, not documentation or CI upload payloads. Share only summarized reports. Contract checks validate response bodies/statuses and declared safe success headers, strict route coverage, local references and reviewed source hashes. The verification report lists successful operation coverage and any unsampled operations explicitly; schema validation does not replace the existing authorization, concurrency or request-validation tests.

The reviewed `required-response-coverage.json` freezes a minimum of 102 operations with successful captured responses. Removing any of those samples fails verification. Four negative checker tests also ensure that extra internal fields, missing ETag, a body in 204 and an incorrect binary media type are rejected. The [verification report](B8-P2-VERIFICATION.md) distinguishes this required representative coverage from all 158 documented operations.
