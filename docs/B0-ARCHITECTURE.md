# HOLOUL Backend — B0 Architecture Gate

**Approved policy amendment, 3 October 2026:** Customer registration and ordinary project intake no longer require email confirmation. See [the customer email prerequisite decision](CUSTOMER-EMAIL-PREREQUISITE-REMOVAL.md) for scope, retained guest-claim proof and verification evidence. The original architecture text below is preserved as historical evidence.

**Status:** Proposed architecture; approval required before B1.  
**Inspection date:** 16 September 2026.  
**Scope:** Architecture only. No application code, Laravel initialization, migrations, Docker files, or dependency installation.

## 1. Architecture Summary

**Repository inspection:** The supplied workspace, `/home/mohamed/projects/customers/holoul` in Ubuntu on WSL, was empty, including hidden entries. It is not a Git repository, nor inside one. Branch, HEAD, and Git worktree status are therefore **not applicable**. No existing stack, configuration, or applicable ancestor AGENTS.md was found. This report is the only proposed workspace addition.

**Decision:** One Laravel modular monolith, one PostgreSQL database, and separately operated web, queue, and scheduler processes. Next.js consumes `/api/v1` through the same public origin. PostgreSQL owns business records, permissions, history, sessions, and durable asynchronous work intent. Redis provides queue transport, disposable cache, and throttling.

**Proposed baseline:** Laravel 13, PHP 8.4, PostgreSQL 18, and a maintained Redis release. Pin compatible patch versions, Composer dependencies, and container digests in B1. Laravel 13 supports PHP 8.4 and has security support through March 2028. [Laravel support policy](https://laravel.com/framework/docs/13.x/releases#support-policy).

Use explicit application actions, Eloquent, Policies, validated input objects, and API Resources. Controllers coordinate HTTP concerns only. Introduce interfaces at external provider boundaries and genuinely shared module entry points; avoid repositories wrapping every Eloquent model, a generic workflow engine, and universal service layers.

**Non-negotiable invariants:** Original submitted content remains retrievable; AI cannot overwrite it; only an accepted, internally approved proposal permits conversion; one request creates at most one separate Project; customer ownership cannot be supplied or changed through customer input; documents remain inaccessible until authorized and cleared; external outages cannot roll back successful core business changes.

No microservices, Kubernetes, Elasticsearch, CQRS, event sourcing, payments, or currency conversion in the initial scope.

## 2. Module Map + Dependency Rules

Each module owns its writes, tables, actions, Policies, and tests. Modules share one deployment and transaction manager.

| Module | Ownership | Permitted direct dependencies |
|---|---|---|
| Identity & Access | Users, credentials, verification, sessions, roles, permissions | Audit |
| Customers | Customer profile and account ownership | Identity & Access, Audit |
| Categories | Main categories, subcategories, active flags and ordering | Audit |
| Project Intake | Drafts, submitted revisions, assignments, information requests, intake transitions | Customers, Categories, Documents, Audit |
| Documents | Private blob metadata, upload lifecycle, scanning, extraction, deletion | Audit; storage/scanner contracts |
| AI Assistance | AI runs, validated suggestions, usage reservations, provider adapter | Documents, Audit; AI provider contract |
| Discovery | Discovery records, requirement revisions and review completion | Project Intake read contract, Documents, Audit |
| Proposals | Versioned scope, prices, internal approval, issuance and customer decisions | Project Intake and Discovery read contracts, Documents, Audit |
| Projects | Converted project, accepted baseline, team membership, milestones, delivery state | Project Intake and Proposals read contracts, Documents, Audit |
| Notifications | Templates, preferences, delivery attempts and deduplication | Identity & Access/Customers recipient contracts, Audit; delivery contract |
| Administration | Administrative HTTP actions and typed non-secret settings | Other modules' authorized public actions |
| Audit | Append-only security/business events and restricted audit reads | No business-module dependency |
| Reporting | Authorized operational reports and dashboards | Approved read contracts and reviewed SQL joins; no business writes |

**Boundary rules:**

- An outer application workflow composes multi-module actions inside one PostgreSQL transaction: issuing/accepting proposals and converting requests are examples. Lower-level modules do not call back into that workflow.
- No cross-module direct writes, model observers causing hidden business effects, or provider calls from controllers. Cross-module foreign keys are encouraged; independently deployable modules are not a requirement.
- Owner modules hold explicit attachment tables and authorize parent/document access. Documents receives trusted server-created access context and does not import Intake, Proposals, or Projects. Customers cannot manufacture that context through HTTP fields.
- AI receives an authorized, immutable input revision through application orchestration. Applying a suggestion invokes the owning module's action after human confirmation; AI never writes another module's tables.
- In-process events and durable work records handle secondary effects. The publisher does not depend on Notifications. Shared infrastructure contains only technical facilities such as clock, money, transactions, and operation dispatch.
- Architecture tests enforce these imports and entry points. Reporting joins require ownership filters and selected columns; they are not an exemption from authorization.

## 3. High-Level Database / Entity Model

### Entities and relationships

| Area | Principal relational records and invariants |
|---|---|
| Access/customer | users; customers with unique user_id; roles; permissions; user_roles; role_permissions; sessions. Initially one customer profile per customer login; organizations are deferred. |
| Taxonomy | categories; subcategories with category_id. Archive referenced taxonomy instead of deleting it. |
| Intake | project_requests holds immutable customer_id, state, public reference and lock_version. request_drafts holds editable, possibly incomplete fields. request_revisions holds complete immutable submissions/amendments, unique (request_id, revision_number). request_assignments, information_requests/responses and request_state_changes preserve workflow history. |
| Submission content | Each submitted revision snapshots full name, email, normalized phone, selected main/subcategory IDs and labels, project name/description, budget-known flag, amount/currency, author, provenance and submission time. Later profile/taxonomy changes do not rewrite history. |
| Discovery | discovery_records; discovery_revisions; requirements linked to a revision; completion/sign-off records. Internal notes and customer-visible information have explicit visibility. |
| Proposals | proposals identifies a numbered revision for a request; proposal_items and scope/deliverables belong to that revision; proposal_approvals and proposal_decisions record staff approval and customer acceptance/decline separately. Issued terms are immutable. |
| Projects | projects has UNIQUE NOT NULL source_request_id, required accepted_proposal_id and immutable customer_id constrained to match its source request; project_members; milestones; project_state_changes. Scope starts as an explicit baseline from accepted terms, with later change records. |
| Documents | documents records immutable customer_id derived from the authorized parent, uploader, generated object key/version, verified type/size/checksum and lifecycle. Typed draft/revision/discovery/proposal/project attachment tables provide real parent FKs and visibility. Composite parent/customer and document/customer FKs reject cross-customer links. |
| AI/work | ai_runs; ai_suggestions with source revision and human disposition; ai_usage_reservations; async_operations; notification_deliveries; idempotency_keys. Work outcomes and retry eligibility live in PostgreSQL. |
| Audit/settings | audit_events; typed application_settings with versioned changes. Secret values remain in the deployment secret store. |

~~~mermaid
erDiagram
    USER ||--o| CUSTOMER : owns
    CUSTOMER ||--o{ PROJECT_REQUEST : submits
    PROJECT_REQUEST ||--o{ REQUEST_REVISION : preserves
    PROJECT_REQUEST ||--o{ DISCOVERY_REVISION : investigates
    PROJECT_REQUEST ||--o{ PROPOSAL : receives
    PROPOSAL ||--o{ PROPOSAL_DECISION : records
    PROJECT_REQUEST ||--o| PROJECT : converts_to
    PROPOSAL ||--o| PROJECT : establishes_baseline
    REQUEST_REVISION ||--o{ REQUEST_REVISION_DOCUMENT : attaches
    DOCUMENT ||--o{ REQUEST_REVISION_DOCUMENT : references
~~~

### Identifiers, values, and constraints

- **Identifiers:** Native PostgreSQL UUID columns, application-generated UUIDv7 primary keys and public IDs. UUIDs reduce accidental enumeration but do not authorize access and reveal approximate creation ordering. Avoid a second public-ID scheme without a requirement.
- **Human references:** Immutable `REQ-2026-00125`, `PRJ-2026-00125`, and `PROP-2026-00125`, assigned on submission, conversion, and issuance respectively. Use a PostgreSQL sequence per record type, format with UTC allocation year and a minimum five-digit suffix. The sequence does not reset annually; gaps and larger suffixes are valid. Never use MAX+1, reuse a reference, or promise gapless numbering. [PostgreSQL sequence behavior](https://www.postgresql.org/docs/18/functions-sequence.html).
- **Constraints:** NOT NULL for required submitted fields; FK for every business relationship; unique normalized login email; unique references/revision numbers/memberships; CHECK for valid state values, non-negative amounts and bounded field sizes. Enforce selected subcategory/category membership with a composite FK. Proposal/request and project/accepted-proposal relationships also use composite keys to prevent mismatched parents.
- **Cross-row rules:** At most one currently issued proposal per request through a partial unique index. Acceptance and conversion use locked transactions; an ordinary CHECK cannot enforce another row's state. Small database triggers guard protected history tables against UPDATE/DELETE by the application role; do not build a second workflow engine in triggers. [PostgreSQL constraints](https://www.postgresql.org/docs/18/ddl-constraints.html).
- **Money:** Signed BIGINT minor units plus a supported currency FK; domain rules disallow negative budgets/prices. USD has exponent 2; LYD has exponent 3. Thus USD 12.34 = 1234 units, LYD 12.345 = 12345 units. API amounts are decimal strings; parse exactly, reject excess precision, and never use float arithmetic or silently round. Totals use overflow-checked integer arithmetic. Initially no fractional proposal quantities, tax engine, payment ledger, or automatic FX. [ISO 4217 authoritative currency list](https://www.six-group.com/dam/download/financial-information/data-center/iso-currrency/lists/list-one.xml).
- **Unknown budget:** In a submitted revision, unknown=true requires amount and currency NULL; unknown=false requires amount >= 0 and USD/LYD currency. Zero is distinct from unknown. Drafts may be incomplete; submission validates the complete snapshot.
- **Dates/contact:** Store instants as timestamptz, operate in UTC, return ISO 8601 UTC. Use DATE for actual calendar deadlines and an explicit business timezone when interpreting them. Normalize phone using a maintained numbering library and selected region into E.164; keep submitted contact text in the private snapshot. A valid format is not proof of ownership. Normalize account email consistently without provider-specific dot/plus rewriting; preserve display spelling.
- **JSONB:** Limited to bounded, versioned AI output/provider metadata, technical operation payloads and redacted audit metadata. Ownership, budgets, category membership, requirements, proposal items, statuses, and approvals remain relational.

### Transactions, concurrency, and history

Submission atomically creates a revision, changes state, allocates its reference, writes audit/history, and records notification work. Proposal acceptance atomically locks the request and specific proposal, checks ownership/current issuance/expiry/internal approval, stores acceptance, and marks the request Approved. Conversion atomically creates the Project and baseline, sets Converted, and writes history/audit/work intent. No HTTP, storage, email, or AI call runs inside these transactions.

Use READ COMMITTED with explicit row locks for competing business actions and uniqueness for final protection. Lock consistently: request, proposal, project, then related rows. Mutable records expose lock_version through ETag/If-Match; compare again under lock and increment atomically. Stale writes fail; do not overwrite them. Retry deadlocks only with a small bound and an idempotent whole transaction. [PostgreSQL row locking](https://www.postgresql.org/docs/18/explicit-locking.html).

Submitted revisions, issued proposal terms, decisions, and state history are append-only. Customer amendments create new revisions; AI-assisted edits record suggestion ID, actor, source revision and explicit acceptance. Revision 1 remains the original. Amendments affecting issued/accepted terms require returning to Discovery and superseding the proposal; after conversion they become project change requests. Restrict deletion of referenced business entities; use archival status instead of universal soft deletion. Privacy erasure is a separately authorized retention process, not an ordinary edit.

## 4. Authentication / RBAC Model

**Default origin:** One HTTPS hostname routes the Next.js UI, `/api/v1`, `/sanctum/csrf-cookie`, and session endpoints. Use Sanctum stateful session authentication with an exact trusted-origin allowlist. Same-origin routing avoids broad parent-domain cookies. If later split across subdomains, retain the same registrable domain and explicitly configure credentialed CORS; unrelated domains require a new authentication decision. [Sanctum SPA authentication](https://laravel.com/framework/docs/13.x/sanctum#spa-authentication).

The session cookie is host-only, Secure, HttpOnly, Path=/, SameSite=Lax. The separate XSRF-TOKEN cookie must be JavaScript-readable for the CSRF header; making every cookie HttpOnly would break that flow. Initialize CSRF before login; protect login, logout, and every state-changing request. Rotate session ID on login and privilege changes, invalidate on logout, and revoke other sessions after password reset or security-sensitive account changes. Persist sessions in PostgreSQL; never store bearer tokens in browser localStorage or provision personal API tokens for the first-party SPA.

Require verified email to submit, upload, accept proposals, or request AI work. Email verification/reset links are expiring, purpose-bound and throttled; password-reset tokens are single-use and stored hashed. Responses do not reveal account existence. Require MFA for staff before production, including recovery codes and recent reauthentication for role changes. Proposed staff timeout: 30 minutes idle / 12 hours absolute; customer: 2 hours idle / 7 days absolute, without staff remember-me.

| Role | Initial authorized scope |
|---|---|
| Super Admin | Break-glass identity/security administration; explicit audited access to business data, no automatic invariant bypass. |
| Administrator | User administration, taxonomy and operational settings. Cannot grant Super Admin or accept customer proposals. Broad private-content access is a separate permission. |
| Project Manager | Assigned requests/projects, assignment, final intake rejection, internal proposal approval, conversion and project transitions. |
| Business Analyst | Assigned discovery, requirements, information requests and proposal scope drafts. |
| Sales | Assigned commercial terms, draft/issue internally approved proposals and customer follow-up. Cannot accept on the customer's behalf. |
| Reviewer | Assigned intake review, clarification and discovery handoff; recommends rejection. |
| Support | Minimum customer/contact and progress information needed for support. No document body, commercial approval, or role management by default. |
| Customer | Own drafts/submissions, permitted amendments, customer-visible documents/projects, and acceptance/decline of own issued proposal. |

Permissions express actions; Policies add ownership, staff assignment, visibility, record state and recent-auth requirements. Multiple staff roles are possible; internal proposal approval requires an actor other than its author. Customer/staff personas are not mixed by ordinary role grants. Role assignment cannot grant permissions the administrator lacks; security roles require Super Admin, and the last enabled Super Admin cannot be removed.

Scope list/search queries before fetching records, then apply Policies to reads/writes, nested resources, downloads, exports and queued work. Check parent-child relationships explicitly. Recheck current access before starting delayed user-requested work or issuing download access. Return 404 for another customer's resource and 403 for a visible resource with a disallowed action. Resource IDs, staff UI visibility and cache entries never substitute for authorization.

## 5. ProjectRequest + Project State Machines

### ProjectRequest

| From | Allowed next state | Actor / required condition |
|---|---|---|
| Draft | Submitted | Owner; verified email, valid complete snapshot. Pending document scanning does not block text submission. |
| Submitted | Under Review | Assigned Reviewer or PM claims review. |
| Under Review | Information Required, Discovery, Rejected | Reviewer/BA may request information; Reviewer/PM starts discovery; PM rejects with reason. |
| Information Required | Under Review or Discovery | Return only to the persisted originating phase after owner response and responsible staff acknowledgement; no arbitrary target. |
| Discovery | Information Required, Proposal, Rejected | Assigned BA/PM requests information; Sales/PM issues an internally approved proposal after discovery sign-off; PM rejects with reason. |
| Proposal | Approved | Owner accepts the current, unexpired, internally approved issued proposal revision with recent authentication. |
| Proposal | Discovery | Customer declines, proposal expires, or staff supersedes/withdraws issued terms; append reason and preserve terms/decision. |
| Approved | Converted | PM; acceptance still valid, no withdrawal, no existing project. |
| Approved | Discovery | Owner rescinds before conversion, or owner agrees to renegotiation; append rescission and invalidate that acceptance for conversion. |
| Submitted, Under Review, Information Required, Discovery, Proposal, Approved | Withdrawn | Owner withdraws before conversion; close outstanding proposal/AI work as appropriate. |

Rejected, Withdrawn and Converted are terminal request states. Draft deletion is allowed under retention rules. Reopening a closed request requires a new linked request, not rewriting history. Information Required also permits PM rejection with a documented reason; silence does not cause automatic rejection.

**Adjustments:** Rejected never leads to Converted. Withdrawn represents customer cancellation. Proposal means an issued proposal is awaiting a customer decision, not merely that staff started drafting. Approved specifically means customer acceptance; internal staff approval is a separate proposal fact. Declining a proposal returns to Discovery instead of automatically rejecting the customer's request. The same request can have many preserved proposals, but only one conversion.

Proposal revision lifecycle: Draft → Internally Approved → Issued → Accepted / Declined / Expired / Superseded / Withdrawn. Accepted → Rescinded is allowed only before conversion, through the owner's rescission/withdrawal or agreed renegotiation. It appends a rescission decision and changes the request atomically. Editing before issue invalidates internal approval. Issued content cannot be edited. Acceptance, expiry, supersession and conversion use the same request/proposal lock order and server time; acceptance losing a race must fail safely.

### Project

| From | Allowed next state | Guard |
|---|---|---|
| Planning | Design | Assigned PM confirms scope, team and plan. |
| Design | Development | Recorded design approval. |
| Development | Testing | Delivery candidate and test scope recorded. |
| Testing | Development or Deployment | Failed tests return to Development; passed release criteria permit Deployment. |
| Deployment | Testing or Completed | Failed deployment/rollback returns to Testing; successful deployment and recorded customer acceptance permit completion. |
| Any active phase | On Hold or Cancelled | PM; reason and customer communication recorded. |
| On Hold | Saved previous active phase or Cancelled | PM; resume conditions met. No arbitrary phase jump. |

Completed and Cancelled are terminal. Post-completion support and scope additions use follow-up/change records, not silent reopening. If customer acceptance must be bypassed for a contractual exception, that policy must be approved separately; B0 provides no administrator override.

Every transition follows authenticate → authorize → lock/revalidate version and guard → mutate → append state history and audit → record async intent → commit. Transition history includes actor, source/target state, reason, timestamp, entity version and correlation ID. Generic PATCH status fields are rejected.

## 6. Private Document Architecture

**Access path:** Authorization → owning-module action → Documents → private S3-compatible storage. No public disk, predictable filename URL, or direct client-selected object key.

1. Authorize the parent and reserve a document record/quota. Initial product limit: one optional intake document per revision, 10 MiB, PDF or DOCX. Additional formats require review.
2. Stream the upload through the application to a generated quarantine key. Enforce limits at Nginx, PHP and application boundaries; use bounded private temporary storage. Validate detected MIME, signature and structure together; extension/client Content-Type alone are insufficient. [OWASP file-upload guidance](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html).
3. Persist byte count, checksum, detected type, original display filename, uploader, storage key/version and upload completion. Finalization is idempotent. Original filenames never become paths or trusted response headers.
4. Queue malware scanning and constrained parsing. Reject executable/active content, encrypted uninspectable files and decompression bombs. PDF scripts/embedded files and DOCX macros/external relationships are disallowed. Run parsers without general network access, with CPU/memory/page/expanded-byte limits. Scanner failure leaves the object quarantined; production release requires a working scanner, not just a stub.
5. Mark Available only for the exact verified immutable object version/checksum after all required checks. Extraction output is private derived data with the same authorization/retention. Never replace scanned bytes at the same accessible key.

Document states: Uploading → Quarantined → Available / Rejected; technical errors remain Quarantined with retry metadata. Authorized deletion is Available/Rejected → Deleting → Deleted after storage confirmation; metadata tombstones preserve historical attachment traceability.

**Downloads:** Default to application-streamed attachment downloads with current session, parent Policy, visibility and Available checks. A download grant expires after 60 seconds, is tied to the authenticated actor and document version, and is reauthorized when consumed. Set no-store, nosniff and sanitized Content-Disposition. Do not serve active document previews on the application origin.

Direct storage presigned links are deferred until traffic justifies them: possession grants access until expiry, so they cannot provide per-request customer isolation or immediate application-level revocation. If introduced, use a distinct download origin, minimal TTL, exact object version and no URL logging. Record access-grant issuance separately from actual storage access. [S3 presigned URL behavior](https://docs.aws.amazon.com/AmazonS3/latest/userguide/using-presigned-url.html).

S3 and PostgreSQL cannot share a transaction. Reconcile abandoned Uploading records and unreferenced objects after a 24-hour grace period, rechecking leases/references before deletion. Retry Deleting records; never delete a shared historical blob while any retained attachment references it. Private buckets, least-privilege identities, encryption, version-aware backup and lifecycle rules must be verified against the chosen provider.

## 7. AI / Queue Architecture

**Provider boundary:** Application → AI Service → Provider Contract → Adapter. Start with one provider; no speculative multi-provider router. All document analysis and AI generation runs asynchronously against identified source revisions.

- Capture purpose, authorized source revision/checksum, consent basis, prompt/schema version and chosen model in ai_runs. Preserve generated suggestions separately. Applying a suggestion requires human confirmation and an unchanged source version; otherwise return a conflict for review.
- Treat document instructions and model output as untrusted data. No AI database credentials, business mutations, browsing, arbitrary URL retrieval or privileged tool access. Validate output schema, length, supported category IDs and requirement structure; render generated text safely. Prompt filtering alone is not a security boundary. [OWASP prompt-injection guidance](https://cheatsheetseries.owasp.org/cheatsheets/LLM_Prompt_Injection_Prevention_Cheat_Sheet.html).
- Send only necessary content; omit customer contact fields unless essential. Do not place prompts, responses or documents in operational logs. Provider retention, training use, region and deletion terms must be accepted before enabling uploads to AI. Explicit per-document AI authorization is the initial default.
- Set connection/overall timeouts, bounded retries with jitter and Retry-After support. Retry network errors, 429 and selected 5xx; fail invalid input/schema output without unlimited regeneration. Open a provider circuit breaker during sustained failure. Surface Pending/Unavailable/Failed to users while manual workflows continue.
- Enforce per-user operation/document limits, maximum input/output size, approved models, concurrent-run caps and a daily monetary cap. Reserve estimated maximum cost atomically in PostgreSQL before calls, settle measured usage afterward, and retain reservations for ambiguous provider outcomes. No automatic paid failover. Customer/provider spending limits must be approved before enablement.

### Durable execution

**Decision:** A small PostgreSQL async_operations table acts as durable work intent/outbox; Redis transports operation IDs. Store the intent with the originating business transaction. After-commit dispatch accelerates delivery, and a scheduler relay independently finds eligible work. Laravel after-commit avoids processing uncommitted data but is not a durable substitute for this ledger. [Laravel jobs and transactions](https://laravel.com/framework/docs/13.x/queues#jobs-and-database-transactions).

Publishing to Redis does not mean completion. A worker atomically claims a PostgreSQL lease, checks prerequisites, executes, and records a unique result. A bounded reconciler re-enqueues stale unpublished/enqueued/running work whose lease expired, including after Redis data loss. Fencing tokens prevent a stale worker from committing over a newer attempt. Business result writes and success status share a transaction. Durable business deduplication keys survive API idempotency-key expiry.

| Queue | Initial job timeout / retry_after | Attempts and backoff |
|---|---|---|
| default | 30s / 90s | 5 attempts; 5s, 30s, 120s, 300s + jitter |
| documents | 120s / 180s | 3 attempts; 30s, 120s + jitter; chunk longer work |
| ai | 90s / 150s | 3 attempts; 30s, 120s + jitter; max retry age 15 minutes |
| notifications | 30s / 90s | 6 attempts; 30s, 120s, 600s, 1800s, 3600s + jitter; max age 24 hours |

Use separately configured queue connections where retry_after differs; it is a connection setting. Provider HTTP timeout must be shorter than the job timeout; visibility/lease expiry and process shutdown grace must exceed it with margin. Dedicated document/AI workers cannot starve notifications. Payloads contain identifiers, not private content. [Laravel queue timeouts](https://laravel.com/framework/docs/13.x/queues#job-expirations-and-timeouts).

Assume at-least-once execution. Use unique operation/result keys, compare-and-set leases and provider idempotency keys when supported. A crash after an external success can leave an uncertain result; reconcile provider operation IDs where possible. Never claim exactly-once email delivery or exactly-once AI billing when the provider cannot deduplicate; avoid blind retries of ambiguous paid calls.

Persist failed_jobs and terminal operation failure details in PostgreSQL with sanitized codes. Alert by queue/purpose, expose restricted operator inspection, and replay only through an audited action preserving logical idempotency. Scheduled tasks are themselves idempotent; singleton scheduler locks reduce overlap but database constraints protect correctness.

## 8. API Conventions

| Concern | Decision |
|---|---|
| Resources | `/api/v1/project-requests`, nested revisions/documents/discovery/proposals, `/projects`, `/categories`, `/ai-runs`. Auth endpoints use the same origin; Sanctum's CSRF endpoint is an explicit framework exception to versioning. |
| Commands | Explicit POST subresources such as submissions, transitions, proposal acceptances and project conversion. No general-purpose status or ownership update. |
| Input/output | Form Request validation, explicit allowed fields and API Resources. Reject unknown mutation fields. Success envelope: data, optional meta/links; expose only customer-appropriate fields. |
| Errors | Stable error.code, safe error.message, optional field-keyed error.fields and request_id. Uniform JSON normalization for framework exceptions, including CSRF, authentication and throttling. |
| HTTP | 200 read/update; 201 created + Location; 202 accepted async work + status URL; 204 completed deletion/logout; 400 malformed input; 401 unauthenticated; 403 prohibited visible action/invalid CSRF; 404 absent or inaccessible; 409 invalid transition/idempotency conflict; 412 stale If-Match; 413 too large; 415 unsupported media; 422 validation; 428 missing required precondition; 429 throttled + Retry-After; 503 unavailable required dependency. |
| Concurrency | ETag/If-Match required for mutable edits and decisions. Submission, proposal acceptance, conversion and AI-run creation additionally require Idempotency-Key. |
| Idempotency | Scope key by actor + route/operation; store canonical body/precondition hash, resource/result and expiry. Same key/different request = 409; matching completed request replays its safe result after current authorization. Atomic unique claims resolve in-flight races; reserve key before side effects. Initial API replay retention 72 hours; business uniqueness remains permanent. |
| Collections | Allowlisted filter/sort/include values, stable ID tie-breaker, default 25 / maximum 100 records. Cursor pagination by default; bounded offset pages only for small administrative datasets. No unrestricted eager-load includes or automatic expensive total counts. |
| Search | PostgreSQL B-tree exact reference lookup; GIN-indexed full-text name/description search using the simple configuration initially. Confirm Arabic/English normalization and matching behavior with product fixtures; no promise of stemming. Bound query length and minimum search length, and scope by authorization before searching. No unbounded leading-wildcard scans. |
| Correlation | Generate/validate a bounded request ID at ingress, return X-Request-ID, propagate to audit, jobs and provider calls. Accept trace context only from trusted ingress. IDs never contain PII. |

Never expose stack traces, SQL, internal paths, secrets, provider response bodies or storage keys. Production debug is disabled. Reject unsupported content types, excessive nesting/body sizes, and client-provided customer_id, role, state, approval or storage fields.

## 9. Docker Development + Production Topology

| Service | Responsibility |
|---|---|
| nginx | Single local/public entry; routing, bounded requests and edge security headers. |
| app | Laravel PHP-FPM API only. |
| postgres | Durable database on a named persistent volume. |
| redis | Queue/cache/throttling; no authoritative business records. |
| queue | Same application image; separate worker processes/pools for the four queues. |
| scheduler | Same image; scheduler and durable-operation reconciliation, independently supervised. |
| nextjs | Frontend runtime/dev service behind nginx, or an explicitly configured local frontend upstream. |
| storage / scanner | Local S3-compatible emulator and malware scanner development profiles; production equivalents are required for uploads. |

The required six backend services remain separately operated. A frontend runtime and storage/scanning integrations are necessary to exercise the full design; do not pretend the six-service list alone implements them. Local database debugging ports, if enabled, bind loopback only.

~~~mermaid
flowchart LR
    Browser --> Edge[HTTPS ingress / Nginx]
    Edge --> Next[Next.js instances]
    Edge --> Apps[Laravel app instances]
    Apps --> PG[(Private PostgreSQL)]
    Apps --> Redis[(Private Redis)]
    Apps --> S3[(Private object storage)]
    Scheduler[Scheduler / operation relay] --> PG
    Scheduler --> Redis
    Redis --> Workers[Queue worker pools]
    Workers --> PG
    Workers --> S3
    Workers --> Scanner[Restricted scanner / parser]
    Workers --> Providers[AI / email providers]
~~~

Production may start with Compose on one host if its availability risk is accepted. Use managed PostgreSQL/object storage and independent backups where practical. Multiple app/worker instances share the database, Redis and storage; no sticky sessions or local authoritative files. Separate queue Redis from eviction-oriented cache Redis when eviction policy/workload isolation requires it; logical databases alone do not isolate memory pressure.

Expose only ingress; PostgreSQL, Redis, PHP-FPM, scheduler and management endpoints stay private. Use non-root containers, minimum capabilities, read-only filesystem where practical, resource limits, secret injection and encrypted connections. App/worker runtime credentials cannot perform schema DDL. Migration credentials are available only to the release job.

Build one immutable application image per release, use it for app/worker/scheduler, and run migrations once through a release job. Use expand/contract schema changes compatible with old/new instances, then drain/restart workers gracefully. Roll back code only while schema-compatible; destructive migration rollback is not a recovery plan. Readiness checks require PostgreSQL/session health; an AI outage must not remove core API instances from service. Report Redis/storage degradation separately.

**Proposed recovery targets, pending budget:** PostgreSQL RPO <= 15 minutes and service RTO <= 4 hours. Use encrypted backups plus WAL/PITR, separate backup credentials and quarterly restore drills. Retained document versions must recover consistently with database references; define object recovery guarantees with the provider and verify them in the same drill. Redis is rebuildable from PostgreSQL work intent. No production readiness claim until a restore has actually passed.

## 10. Security Threat Model

Trust boundaries are browser→API, staff→customer data, application→database/storage, untrusted documents→parser, and application→external provider.

| Threat | Required controls / negative evidence |
|---|---|
| IDOR / BOLA | Ownership/assignment-scoped queries, parent-child checks and Policies on every path. Customer A cannot list, read, mutate, download, export, accept, convert or poll Customer B's resources. |
| Privilege escalation | Server-defined role grants, no self-promotion or Super Admin bypass, staff MFA, recent authentication, permission-change audit and session revocation. Test stale permissions and last-admin protection. |
| Mass assignment | Explicit mutation input objects; reject ownership, role, status and internal fields. Test nested/repeated forbidden fields. |
| CSRF | Session CSRF middleware, trusted origins and correct SameSite cookies. Test login/logout and every mutation, not only JSON CRUD. |
| XSS / leakage | Treat customer/AI text as data; safe Next.js rendering/CSP, no raw HTML injection, private no-store responses, sanitized headers and attachment delivery. Authenticated Next.js fetches/pages must not enter shared response caches. |
| Brute force / enumeration | Combined normalized-account and IP limits, generic reset/login responses, escalating delays and monitored failures. Initial login limit 5/minute per account plus 30/minute per IP; reset/resend 3/hour per account, tuned with abuse evidence. |
| Malicious uploads / traversal | Generated keys, layered type checks, quarantine, patched sandboxed parsers, scan fail-closed, limits and no client-selected paths. Test polyglots, archive bombs and filenames containing traversal/control characters. |
| Injection | Parameterized database queries, allowlisted sort/filter names, no untrusted raw SQL or shell invocation; parser arguments never built as shell strings. |
| SSRF | No fetching customer-supplied URLs. Fixed allowlisted storage/provider endpoints; block loopback/private/metadata destinations and unapproved redirects for any future fetcher, including DNS rebinding scenarios. |
| AI/document abuse | Authorized source selection, no tools/business authority, input/output/cost quotas and atomic spending reservations. Test malicious document instructions and cross-customer source IDs. |
| Races / duplicates | PostgreSQL locks, version checks, unique constraints, durable operation keys and fenced worker commits. Race acceptance/expiry, withdrawal/conversion, upload/delete and duplicate jobs. |
| Sensitive-data leakage | Minimum-field serializers, no prompts/documents/tokens in logs or queue payloads, private storage/backups, scoped exports and redacted error envelopes. Verify telemetry and notification templates. |
| Dependency / operational compromise | Locked dependencies/images, audits, minimum runtime privileges, separated migration credentials, secret rotation and restore exercises. |

Redis failure must not silently disable abuse controls. Continue existing authenticated core workflows only while edge limits and database-backed authorization remain effective; fail closed on login/reset/upload/AI creation if their required limiter is unavailable. Optional AI and notification failures leave durable pending work and clear user-visible state. Storage failure affects document operations; it does not prevent submitting text without a document.

## 11. Observability / Performance Strategy

**Audit:** Separate append-only audit_events from operational logs. Record authentication outcomes, assignment/state changes, document access grants/download outcomes, proposal actions, conversion, role/settings changes, exports and retention actions. Include actor/service identity, action, subject reference, outcome, timestamp, reason, correlation ID and bounded redacted changes. Store document contents and commercial text in their protected domain records, not audit diffs. Hash or truncate identifiers/IPs used for unauthenticated-abuse analysis according to retention policy.

The application role may insert/read authorized audit data but cannot update/delete it; the migration role is separate. State-changing business transactions fail if their audit insertion fails. A sensitive download fails closed if access authorization cannot be durably audited. Forward audit batches to an independently controlled append-only archive when the retention/storage policy is approved. PostgreSQL permissions protect against application tampering; they do not make a database administrator unable to alter history.

**Operational telemetry:** Structured JSON logs to stdout with request/job/trace IDs and bounded metadata; centralized collection, restricted access and separate retention. Measure HTTP p50/p95/p99, throughput/5xx, PostgreSQL slow queries/locks/connections/disk, queue depth/oldest age/failures, durable-operation backlog, Redis latency/memory/evictions, worker and scheduler heartbeats, storage latency/errors/capacity, scan age, AI provider latency/errors/tokens/cost and backup/restore status. Avoid resource IDs/PII as high-cardinality metric labels.

Initial alert proposals: core API 5xx > 1% for 5 minutes; worker/scheduler heartbeat absent for 2 minutes; oldest notification > 5 minutes; durable dispatch backlog > 2 minutes; document quarantine > 15 minutes; AI cost at 80% budget and hard-stop at 100%. Backup failure, storage exposure and suspicious permission changes alert immediately. Tune thresholds against an agreed workload; no claimed production SLO without measurements.

**Query/performance policy:**

- Index all referencing FKs used for joins/deletes, plus requests(customer_id, created_at, id), requests(status, created_at, id), active assignment(staff_id, request_id), projects(source_request_id unique), project_members(user_id, project_id), proposals(request_id, revision_number), transitions(parent_id, created_at, id), and async_operations(state, next_attempt_at). Use partial indexes for eligible work/current issuance when appropriate; do not duplicate indexes already supplied by unique constraints.
- Bound list/query sizes and selected columns; explicitly eager-load necessary relationships. Prevent lazy loading in development/test and exercise query-count budgets for lists. Use query plans and representative fixtures before adding indexes or caches.
- Start dashboard counts/aggregations in PostgreSQL using bounded date ranges and indexed queries. Cache public categories and short-lived permission-scoped summaries selectively; never trust cache for authorization, state or spending limits. Invalidate after commit and tolerate cache loss.
- Keep source text/documents out of list responses. Make extraction, AI, notifications and large exports asynchronous. Add aggregate tables/materialized views only for measured repeated bottlenecks, with visible freshness and reconciliation rules.
- Proposed load-test target: core JSON p95 < 300 ms / p99 < 1 second, excluding uploads/external work. Define traffic, data volume and infrastructure before accepting it as an SLO. Profile connection budgets before introducing pooling or read replicas.

## 12. Testing + CI Gate

| Layer | Mandatory coverage |
|---|---|
| Unit | State guards, money precision/overflow including LYD, normalized values, permission decisions and AI output validation. |
| Feature / API | End-to-end intake→acceptance→conversion→completion, error/status contracts, CSRF, verification/reset, filtering/pagination and immutable original content. |
| Authorization | A/B customer denial for every resource/action/list/search/export/document/AI route; staff assignment/role matrix; internal-note visibility; permission revocation and forged ownership. |
| Integration | Real PostgreSQL and Redis, S3-compatible test service, scanner contract, provider/email fakes and optional sandbox contract checks without production data. |
| Database constraints | Bypass application validation and prove FK/unique/CHECK/history protections reject invalid ownership links, duplicate conversions and malformed money/state. |
| Concurrency | Independent connections/processes against PostgreSQL: double conversion, accept-vs-expire, withdraw-vs-convert, concurrent amendments, quota reservations and stale worker writes. |
| Queue / idempotency | Duplicate delivery, rollback without effects, death after commit/before publish, Redis loss, expired leases, provider success with missing acknowledgement, audited replay and backoff. |
| File security | MIME/extension mismatch, malicious PDF/DOCX, oversized/expanded content, quarantine access, expired/cross-user grant, storage failure and orphan cleanup races. |
| Architecture | Module import/write boundaries, thin controllers, no direct AI/storage SDK use outside adapters, no hidden status mutation/side-effect observers. |
| Regression | Every corrected production defect gains a focused test; baseline workflow, authorization and API contract suite runs on every change. |

Use PostgreSQL in CI rather than SQLite for relational/concurrency evidence. Laravel test transaction wrappers must not conceal after-commit behavior; dedicated integration cases commit and use real independent workers/connections. Test fakes alone do not prove delivery reliability or object-store semantics.

**Every merge/release gate must pass:**

1. Composer manifest/lock validation and reproducible install; Laravel Pint check mode.
2. PHPStan/Larastan **level 10** for first-party application code: greenfield code can normalize mixed input at boundaries rather than accumulate a baseline. Typed framework stubs are acceptable; broad ignore rules or unexplained baselines are not. Verify Laravel/package compatibility during B1. [PHPStan rule levels](https://phpstan.org/user-guide/rule-levels).
3. PHPUnit unit, feature, negative authorization, integration, database, concurrency, queue and security suites; architecture tests and API contract checks. Gate critical workflow/Policy branch coverage, not only a misleading global percentage.
4. Dependency/security audit, secret scan and container scan. Unresolved known high/critical runtime findings block release; failed/skipped scanners do not count as success. Remediate or replace affected components.
5. Migrations on an empty PostgreSQL database and from the previous release schema/data fixture; validate constraints, indexes, lock duration, rollback where genuinely reversible and old/new application compatibility. Destructive changes require a staged forward plan.
6. Docker/Compose configuration validation, production image build, non-root/health/startup checks and API/worker/scheduler smoke test. Test the exact release image; use fake provider credentials.

Protected branch/deployment rules prevent bypass. A release also requires staging acceptance, backup/recovery evidence, migration review and documented rollback/runbooks. B0 runs none of these implementation tests: there is no application yet.

## 13. ADRs for Major Architectural Decisions

### ADR-01 — Modular monolith

- **Decision:** One Laravel deployable codebase with owned modules and explicit application workflows.
- **Reason:** Cross-domain transactions and one small operational surface best serve correctness and maintainability.
- **Alternatives rejected:** Microservices, CQRS/event sourcing, generic domain frameworks.
- **Consequences:** Enforce boundaries in tests; scale app and worker processes independently without promising independent module deployment.

### ADR-02 — PostgreSQL authority and durable work

- **Decision:** Relational business state and durable operation intent live in PostgreSQL; Redis is transport/cache/throttling.
- **Reason:** Committed actions must survive process failure, publish failure and Redis loss.
- **Alternatives rejected:** Redis-only work state, fire-and-forget events, after-commit dispatch as the sole delivery guarantee.
- **Consequences:** A small lease/reconciliation mechanism is required; handlers and external calls still need idempotency.

### ADR-03 — Immutable lineage and distinct conversion

- **Decision:** Preserve submitted revisions and issued proposal terms; create one separate Project from accepted terms.
- **Reason:** Customer intent and commercial decisions must remain attributable after edits and delivery.
- **Alternatives rejected:** Reusing the request row as the project, mutable originals, silent AI replacement.
- **Consequences:** Additional revision/decision tables and explicit amendment/change workflows.

### ADR-04 — Identifiers and exact money

- **Decision:** UUIDv7 IDs, unique non-gapless human references, integer minor-unit money with currency-specific precision.
- **Reason:** Stable identity, concurrency-safe references and exact USD/LYD values.
- **Alternatives rejected:** MAX+1 numbering, float money, two decimals for all currencies, JSONB budgets.
- **Consequences:** API amounts are strings, LYD uses three decimal places, and reference sequences need no annual reset.

### ADR-05 — Same-origin session authentication

- **Decision:** Sanctum sessions, CSRF, PostgreSQL session storage, RBAC plus Policies and staff MFA.
- **Reason:** First-party browser access and ownership-sensitive data fit server-controlled sessions.
- **Alternatives rejected:** Browser localStorage tokens, UI-only authorization, universal Super Admin bypass, unrelated-domain cookie assumptions.
- **Consequences:** Frontend/ingress/authentication configuration must be tested together; external/mobile API tokens are a later decision.

### ADR-06 — Quarantined documents and mediated downloads

- **Decision:** Private immutable objects, scan-before-access, typed parent links and application-authorized streaming.
- **Reason:** Untrusted uploads and revocable customer authorization require a controlled boundary.
- **Alternatives rejected:** Public storage, trusting extensions, immediate access before scanning, default bearer download links.
- **Consequences:** Scanner/parser operations and orphan reconciliation are required; streaming consumes application bandwidth.

### ADR-07 — Optional, constrained AI

- **Decision:** One provider adapter behind a contract, queued revision-based suggestions, human application and PostgreSQL spending reservations.
- **Reason:** AI must improve content without owning business decisions or jeopardizing core availability/privacy.
- **Alternatives rejected:** Synchronous provider dependency, autonomous mutations, automatic paid failover, unlimited retry/regeneration.
- **Consequences:** AI enablement depends on privacy/budget approval and honest handling of ambiguous provider results.

### ADR-08 — Transactional state and commercial acceptance

- **Decision:** Explicit finite transitions, version checks/locks, separate staff approval and customer acceptance, unique conversion.
- **Reason:** Concurrent commercial actions need one deterministic, attributable outcome.
- **Alternatives rejected:** Arbitrary status PATCH, implicit acceptance, staff accepting for customers, rejected requests converting.
- **Consequences:** Clients handle conflicts; term changes require new approval and a preserved new proposal revision.

### ADR-09 — Separate audit and operational logs

- **Decision:** Append-only business/security audit with restricted privileges, plus independently retained operational telemetry.
- **Reason:** Accountability requires stable domain evidence without leaking private content into logs.
- **Alternatives rejected:** Log files as the business audit trail, editable audit rows, raw request/document logging.
- **Consequences:** Sensitive actions depend on durable audit writes; independent archive and erasure policy need operational ownership.

### ADR-10 — Incremental operations and analytics

- **Decision:** Compose-based development, stateless application processes, private infrastructure, PostgreSQL analytics and measured optimizations.
- **Reason:** The initial product has no demonstrated need for specialized orchestration/search infrastructure.
- **Alternatives rejected:** Kubernetes, Elasticsearch, precomputed analytics platforms and speculative replicas.
- **Consequences:** A one-host launch has explicit availability limits; production recovery, load testing and future capacity remain measurable gates.

## 14. Risks / Unresolved Decisions

These are proposed defaults, not approved product policy. Resolve at the indicated gate without starting implementation in B0.

| Decision / risk | Proposed default | Owner / gate |
|---|---|---|
| Public intake friction | Verified customer account before submission; no anonymous or staff-on-behalf submission. | Product — before B2/B3. |
| Customer tenancy | Individual customer account; no organization membership/delegated buyer model. | Product — before B2 schema. |
| Commercial authority | PM approval by a different author, Sales issuance, explicit owner acceptance; one request→one project. Confirm staffing permits separation and define acceptance evidence/terms. | Product/commercial — before B5. |
| Completion acceptance | Customer confirmation required; no implicit acceptance or privileged override. | Product/commercial — before B6. |
| Workflow expectations | Mandatory recorded discovery; clarified decline/expiry/rescission behavior; no arbitrary reopening. | Product/operations — before B3/B5. |
| Currency/budget policy | USD/LYD exact minor units, zero allowed, unknown has no amount/currency; no FX/tax/payment scope. Confirm business maximum amounts. | Product — before B3/B5. |
| Upload scope | One PDF/DOCX up to 10 MiB per intake revision; scanner/parser required. | Product/security — before B4. |
| Privacy/retention | AI opt-in; no indefinite-retention promise. Choose region, retention periods, erasure process, audit/archive access and backup expiry. | Product/security/data owner — before production data or AI enablement. |
| AI supplier and spending | Disabled until provider terms, models, unit costs and daily/user limits are approved; no automatic failover. | Product/security — before B7 enablement. |
| Hosting/recovery | Same-origin deployment, private data services, proposed RPO 15m / RTO 4h; single-host risk explicitly accepted if used. | Operations/business — before B8 launch. |
| Languages / scale | Confirm Arabic/English taxonomy/search, timezone, initial users/request volume, upload volume and peak concurrency. | Product — before B3 search and B8 load acceptance. |
| Version compatibility | Proposed Laravel/PHP/PostgreSQL baseline; validate Sanctum, Larastan, scanner and S3 provider compatibility and pin releases. | Engineering — B1/B4. |

Residual risks needing explicit acknowledgement: database operators remain able to alter database-only audit history; external providers may duplicate email or bill uncertain AI attempts; no application can stop a customer redistributing a file they legitimately downloaded. Independent archives, provider reconciliation and access controls reduce these risks without pretending to eliminate them.

## 15. Proposed Implementation Batches B1 Onward

Every batch includes its own relevant tests and CI gates; security and verification are not deferred to the final batch. Dependencies follow the order below. B1 starts only after explicit B0 approval.

| Batch | Deliverable | Exit gate |
|---|---|---|
| B1 — Foundation | Initialize repository/Laravel only after approval; module structure, pinned runtime, development topology, CI, safe configuration/errors, health endpoints, audit and durable-operation primitives. | Reproducible build; PostgreSQL/Redis connectivity; strict analysis; committed-operation recovery and audit protections demonstrated. |
| B2 — Identity and customer isolation | Sanctum/CSRF, verification/reset, staff MFA, sessions, roles/Policies, customer profile; minimum email adapter/templates for verification/reset. | Full negative customer/staff authorization matrix and session-revocation tests pass; sandbox delivery demonstrated before external onboarding. |
| B3 — Taxonomy and intake | Categories, validated drafts, immutable submissions/amendments, exact budgets, references, assignments, request states and idempotent submission. | Original content preserved; category/money constraints and transition/concurrency tests pass. |
| B4 — Private documents | S3 integration, typed attachments, quarantine/scanner/parser, authorized downloads, quotas and cleanup. | Malicious uploads, cross-customer access, scanner outage and cleanup races pass with real integration components. |
| B5 — Discovery and proposals | Discovery revisions, requirement review, priced proposal versions, internal approval/issuance, customer acceptance/decline/expiry. | Immutable terms; authorization and accept/expire/supersede race tests pass. |
| B6 — Projects | Atomic conversion, accepted baseline, membership, milestones and delivery lifecycle. | Parallel conversion creates exactly one Project; source history remains intact; completion guards pass. |
| B7 — AI and notifications | AI adapter, constrained suggestions, consent/cost controls, business notification templates, preferences and operator replay; extend B2's email delivery. | Provider outage/ambiguous result/duplicate job/privacy tests pass; enable AI only with approved terms/limits. |
| B8 — Operations and launch readiness | Authorized administration/reporting, monitoring/alerts, performance evaluation, migration/release runbooks, security regression and restore drills. | All CI gates green, production topology and recovery targets validated, unresolved launch decisions signed off. |

**B0 validation performed:** Read the supplied brief; inspected the workspace and ancestor guidance; checked Git applicability; verified relevant Laravel/PostgreSQL/PHPStan/storage/security documentation and USD/LYD currency precision. Only this architecture document was added. No application, migration, container configuration, dependencies or implementation tests were created or run.

B0 ARCHITECTURE COMPLETE — AWAITING REVIEW / IMPLEMENTATION NOT STARTED
