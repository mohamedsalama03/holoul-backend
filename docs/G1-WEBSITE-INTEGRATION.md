# G1 — Public Website integration handoff

**BACKEND FEATURE READY FOR WEBSITE INTEGRATION.** Acceptance evidence: [G1-FINAL-ACCEPTANCE.md](G1-FINAL-ACCEPTANCE.md). This authorizes integration against the accepted backend contract; no website or Admin Dashboard implementation is included, and this is not Production Ready.

Authoritative contract: [openapi.json](openapi.json), OpenAPI **3.1.1**, API version **1.1.0-g1**, **172 operations**, SHA-256 `ae8d0a904a0042b80f9adc1224dedf8677794e2d49ca5cdf41daeb5278780be8`. The frontend contract copy has not been changed. The original 164 operation objects and authenticated request bodies remain unchanged. Five GuestIntake schemas are additive. The shared `IntakeRevision` now also represents guest history: `submitted_by` may be null and `provenance` may be `guest_submission`; existing customer-authored values remain unchanged.

## Choose the correct experience

**Guest:** Contact Information → Guest Draft → Optional Document → Final Submission → Reference → Secure account/sign-in/claim continuation.

**Authenticated customer:** Authenticated Profile → existing authenticated draft/intake → optional document → submission → own request. **Do not ask for Name, Email or Phone again in Submit Your Idea.** They come from the server-side profile, and the server derives ownership. Do not send forged contact, customer or user ownership fields. An incomplete profile returns 422 with `error.fields.profile_complete_required`; offer profile completion. An unverified customer must complete existing email verification. Staff and incomplete MFA identities are not guests.

Resolve identity through the existing authentication state. Do not interpret a transient failed identity request as permission to use guest endpoints. Bootstrap CSRF explicitly with `identityInitializeCsrf` (`GET /sanctum/csrf-cookie`, 204); after completed authentication, read `identityGetCurrentUser` (`GET /api/v1/identity/me`, `IdentityCurrentUserResponse`) and `customerListOwnProfiles` (`GET /api/v1/customers`, `CustomerProfilesResponse`, own 0–1 profiles). These supply name/email and phone for display; authenticated submission independently reads the current profile. Do not call authenticated `/identity/me` while MFA is pending. Refresh CSRF after login/MFA/session rotation.

Guests must provide **Full Name, Email and an international phone with leading `+` and country calling code**. These contacts remain part of the immutable submitted revision. The server validates and normalizes the phone to E.164 and trims/lowercases email without provider-specific rewriting. It does not guess a phone region or associate an account from an email match.

Both paths use the same active category/subcategory taxonomy, project information, exact money parser, canonical `ProjectRequest`, immutable revisions and private document security. USD permits up to **2** fractional digits and LYD up to **3**; send decimal **strings**, never JSON floating-point numbers. Zero is valid. Known budget requires `budget_unknown:false`, `estimated_budget` and `currency` (`USD`/`LYD`). Unknown budget uses `budget_unknown:true` and omits or nulls amount/currency. Minor units must fit PostgreSQL signed BIGINT.

## Eight G1 operations

All paths in this table are relative to `/api/v1`. Responses use `{ "data": ... }`; taxonomy also returns `meta.next_cursor`. The named schemas are under `components.schemas` in the accepted contract. Public taxonomy accepts `limit` (1–100) and `cursor`; other path identifiers use UUIDv7.

| Method and path | operationId | Request → success schema/status | Required operation headers |
|---|---|---|---|
| GET `/intake/categories` | `guestIntakeCategories` | No body → `TaxonomyPageResponse` / 200 | No capability or CSRF header |
| GET `/intake/categories/{category}/subcategories` | `guestIntakeSubcategories` | No body → `TaxonomyPageResponse` / 200 | No capability or CSRF header |
| POST `/guest/project-requests` | `guestIntakeCreate` | Empty object `{}` → `GuestIntakeDraftResponse` / 201 | CSRF |
| POST `/guest/project-requests/{projectRequest}/documents` | `guestIntakeReserveDocument` | `DocumentReserveInput` → `DocumentResponse` / 201 | CSRF, capability, If-Match, Idempotency-Key |
| PUT `/guest/project-requests/{projectRequest}/documents/{document}/content` | `guestIntakeUploadDocument` | Raw binary → `DocumentResponse` / 200 | CSRF, capability, If-Match |
| GET `/guest/project-requests/{projectRequest}/documents/{document}` | `guestIntakeDocumentStatus` | No body → `DocumentResponse` / 200 | Capability and original session |
| POST `/guest/project-requests/{projectRequest}/submissions` | `guestIntakeSubmit` | `GuestIntakeSubmissionInput` → `GuestIntakeConfirmationResponse` / 201 | CSRF, capability, If-Match, Idempotency-Key |
| POST `/project-request-claims` | `guestIntakeClaim` | `GuestIntakeClaimInput` → `GuestIntakeClaimResponse` / 200 | CSRF, Idempotency-Key, verified matching customer session |

“CSRF” means the decoded `XSRF-TOKEN` cookie sent as `X-XSRF-TOKEN`. “Capability” means `X-Intake-Capability`. Send browser cookies using same-origin credentials. Exact allowed origin/host rules remain mandatory; do not add cross-origin access or rewrite Origin. Public taxonomy does not load/save session state or emit session cookies. Guest mutations explicitly require the CSRF bootstrap session but never save or replace authenticated session state. Document status is a read, so no CSRF header is required, but capability/session/origin authorization still applies.

Use JSON `Content-Type: application/json` except the binary upload. Preserve the **quoted parent request ETag** in `If-Match`, not a document ID/version. Create returns ETag both in the header and `data.etag`; reservation, upload and metadata return parent ETag headers. Claim returns the resulting request ETag. Final submission returns its confirmation receipt without a new ETag.

## Guest sequence and expiry

1. Collect the form in page state. Create the empty draft when an upload identity or final submission is needed. `GuestIntakeDraftResponse.data` has `draft_id`, `capability`, `expires_at`, `etag`. The 256-bit capability is returned once, stored hashed server-side, and lasts **30 minutes**, bound to the original browser session **and its CSRF generation**. It is not a persistent public draft-reader token. Draft creation has no idempotency key; do not automatically create another draft after an ambiguous response.
2. Use the exact optional upload sequence below, retaining its latest parent ETag.
3. Submit `GuestIntakeSubmissionInput`: required `full_name`, `email`, `phone`, `category_id`, `subcategory_id`, `project_name`, `project_description`, `budget_unknown`; optional exact `estimated_budget` and `currency` under the money rules. No contact/ownership overrides or unsupported fields are allowed. Final submission records the complete contact/project snapshot atomically; it does not persist partial form fields.
4. Display `GuestIntakeConfirmationResponse.data.reference` and `confirmation: submitted`. Every email gets the same `next_step: sign_in_verify_email_and_claim`, plus `claim_token` and `claim_expires_at`. **A reference is not an authentication credential. Email equality alone never establishes ownership.** No guest private request list/detail/download or account-existence lookup is available.
5. Retain the independent claim token securely for account creation/sign-in and email verification. Never put it or the draft capability in URLs, localStorage, logs, analytics, error reports or screenshots. Prefer memory during an in-page flow; a full navigation needs protected server-session continuity. Loss does not authorize an email/reference-based recovery shortcut.
6. Complete existing authentication and verify the submitted email. Send `GuestIntakeClaimInput` (`{ "token": "<claim_token>" }`) to the claim operation with fresh CSRF and a retained distinct Idempotency-Key. Both the token and the currently enabled, verified matching **customer** are required. An unconsumed claim expires after **72 hours**, and initial claim is allowed while Submitted or Under Review. Staff MFA grants no customer claim permission.
7. Claim atomically associates the canonical request, mutable draft and document authorization; it does not rewrite original contact/author history or copy private bytes. `GuestIntakeClaimResponse.data` returns `request_id`, `reference`, `version`; use authenticated detail for “View My Request”.

Login/MFA/logout rotation invalidates the old draft binding, including attempts to bootstrap a retired cookie. Keep unsent fields in page state, resolve the completed identity, and continue through authenticated intake after login. Do not silently transfer anonymous attachments. An already-authorized in-flight guest submission may finish while login completes; its receipt remains a guest receipt requiring explicit claim. Do not create a duplicate authenticated submission. A completed guest submission's independent claim token survives session rotation under the claim rules.

## Exact private upload sequence

**PDF or DOCX only; one optional file, maximum 10 MiB (10,485,760 bytes).** No multipart upload, presigned/public storage URL or guest download is exposed.

1. Compute the actual file length and lowercase SHA-256. Reserve with `DocumentReserveInput`: `filename`, integer `bytes`, `sha256`, capability, CSRF, current parent If-Match and a unique retained Idempotency-Key. Save the document ID and returned parent ETag. Reservation expires after **10 minutes**, while the parent capability must also remain valid.
2. PUT the exact raw bytes to the guest content endpoint using `Content-Type: application/octet-stream`, capability, CSRF and the returned parent If-Match. No separate Idempotency-Key is required for this PUT. Authorization/expiry/attachment association and ETag are checked before storage I/O and again before finalization. The object uses a server-generated private quarantine key; size/checksum/content rules remain B4's rules.
3. Read bounded processing metadata with the status operation, original session and capability while the draft remains editable. Metadata carries the parent ETag. Quarantine, bounded inspection and malware scanning run through the existing durable workers. Submission requires completed upload with the file Quarantined or Available; pending/incomplete upload cannot be attached to the final revision. Rejected content cannot be submitted as acceptable content.
4. Submit with the latest parent ETag. There is **no guest download**, even for an Available file. After claim, normal authenticated document authorization applies and download remains blocked until Available. Scanning is not bypassed by claim. Original attachment history stays immutable.

Expired unsubmitted draft attachments are retired after 24 hours through the existing deletion pipeline; submitted immutable history is retained. Anonymous reservations share bounded capacity (500 MiB, 100 active files, 50 pending uploads). Claim must fit the customer's existing 1 GiB/1000-document quota; quota failure rolls back the entire association.

## Authenticated intake operations

All use an authenticated customer session and the server-derived profile/owner. Reads require normal identity authorization; mutations also require CSRF. Path prefix `/api/v1`:

| operationId | Method/path | Request → response | Extra requirements |
|---|---|---|---|
| `intakeCustomerCreate` | POST `/project-requests` | `IntakeDraftInput` → `IntakeCustomerSummaryResponse` / 201 | No contact/owner fields; receive parent ETag |
| `intakeCustomerUpdate` | PATCH `/project-requests/{projectRequest}/draft` | `IntakeDraftInput` → `IntakeCustomerSummaryResponse` / 200 | Current If-Match |
| `documentReserve` | POST `/project-requests/{projectRequest}/documents` | `DocumentReserveInput` → `DocumentResponse` / 201 | If-Match and Idempotency-Key |
| `documentUpload` | PUT `/project-requests/{projectRequest}/documents/{document}/content` | Raw octet-stream → `DocumentResponse` / 200 | If-Match |
| `intakeCustomerSubmit` | POST `/project-requests/{projectRequest}/submissions` | `IdentityEmptyInput` (`{}`) → `IntakeSubmissionReceiptResponse` / 201 | If-Match and Idempotency-Key |
| `intakeCustomerDetail` | GET `/project-requests/{projectRequest}` | No body → `IntakeCustomerDetailResponse` / 200 | Own request; returns ETag |
| `intakeCustomerList` | GET `/project-requests` | No body → `IntakeCustomerPageResponse` / 200 | Own requests only |

Use the contract for optional draft fields and cursor filters. Complete project/taxonomy/budget fields before submitting. Both paths snapshot contact/project information into the same immutable revision model.

## Idempotency and safe errors

Use distinct Idempotency-Key values for reservation, final submission and claim; retain each key and its original input/precondition until the result is known. Keys must contain 16–128 characters from `A–Z`, `a–z`, `0–9`, `_`, `.`, `:`, `-`; a generated UUID is suitable. Do not silently retry non-idempotent draft creation.

Guest final submission retries require the same normalized body, original quoted If-Match and original key; its receipt can replay within capability expiry. Changed content/key conflicts; the same final key cannot be reused on another draft in the same browser. Independent intentional ideas are not deduplicated by email. A lost/expired capability requires an explicitly resolved new workflow, not an automatic duplicate after an uncertain successful response.

An exact claim retry by the same current verified owner with the same token/key replays its immutable receipt, including after claim expiry, without a second association. Other identities, wrong/expired unconsumed tokens, different keys, or ineligible state fail generically. Read current detail for a fresh ETag before a later mutation. No public token reissue or historical contact rewrite exists.

Failures use `ErrorEnvelope`: `error.code`, fixed safe `error.message`, optional `error.fields`, and `request_id` matching `X-Request-ID`. Display product wording based on safe codes/fields; retain request IDs for support without recording payloads or secrets.

| HTTP / error.code | Integration behavior |
|---|---|
| 400 `MALFORMED_REQUEST` / 405 `METHOD_NOT_ALLOWED` | Correct request shape/method |
| 401 `UNAUTHENTICATED` | Resolve authentication; do not assume guest mode |
| 403 `FORBIDDEN` | CSRF/origin/persona/permission restriction; never bypass via another flow |
| 404 `NOT_FOUND` | Generic inaccessible/invalid/expired capability or claim; reveal no account existence |
| 409 `CONFLICT` | Conflicting key/body/state or incomplete upload; resolve state before proceeding |
| 412 `STALE_VERSION` / 428 `PRECONDITION_REQUIRED` | Supply quoted parent ETag; preserve the original for an exact retry |
| 413 `REQUEST_TOO_LARGE` / 415 `UNSUPPORTED_MEDIA_TYPE` | Respect file/body/format limits |
| 422 `VALIDATION_FAILED` | Map safe fields to form errors, including profile completion |
| 429 `RATE_LIMITED` | Respect Retry-After when present; may include quota rejection |
| 500 `INTERNAL_ERROR` / 503 `SERVICE_UNAVAILABLE` | Keep operation identity and request ID; resolve ambiguous completion before retry |

Guest JSON is capped at 128 KiB. Public taxonomy is limited to 60/IP/minute; guest creation 5/IP/session/hour plus 100/hour globally, submission 15/IP/session/hour, claim 10/IP/session/minute and other guest operations 60/IP/session/minute. No limiter bypass or anonymous account discovery is permitted.

## AI and deployment boundary

**G1 works with AI disabled.** No anonymous AI endpoint is added. Integrate AI assistance as a separate website phase **after the core Dual Intake flow works**; that phase is outside this acceptance.

The accepted additive migration extends 29 migrations to 30 without changing prior migration files. Once guest history exists, downgrade refuses to destroy it; use a reviewed forward migration or restore the previously verified database/application pair. Local acceptance does not authorize a production deployment. B8 target-VPS performance certification remains pending; failed performance, identity remediation and F1-E1/F1-E2 evidence are preserved.