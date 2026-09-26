# G1 website integration contract

Scope: backend contract only. Website implementation has not started. Use `docs/openapi.json` (OpenAPI 3.1.1, version `1.1.0-g1`) for exact schemas. See `G1-VERIFICATION.md` for the verified candidate and deployment boundary.

## Select the experience from the existing session

Initialize the existing first-party CSRF cookie and resolve the existing identity state. A signed-in customer uses the existing authenticated intake endpoints and profile name/email/phone. Do not ask for those contacts again or send replacement contact/customer IDs. A staff identity or incomplete MFA session must finish its own authentication flow; it is not a guest. An anonymous session uses the new guest flow. Do not infer identity from an entered email.

After authentication is complete, `GET /api/v1/identity/me` provides `full_name`, `email`, `kind`, verification and session capabilities. `GET /api/v1/customers` returns the current customer's own profile (0–1 rows), including `phone_e164` and `phone_display`; use these reads for the “Submitting as” presentation only. Final authenticated submission independently reads the current profile again. Refresh CSRF after login/MFA/session rotation. Do not probe `/identity/me` while MFA is pending, since its authenticated guard invalidates an incomplete session; that guard behavior remains part of the inherited security contract.

An incomplete authenticated contact profile returns HTTP 422 with `error.fields.profile_complete_required`. Display a profile-completion action; the generic field message is deliberately not product copy. An unverified identity must complete the existing email-verification flow before submitting. There is no guest fallback for an authenticated identity.

Both experiences use: active category → active subcategory → project name → description → optional document → budget → review → submit. USD accepts at most 2 decimal places; LYD accepts at most 3. Send decimal **strings**, including zero. Unknown budget uses `budget_unknown: true` with amount/currency omitted or null. Known budget uses `budget_unknown: false`, `estimated_budget` and `currency` together.

## New operations

| Method | `/api/v1` path | Purpose |
|---|---|---|
| GET | `/intake/categories` | Active shared categories; cursor pagination |
| GET | `/intake/categories/{category}/subcategories` | Active children of an active parent |
| POST | `/guest/project-requests` | Empty canonical draft; returns capability, expiry and ETag |
| POST | `/guest/project-requests/{draft}/documents` | Reserve one private PDF/DOCX |
| PUT | `/guest/project-requests/{draft}/documents/{document}/content` | Stream raw file bytes into quarantine |
| GET | `/guest/project-requests/{draft}/documents/{document}` | Bounded processing metadata while draft is editable |
| POST | `/guest/project-requests/{draft}/submissions` | Commit validated contacts/project and immutable revision |
| POST | `/project-request-claims` | Authenticated, verified customer's explicit one-time association |

No guest request-detail, list, download, AI or account-existence endpoint is provided. A request reference or UUID never authorizes access.

Public taxonomy GETs are stateless: they retain exact-origin and IP rate controls but do not load, save or emit session cookies. Guest mutations require an explicit `/sanctum/csrf-cookie` bootstrap first; they read its CSRF/session binding but never save or replace authentication state.

Login/MFA/logout rotations invalidate the original draft binding. Keep unsent form fields in page state and switch to authenticated intake after login; do not silently transfer anonymous attachments or fall back to guest. A submitted guest request uses its independent claim token, which remains usable after login/logout under the claim rules. Customer login can claim; staff MFA does not grant customer ownership.

A guest request already authorized and in flight may finish anonymously while login completes. Its response must never replace the current login. Treat any resulting submission receipt as a guest receipt requiring explicit claim; do not silently attach it or automatically create a second authenticated submission. A rotated binding rejects subsequent use of the old draft capability, including bootstrap with a retired cookie.

## Guest sequence

1. Keep form fields in the current page's state. Send `{}` to create the draft when the workflow needs its upload identity or is ready to submit. The response contains `draft_id`, `capability`, `expires_at`, `etag`; the response also carries ETag. The capability lasts 30 minutes and is bound to the current browser session and its CSRF generation. There is no persistent public draft reader.
2. Collect `full_name`, `email` and `phone`. The phone must include `+` and the selected country calling code; the backend normalizes it to E.164. It does not guess a country. Emails are trimmed/lowercased without provider-specific rewriting. These are submitted contacts, not proof of an account.
3. For a document, send `filename`, integer `bytes` and lowercase SHA-256 to the reservation endpoint. Include `X-Intake-Capability`, a fresh `Idempotency-Key`, and the current quoted `If-Match`. Preserve the returned parent ETag. Upload with `Content-Type: application/octet-stream`, the capability and that ETag; do not use multipart. Upload authorization expires 10 minutes after reservation and is checked again after storage I/O.
4. Submit the complete contact/project payload below with the capability, current parent `If-Match` and a distinct final-submission `Idempotency-Key`. At most one PDF/DOCX of 10 MiB can be attached. It must have finished upload and be Quarantined or Available. Download remains blocked until Available and authorized account/staff access.
5. On success, display the safe `reference` and confirmation. The identical continuation for every email is `sign_in_verify_email_and_claim`. Securely retain `claim_token` only for the current continuation. Never put capabilities or claims in URLs, logs, analytics, error reports or localStorage. In-memory state can retain the token across an in-page authentication flow; if a full navigation is necessary, a protected server session must carry it. Losing it does not authorize an email/reference recovery shortcut.
6. Create/sign into an account through existing identity APIs and verify the same email used for submission. POST `{ "token": "<claim_token>" }` to `/project-request-claims` with CSRF and a fresh retained `Idempotency-Key`. The claim is eligible for 72 hours while Submitted or Under Review. The independent token and the authenticated verified matching identity are both required. Success returns `request_id`, `reference`, `version` and the resulting ETag. Read the request through the existing authenticated endpoint and offer “View My Request”.

Example final body (category values must be actual active UUIDv7 identifiers):

```json
{
  "full_name": "Guest Person",
  "email": "person@example.test",
  "phone": "+218912345678",
  "category_id": "019961a0-0000-7000-8000-000000000001",
  "subcategory_id": "019961a0-0000-7000-8000-000000000002",
  "project_name": "Learning portal",
  "project_description": "A bilingual learning portal for our team.",
  "budget_unknown": false,
  "estimated_budget": "1234.567",
  "currency": "LYD"
}
```

## Retries and safe errors

All mutations retain existing exact HTTPS origin, session cookie and CSRF requirements. Send cookies from the same origin and the decoded XSRF cookie value in `X-XSRF-TOKEN`. Do not cache private responses. Preserve `X-Request-ID` for support without recording request bodies or secrets.

Final submission returns a confirmation receipt without a new resource ETag. Final submission retries must preserve the same normalized body, Idempotency-Key and **original** If-Match. The exact receipt replays within the capability lifetime; changed data/key conflicts. Reusing that final key on another draft in the same browser also conflicts. Separate intentional ideas are not deduplicated by email/content. A lost/expired capability requires a new workflow; do not silently submit again after an uncertain successful response.

An exact claim retry by the same verified current owner and key replays its immutable receipt, including after claim expiry; it performs no second association. Fetch the request for a current ETag before a later mutation. Wrong identity/token, expired unconsumed claim, another key, consumed claim or terminal request gets a generic 404. There is no public token reissue. A typo in the submitted email cannot be corrected by rewriting the historic revision.

| Status | Website behavior |
|---|---|
| 401 | Resolve authentication; never assume a transient authentication failure means guest mode |
| 403 | Origin/CSRF/persona/permission restriction; do not switch endpoints to bypass it |
| 404 | Generic inaccessible/invalid/expired capability or claim; do not reveal account existence |
| 409 | Conflicting key/content/state, incomplete upload, or work that requires claim first |
| 412 / 428 | Stale/missing quoted parent ETag; preserve original precondition for exact retries |
| 413 / 415 | Oversized body/file or unsupported format/content type |
| 422 | Field validation; map safe field names to product copy |
| 429 | Respect Retry-After; may represent abuse limits or customer document quota |
| 503 | Dependency unavailable; retain the same operation key and retry only after backoff |

Limits: guest JSON 128 KiB; creation 5/IP/session/hour and 100/hour globally; submission 15/IP/session/hour; claims 10/IP/session/minute; remaining guest operations 60/IP/session/minute. Anonymous document reservations additionally share 500 MiB / 100 active files / 50 pending uploads. Claimed files must fit the existing customer's 1 GiB / 1000-file quota. Quota failure rolls back the whole claim. File scanning/deletion use existing durable workers. Expired unsubmitted attachments are retired after 24 hours; submitted immutable history is preserved.

## Existing consumers and operations

The original 164 operation paths and authenticated request bodies remain. Eight operations are added. `IntakeRevision.submitted_by` can now be null for the original guest revision and `provenance` adds `guest_submission`; customer-authored revisions retain their prior values. Render the new provenance safely in any future staff integration. Guest historic revision author/contact do not change after claim. Current root/draft/document ownership changes once, without re-uploading or copying private bytes.

Authorized staff retain existing scope/assignment rules and can inspect, review and reject unclaimed submissions. Discovery, information requests, proposals, project conversion and existing private AI sources require ownership first. No guest email notification is sent and no identity-specific B7 event is fabricated before claim; durable canonical intake submission intent and audit remain. No external paid AI provider is enabled.

Once any guest request exists, the migration refuses downgrade; take the existing approved backup before a future release. Recovery then means restoring the pre-G1 database and application together, not detaching customers or rewriting history. An unused extension can be removed while restoring the original constraints. See the verification report for actual image/test evidence. Production performance certification remains pending the target VPS.
