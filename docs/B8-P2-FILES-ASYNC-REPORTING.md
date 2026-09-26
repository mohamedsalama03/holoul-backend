# B8-P2 — Files, AI, Notifications, Reporting and Audit Contract Review

Status: this document records the frontend contract of approved B1–B7 plus the uncommitted B8 candidate. It does not approve B8, certify production performance, enable an AI provider, or alter the failed performance gate.

Canonical machine-readable input: `docs/contracts/files-async-reporting.json` (43 operations, 49 schemas). The consolidated OpenAPI file and verification report are produced by the root review task. No product behavior was changed by this domain review.

## Review findings and decisions

1. **Document boundary is safe and explicit.** Public metadata contains only `id, filename, format, mime, bytes, state, version, retryable`; project attachments add `visibility`. Storage keys, bucket names, object versions, signed URLs, source checksums, scanner diagnostics and failure details are not document response fields. Current parent/customer identity and exact immutable object are rechecked after storage I/O and before download bytes.
2. **Upload finalization is part of PUT.** There is no separate finalize endpoint, multipart contract, presigned direct upload or public storage URL. This must be reflected in frontend implementation.
3. **Document and parent versions differ.** Request/project document responses expose a document `version`, while their ETag belongs to the parent request/project. Proposal document GET has no ETag. The frontend must preserve the returned header for the relevant parent and never assemble it from document metadata.
4. **Removal semantics differ by domain, deliberately.** Request draft removal detaches an attachment and only deletes unreferenced bytes. Project deletion cancels a pending uploading reservation; finalized project evidence is immutable. Proposal attachment mutation belongs to CommercialController and is covered in its companion contract.
5. **Disabled AI is a durable unavailable result.** A valid create returns HTTP202 with `state=unavailable` and `failure_code=provider_unavailable`; budget/run-capacity refusals also return202 with a stable failure code. This is not a submission failure and must not be treated as an HTTP503-only case.
6. **Suggestion and run states are separate.** Applying or dismissing keeps the run `succeeded`; suggestion state changes. A stale source is rejected409 at apply, without adding a fictional `stale` run state.
7. **Pagination differs across established APIs.** Project documents use page/per_page; AI and notifications use UUID after/limit; audit uses opaque cursor/limit; reports use bounded date windows. These compatible existing contracts are frozen and documented instead of being renamed.
8. **Every reviewed collection is bounded.** Detailed bounds appear below. Delivery attempt history looks unpaginated but PostgreSQL enforces at most100 generations ×5 attempts, unique per delivery/generation/attempt; its maximum is500.
9. **Existing timestamp serialization differs.** Some notification/delivery and AI processing timestamps are PostgreSQL text with a space and `+00`; model-cast timestamps can be ISO8601. Schemas accept both exact shapes. Normalize before browser date parsing; do not assume all strings pass a strict RFC3339-only parser.
10. **Operational metadata is narrowly exposed.** AI history deliberately retains the caller's source IDs/hash, checksum, provider/model, token counts and integer micro-USD cost; it does not return raw source text, prompt, credentials, storage version, provider operation ID, or internal reservation/fence fields. Keep these technical fields out of normal customer presentation. Restricted delivery investigation includes a bounded opaque provider reference. Audit omits even safe internal metadata from its public projection.
11. **No TODO/FIXME found in application, routes, configuration and tests during this review.** Read-only review does not prove global dead-code absence; the owned controllers and APIs are directly routed. No dead-code removal or stylistic endpoint rename was attempted.
12. **No demonstrated contract-breaking correction required in these owned modules.** The documentation resolves frontend ambiguities. Full regression/schema evidence is recorded in the consolidated review, not claimed from this source inspection.

## Upload, scan, download and removal guide

All paths below are relative to the same-origin `/api/v1` gateway and use the established session cookie, Origin and CSRF contract. Unsafe requests send the decoded readable XSRF cookie in `X-XSRF-TOKEN`; authentication cookies remain HttpOnly. Send JSON with `Content-Type: application/json`, except the raw content PUT.

### Customer request attachment

1. Read/create the draft request and retain its strong ETag. The customer must have verified email and an open editable draft; a closed/submitted state without an open amendment rejects upload mutations409.
2. Calculate the complete file's SHA-256 in lowercase hex and exact integer byte length.
3. POST `/project-requests/{projectRequest}/documents` with `{filename, bytes, sha256}`, parent `If-Match`, and a new `Idempotency-Key` matching `[A-Za-z0-9_.:-]{16,128}`. HTTP201 returns safe metadata in `data`, a parent ETag and relative metadata `Location`. Reusing the identical key/input returns the original reservation; conflicting reuse409.
4. Retain the new parent ETag. PUT `/project-requests/{projectRequest}/documents/{document}/content` using the file bytes directly, exact `Content-Type: application/octet-stream`, CSRF/Origin and that parent `If-Match`. The API verifies byte count, SHA-256, filename format and byte signature, stores the immutable object, rechecks authorization and finalizes. HTTP200 normally returns `quarantined`. No additional finalize request is needed.
5. Poll metadata GET with backoff. File processing changes `data.version` independently of parent ETag. Download only when `state=available`. The request submission rule permits a finalized `quarantined` or `available` attachment; uploading/rejected/deleting/deleted attachments cannot be snapshotted.
6. Download by authenticated API GET `.../{document}/download`. The response streams PDF/DOCX with attachment disposition, private/no-store and nosniff. A frontend may save the response Blob under the safe filename. There is no storage URL to persist.
7. To replace the single draft attachment, create a new reservation on the current draft with a fresh key and ETag, then upload it. Replacement detaches the old draft link. Historical submitted/proposal/AI references retain their original bytes.
8. DELETE `.../{document}` with parent `If-Match` removes only the current draft link. The returned state is `deleting` only if no retained reference exists; otherwise the original state may remain. Metadata may later show a `deleted` tombstone. A removed document is not downloadable through the draft link.

### Staff project attachment

Use `/admin/projects/{project}/documents` for POST and `.../{document}/content` for PUT. Creation requires explicit `visibility=internal|customer`; project access and `projects.documents.upload` are required, with document-read authority also checked when rendering final upload response. Preserve the PROJECT ETag.

A reservation increases the project version. Successful PUT moves the pending upload into permanent project evidence and enqueues scanning without another parent version bump. Staff collection includes pending uploads; customer collection includes only finalized customer-visible attachments. The returned file may still be quarantined/rejected until processing completes.

DELETE only cancels an `uploading` pending reservation. An attached/finalized document returns409; the API intentionally supplies no finalized evidence replacement/removal or visibility-edit endpoint. Completed/cancelled projects reject upload mutations. This is a domain constraint, not an omitted destructive action to invent in the UI.

Proposal metadata/download use nested `/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}` paths (staff prefix `/admin`). Customers only see issued proposal versions and attached documents. Staff attach/remove commands reuse an available historical intake attachment; there is no proposal-specific raw upload.

### Formats, limits and states

- Supported: PDF (`application/pdf`) and DOCX (`application/vnd.openxmlformats-officedocument.wordprocessingml.document`),1–10,485,760bytes inclusive. Extension and detected content must agree; DOCX receives structural inspection and all files require scan success before download.
- Display filename input is at most240characters; sanitization strips path/control characters and yields a nonempty basename of at most200characters, with a1024UTF-8-byte raw ceiling.
- Reservation lifetime10minutes. Per customer: at most2 active uploading reservations,1GiB reserved across non-deleted documents and1000 non-deleted documents. Reservation/content/manual retry operations are20/minute per actor per purpose (request/project scopes have separate buckets).
- Scan transport/outage exhaustion can leave a document quarantined with `retryable=true`. Manual scan-retries is allowed twice and requires parent If-Match. A rejected malicious/invalid file is not retryable.
- Initial reservation size violations422; actual oversized stream413; length/checksum mismatch422; unsupported media/format415; stale parent412; missing precondition428; lifecycle/access-scope conflict409; quotas/rate429; storage outage503. Preserve `Retry-After` when present. No raw scanner error is surfaced.

| API state | Frontend label | Action |
|---|---|---|
| uploading | Uploading | Send/retry identical reserved bytes within10minutes; show progress locally. |
| quarantined | Quarantined | Await scan. Show retry only when retryable=true and caller may mutate. |
| available | Available | Enable authorized API download. |
| rejected | Rejected | Explain file could not be accepted; no download/manual scan retry. |
| deleting | Deleting | Disable file actions while asynchronous cleanup runs. |
| deleted | Removed | Tombstone; no private content remains available through this object. |

The API does not return upload expiry or a detailed rejection reason. Use the fixed10-minute contract and safe state-based language; handle expiry409 with a fresh parent read/reservation.

## Optional AI integration guide

The current backend setting is `HOLOUL_AI_ENABLED=false` (`config('ai.enabled')`); an independent frontend `AI_ENABLED=false` feature setting hides/disables optional assistance. There is no public capabilities/settings endpoint. Project submission always uses the normal intake flow and must work without any AI run. Never put provider keys in Next.js public configuration.

Creation: POST `/ai-runs` with `parent_type=request|project`, UUIDv7 `parent_id`, purpose, optional `document_id`, literal `consent:true`, PARENT If-Match and Idempotency-Key. Success is202 with a RUN ETag. Read GET `/ai-runs/{aiRun}` using the same actor and current parent scope; runs are actor-private, even within a customer account.

Purpose rules:

| Purpose | Result shape | Available application |
|---|---|---|
| improve_description | description (1–20,000characters) | Customer editable request draft only. No document input. |
| suggest_category | category_id, subcategory_id, reason (≤1000) | Customer editable request draft only; current valid taxonomy. No document input. |
| analyze_document | summary (≤6000), findings (≤20 strings of≤1000) | Advisory only; available authorized document required. |
| extract_requirements | requirements (1–50 title/description/priority objects), questions (≤20) | Staff can append to same-source editable discovery revision; other outputs advisory. |
| missing_information | questions (≤20 strings of≤1000) | Advisory only. |

Project parents require an available document and only analyze_document/extract_requirements/missing_information. Staff request runs read the current submitted revision and reject draft/converted/withdrawn/rejected parents; customer request runs use the editable draft. Outputs are validated data, never executable instructions or HTML.

Run states: `pending, processing, succeeded, failed, unavailable, cancelled`. Suggestion state: `pending, applied, dismissed`; suggestion itself is null without a successful result. Poll using backoff and stop at terminal run state. A succeeded run can retain a pending suggestion until the human decides.

POST `/ai-runs/{id}/applications` uses the RUN If-Match and a new Idempotency-Key. Customer application uses an empty body or `{}`; staff extracted-requirement application supplies `discovery_revision_id`. Source staleness/changed content/checksum/authorization prevents applying; the source change normally returns409 (permission loss can403/404). Refetch the parent after successful application. Dismiss via `/dismissals`; cancel only pending/processing via `/cancellations`. All decisions use current run ETag and idempotency receipt; run state remains succeeded after apply/dismiss.

No persisted stale state exists. A frontend may compare the run's source_version with a fresh parent's version to warn early, but must still let the API enforce the decision atomically. Do not regenerate or apply automatically.

Availability failures at HTTP202:
- `provider_unavailable`: disabled/not-allowlisted provider or model.
- `daily_budget_exhausted`: daily monetary reservation limit.
- `concurrent_limit`: global or actor concurrent run limit.
- `daily_user_limit`: daily actor run count.

Processing failure codes include `source_unavailable, source_or_authorization_changed, attempts_exhausted, malformed_output, provider_outcome_unknown` and bounded provider failure codes. Treat unknown safe codes as a generic AI failure and retain request/run references for support; never depend on exception messages. API rate limits return429 (120AI requests/minute per actor, creation20/minute). Defaults:8global/2per-user concurrent,20daily runs/user,10,000,000micro-USD daily budget; these internal backend settings are not a public billing promise.

This phase keeps external AI disabled. Existing tests use provider doubles/sandbox; the review does not authorize live provider traffic.

## Notifications, reporting and audit

Notifications are recipient-private and contain generic safe title/message plus resource references. Always call the referenced resource API to enforce current access; a notification is not an authorization grant. There is no WebSocket/SSE/push contract. Poll unread-count/list conservatively (for example30–60seconds while visible), back off on429/503, and stop after401/logout. A list's `next_after=null` ends that page chain, but retaining the last returned data.id allows a subsequent poll for new arrivals. Ascending order is established; no unread-only/search/sort filter exists.

Keep notification, inbox, preference and delivery ETags separate. All notification writes require Idempotency-Key. Read-all uses inbox ETag from list/unread-count; a single read uses the notice ETag; preference update uses preference ETag. Inbox/preference IDs both equal the user UUID, so the ETag text can coincide by version while still representing distinct resources.

Delivery investigation is staff-only. `accepted` means the provider acknowledged the email, not guaranteed delivery/read. `uncertain` must not be automatically resent. A replay requires recent password confirmation, a reason, delivery ETag, Idempotency-Key and either `retry_failure` or operator-confirmed `confirmed_not_accepted` resolution. Generation100 cannot be replayed again.

Reports are verified-staff aggregate reads with reporting.read, up to366inclusive UTC days ending today or earlier (default last30days). They describe current states of selected cohorts, not historical snapshots. Requests use first submission; projects/customer growth use creation; proposal versions use issuance. Omitted trend days mean zero. Currency values are exact minor-unit strings, separately USD(scale2)/LYD(scale3); accepted proposal value is not revenue. Empty-denominator rates are null.

Audit investigation requires verified staff with audit.investigate; supports event_type,actor_id,subject_type,subject_id,request_id and bounded dates. It returns allowlisted IDs/types/occurred_at and never raw metadata/content. Each read appends its own safe audit event. The cursor is encrypted and bound to the exact window/filters; when using defaults across UTC midnight, explicitly retain resolved meta.window dates with the next cursor.

| Collection | Bounds and metadata | Filters / fixed order |
|---|---|---|
| Project documents | page1–100000; per_page1–100(default25); meta.page,per_page,total | document_id ascending; visibility enforced by persona, no caller filter. |
| AI runs | limit1–100(default25); UUIDv7 after; meta.next_after | Required parent_type,parent_id; actor-private; id ascending. |
| Notifications | limit1–100(default25); UUIDv7 after; meta.next_after | Current recipient; id ascending. |
| Deliveries | limit1–100(default25); UUIDv7 after; meta.next_after | Optional state; id ascending. |
| Delivery attempts | ≤500 by DB generation/attempt constraints; embedded array | generation then attempt_number ascending. |
| Report trends | ≤366day buckets | UTC day ascending. |
| Report category groups | top100 plus other_requests and limit100 | Count descending, IDs as deterministic tie-breakers. |
| Report states/currencies | ≤10request states,≤8project states,≤2currencies | State/currency ascending. |
| Audit | limit1–100(default25); opaque cursor≤2048; meta.next_cursor,limit,window | occurred_at DESC,id DESC; exact filters as above. |
| AI suggestion lists | Findings/questions≤20; requirements≤50 | Provider-validated order. |

## Complete owned endpoint matrix

Each row describes effective permission requirements. For mixed AI personas, self permissions apply to customers, non-self permissions to staff; the lists are conditional, not a demand that every actor possess both. Parent scope/assignment and verified-email/recent-password requirements remain authoritative as explained above. Unsafe operations also require Origin/CSRF, with If-Match and Idempotency-Key documented in OpenAPI.

| Frontend feature | Method and endpoint | Persona | Permission |
|---|---|---|---|
| Reserve request document | `POST /api/v1/project-requests/{projectRequest}/documents` | customer | Current customer/parent ownership; no separate document grant |
| Upload and finalize request document | `PUT /api/v1/project-requests/{projectRequest}/documents/{document}/content` | customer | Current customer/parent ownership; no separate document grant |
| Read request document metadata | `GET /api/v1/project-requests/{projectRequest}/documents/{document}` | customer | Current customer/parent ownership; no separate document grant |
| Remove draft document | `DELETE /api/v1/project-requests/{projectRequest}/documents/{document}` | customer | Current customer/parent ownership; no separate document grant |
| Download request document | `GET /api/v1/project-requests/{projectRequest}/documents/{document}/download` | customer | Current customer/parent ownership; no separate document grant |
| Read request document metadata | `GET /api/v1/admin/project-requests/{projectRequest}/documents/{document}` | staff | `intake.read`, `documents.read` |
| Download request document | `GET /api/v1/admin/project-requests/{projectRequest}/documents/{document}/download` | staff | `intake.read`, `documents.download` |
| Retry request document scan | `POST /api/v1/project-requests/{projectRequest}/documents/{document}/scan-retries` | customer | Current customer/parent ownership; no separate document grant |
| List project documents | `GET /api/v1/projects/{project}/documents` | customer | `projects.self.read`, `projects.self.documents.read` |
| Read project document | `GET /api/v1/projects/{project}/documents/{document}` | customer | `projects.self.read`, `projects.self.documents.read` |
| Download project document | `GET /api/v1/projects/{project}/documents/{document}/download` | customer | `projects.self.read`, `projects.self.documents.read` |
| List project documents | `GET /api/v1/admin/projects/{project}/documents` | staff | `projects.read`, `projects.documents.read` |
| Reserve project document | `POST /api/v1/admin/projects/{project}/documents` | staff | `projects.read`, `projects.documents.upload` |
| Read project document | `GET /api/v1/admin/projects/{project}/documents/{document}` | staff | `projects.read`, `projects.documents.read` |
| Cancel pending project upload | `DELETE /api/v1/admin/projects/{project}/documents/{document}` | staff | `projects.read`, `projects.documents.read`, `projects.documents.upload` |
| Download project document | `GET /api/v1/admin/projects/{project}/documents/{document}/download` | staff | `projects.read`, `projects.documents.read` |
| Upload and finalize project document | `PUT /api/v1/admin/projects/{project}/documents/{document}/content` | staff | `projects.read`, `projects.documents.upload`, `projects.documents.read` |
| Retry project document scan | `POST /api/v1/admin/projects/{project}/documents/{document}/scan-retries` | staff | `projects.read`, `projects.documents.read`, `projects.documents.upload` |
| Read proposal document | `GET /api/v1/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}` | customer | `proposals.self.read` |
| Download proposal document | `GET /api/v1/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}/download` | customer | `proposals.self.read` |
| Read proposal document | `GET /api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}` | staff | `intake.read`, `proposals.read`, `documents.read` |
| Download proposal document | `GET /api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}/download` | staff | `intake.read`, `proposals.read`, `documents.download` |
| List own AI runs | `GET /api/v1/ai-runs` | customer / staff | `ai.self.use`, `ai.use` |
| Request optional AI assistance | `POST /api/v1/ai-runs` | customer / staff | `ai.self.use`, `ai.use` |
| Poll own AI run | `GET /api/v1/ai-runs/{aiRun}` | customer / staff | `ai.self.use`, `ai.use` |
| Apply AI suggestion explicitly | `POST /api/v1/ai-runs/{aiRun}/applications` | customer / staff | `ai.self.use`, `ai.use`, `ai.self.apply`, `ai.apply`, `discovery.manage` |
| Dismiss AI suggestion | `POST /api/v1/ai-runs/{aiRun}/dismissals` | customer / staff | `ai.self.use`, `ai.use` |
| Cancel pending AI assistance | `POST /api/v1/ai-runs/{aiRun}/cancellations` | customer / staff | `ai.self.use`, `ai.use` |
| List own notifications | `GET /api/v1/notifications` | customer / staff | `notifications.self.read` |
| Poll unread notification count | `GET /api/v1/notifications/unread-count` | customer / staff | `notifications.self.read` |
| Mark own inbox read | `POST /api/v1/notifications/read-all` | customer / staff | `notifications.self.manage` |
| Read notification preference | `GET /api/v1/notifications/preferences` | customer / staff | `notifications.self.read` |
| Change workflow email preference | `PATCH /api/v1/notifications/preferences` | customer / staff | `notifications.self.manage` |
| Read own notification | `GET /api/v1/notifications/{notification}` | customer / staff | `notifications.self.read` |
| Mark notification read | `POST /api/v1/notifications/{notification}/read` | customer / staff | `notifications.self.manage` |
| Inspect notification deliveries | `GET /api/v1/admin/notification-deliveries` | staff | `notifications.delivery.read` |
| Inspect delivery attempts | `GET /api/v1/admin/notification-deliveries/{delivery}` | staff | `notifications.delivery.read` |
| Replay failed notification delivery | `POST /api/v1/admin/notification-deliveries/{delivery}/replays` | staff | `notifications.delivery.replay` |
| Read dashboard statistics | `GET /api/v1/admin/reports/dashboard` | staff | `reporting.read` |
| Read requests statistics | `GET /api/v1/admin/reports/requests` | staff | `reporting.read` |
| Read projects statistics | `GET /api/v1/admin/reports/projects` | staff | `reporting.read` |
| Read customers statistics | `GET /api/v1/admin/reports/customers` | staff | `reporting.read` |
| Investigate audit events | `GET /api/v1/admin/audit-events` | staff | `audit.investigate` |

## Frontend/backend gaps to preserve visibly

- No public AI capability endpoint; deployment supplies the optional feature flag.
- No upload-expiry field, scan-progress percentage or granular rejection reason; use fixed reservation lifetime and safe states.
- No finalized project evidence deletion/replacement or visibility change; these are deliberate integrity constraints.
- No notification unread-only/search/sort, push, or email delivery receipt/read tracking.
- Reporting has request taxonomy filters, date windows and bounded aggregates; it has no arbitrary reporting query engine, CSV/export route, all-time unbounded window, multi-currency total or revenue API.
- No standalone document search/browser/upload route; documents remain nested within authorized requests/proposals/projects.
- This domain review did not receive a separate approved dashboard design artifact. These are verified current capability limits, not a claim that every limit contradicts an approved UI requirement.

## Evidence and scope limits

Read sources: the six owned controllers and application APIs; form requests; DocumentPolicy/DocumentView/DocumentState/DocumentFormat; IntakeDocuments/ProjectDocuments/ProposalDocuments; AIRuns/AISchema/CurrentAIContext/ApplyAISuggestion; NotificationAccess and its constrained migration; OperationalReports and four module-owned reporting readers; InvestigateAudit; all owning route files.

The fragment covers43 routed frontend operations and49 strict named schemas with explicit body/query/header/response/permission contracts. Every x-source reference is a repository path. No tests, builds, DB writes, performance workload or external AI call were run by this delegated source-review pass. Consolidated OpenAPI validation, real HTTP schema coverage, full regression results and production blockers belong to the parent B8-P2 verification report.
