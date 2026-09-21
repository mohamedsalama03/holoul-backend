# B7 AI Assistance and Notifications

B7 extends the approved B0 modular monolith. B8 Reporting, Analytics and production-launch work are not implemented. Baseline: `bf738302657f8da1bca911058186a24e87b7ea37`.

## Provider and enablement decision

The configured initial AI provider is the deterministic, local `sandbox` adapter with model `sandbox-v1`. It performs no network calls, incurs no paid usage, and provides predictable contract outputs for integration and acceptance testing. These outputs are not represented as the quality of a commercial language model. `HOLOUL_AI_ENABLED=false` is the default. The full verification gate explicitly enables this local adapter for synthetic fixtures.

B0's external-provider approval remains outstanding. No real customer data or paid request is sent externally. Adding a real adapter requires approval of its retention, training/data-use terms, region, deletion behavior, model allowlist and actual unit costs. There is no automatic paid failover. Core intake, commercial and project workflows remain independent of AI availability.

Application `AIApi`/`AISources` → AI `AIRuns` and durable `GenerateAI` → `AIProvider` contract → configured adapter. Controllers delegate HTTP handling. The AI module owns only AI writes and depends on Documents/Audit; source authorization and owning-module application are composed in `app/Application/AI`. Provider names are bounded metadata rather than domain-specific provider types.

## Sources, consent and application

Every creation command requires explicit `consent: true`, the parent ETag and an idempotency key. The source is selected on the server. The run retains actor/customer/parent, purpose, exact draft or submitted revision/document identity, aggregate version, SHA-256 source fingerprint, document checksum/object version where present, consent version/time, prompt/schema versions, configured provider/model, state and safe usage/cost metadata. Source snapshots and validated suggestions are separate immutable records; human decisions are retained.

- Customers may improve/categorize their own open drafts. Creating or completing a run never edits the draft.
- Customers can analyze a current available draft attachment or customer-visible Project document. Staff use currently authorized submitted request content or Project documents. Staff cannot read customer-only drafts through AI.
- Staff requirement extraction can be explicitly appended to an editable Discovery revision through `ManageDiscovery`. Existing requirements remain present and new suggestions enter as `proposed`; no confirmation, signoff, pricing or approval is generated. Completed Discovery revisions reject application.
- Applying description/category suggestions calls `ManageDraft`. Submitted revisions, uploaded bytes, accepted proposals and Project baselines are never overwritten.
- Any parent version/source fingerprint change causes stale application rejection. This deliberately treats unrelated changes to the same aggregate conservatively. Regeneration is a new consented run/new idempotency key against the current source, subject to all quotas.
- History metadata is requester-scoped and also requires current parent access. Full document-derived output additionally requires current document permission and a readable attachment, including creation replay. History does not require an unchanged source version; application requires a current, permitted mutable target. Permission checks also precede command-receipt replay.

The worker rechecks current identity/permissions, parent scope and exact source before extraction, before provider dispatch and before storing output. It rechecks after extraction I/O. It never holds a business transaction open during storage/parser/provider calls. Staff assignments/team membership and customer ownership remain governed by the existing modules.

## Private extraction and input/output boundary

`DocumentTextService` captures an Available B4 source and checks document ID, parent/customer, document version, immutable storage version, SHA-256, size and format. `openVerified` supplies the exact private bytes. Uploading, quarantined, rejected, replaced or unavailable sources cannot reach extraction/provider processing. No storage URL or credentials enter AI input or API output.

The existing offline inspector now supports an explicit bounded extraction operation. PDF extraction uses pinned Poppler `pdftotext` after the existing QPDF structural checks. DOCX extraction uses the main document body only, excluding package metadata/comments/headers. Existing ZIP, expanded-size, structural, active-content, encryption, process/time/memory limits still apply. Empty/unsupported/oversized output fails closed; there is no OCR. The inspector remains without network access.

Only the selected description or bounded document body and bounded public taxonomy choices enter the adapter. Names, email, phone, budgets, internal notes, session/MFA/password fields and unrelated project records are not selected. A minimizer redacts recognizable email/phone/credential patterns inside free text. Pattern redaction cannot prove arbitrary prose contains no sensitive information; external processing remains disabled pending the separate data-policy approval.

Inputs and outputs have no executable authority. There are no SQL, browser, storage, authorization or business-mutation tools. Output validation rejects unknown keys, malformed JSON, excessive depth/length/counts and unsupported values. Categories must form a currently active valid pair. API output is JSON text/data; consuming interfaces must render it as untrusted text, not executable HTML. Operational logs/audit/queue payloads exclude prompts, document text, full responses and credentials.

## Cost and reliability

PostgreSQL serializes admission and owns daily reservations, settled costs, per-user daily counts and concurrent-run limits. The sandbox reserves a configured synthetic ceiling and settles at zero actual cost. Configured defaults: 20,000 input characters, 32,000 output bytes, eight active platform runs, two per actor, 20 requests per actor/day, 10,000,000 micro-USD/day and 1,000 micro-USD reserved per run. These are conservative sandbox control values, not approved paid-model prices. Model allowlists and disablement are enforced again before dispatch.

States: `pending`, `processing`, `succeeded`, `failed`, `unavailable`, `cancelled`. Rejected budget/limit requests retain safe visible state and audit evidence without queuing paid work. Provider attempt intent is written before the call. Duplicate deliveries and an expired worker fence cannot trigger another call after an unacknowledged attempt. Ambiguous outcomes stop automatic retries and keep the reservation uncertain; they do not claim zero cost or exactly-once execution.

Only known retryable failures are retried, with bounded exponential backoff/jitter and bounded Retry-After. Source/storage/parser outages have bounded retries. Malformed responses fail safely. Cancellation before dispatch releases capacity; cancellation during a possible external call preserves uncertain spending until a known result can settle it. The minute reconciler finalizes exhausted or abandoned work without blindly calling the provider again.

The dedicated `ai` Redis transport contains only operation IDs: worker timeout 120 seconds, PostgreSQL lease 150 seconds, Redis retry interval 180 seconds, shutdown grace 135 seconds. Provider connect/overall limits are 5/60 seconds; extraction is constrained separately. Ordinary notifications remain on the shorter default pool; document scans retain their own pool. PostgreSQL durable intent/reconciliation remains authoritative if Redis publication fails.

## Notifications

Explicit owner events for request submission/information requests, proposal issuance/acceptance/decline and Project creation/customer updates/state changes/customer-visible milestone transitions are composed by `BusinessNotifications`. A synchronous subscriber stores notification, delivery and durable operation intent in the same business transaction. External email delivery occurs after commit. Historical passive B3 intents are retained; B7 does not backfill unsolicited messages from old events.

Templates contain a fixed safe title and a generic sign-in instruction. They omit private document/commercial/internal content. Customers receive their own workflow updates; relevant assigned staff receive submission/decision notifications. Internal milestones do not generate customer notices. AI completion/failure creates a requester notification without including the AI result.

Current identity reads use PostgreSQL `FOR NO KEY UPDATE` so recipient foreign-key checks do not form a lock cycle with concurrent parent reads. Account/role revocation still serializes on the identity row, and HTTP session locks remain exclusive. Real-process tests cover both notification progress and revocation serialization.

In-app records are recipient-scoped, paginated and support detail, unread count, individual read and mark-all-read. Inbox versions serialize new arrivals and read-all commands. A `workflow_email` preference applies to business/AI email; all personas can manage their own preference. B2 verification/recovery delivery remains unchanged and cannot be disabled through this preference. There is no marketing/SMS/WhatsApp system.

`EmailProvider` abstracts SMTP. Delivery states include pending, processing, accepted, failed, uncertain and explicitly suppressed. Attempts retain only safe outcome/provider references/timestamps. A proven pre-send rejection can retry; an acknowledgement lost after sending starts is uncertain and cannot automatically resend. A crash after acceptance but before the fenced database outcome is also uncertain. Email is not claimed to be exactly once.

Operators require explicit `notifications.delivery.read`/`notifications.delivery.replay` (initially Super Admin), current session authority, recent password confirmation, ETag, idempotency key and an audited reason. Replaying an uncertain attempt additionally requires explicit `confirmed_not_accepted` resolution. Replay retains logical notification identity and creates a new delivery generation; it never repeats the originating business mutation. A false operator assessment can still duplicate external email, so the audit records that human decision.

## API and permissions

All 16 new route definitions are under `/api/v1`, retaining session/origin/CSRF, safe error/request IDs, no-store responses, UUIDv7 and rate limiting. Total route definitions: 155 (GET/HEAD count as one).

AI uses an atomic 120-request/minute aggregate bucket and a separate 20-create/minute bucket per identity. Polling consumes only the aggregate bucket; PostgreSQL spending and daily-run limits remain independent and authoritative.

| Method | Route | Purpose |
|---|---|---|
| POST / GET | `/ai-runs` | Create a consented run / list own runs for a required parent |
| GET | `/ai-runs/{aiRun}` | Read authorized run and separate suggestion |
| POST | `/ai-runs/{aiRun}/applications` | Explicitly apply; optional `discovery_revision_id` for staff requirements |
| POST | `/ai-runs/{aiRun}/dismissals` | Dismiss a pending suggestion |
| POST | `/ai-runs/{aiRun}/cancellations` | Cancel eligible work |
| GET | `/notifications` | Recipient's page |
| GET | `/notifications/unread-count` | Recipient's unread count |
| GET | `/notifications/{notification}` | Recipient's detail |
| POST | `/notifications/{notification}/read` | Mark own record read |
| POST | `/notifications/read-all` | Mark own inbox read with version precondition |
| GET / PATCH | `/notifications/preferences` | Read/update own transactional email preference |
| GET | `/admin/notification-deliveries` | Authorized operator delivery page |
| GET | `/admin/notification-deliveries/{delivery}` | Safe delivery/attempt detail |
| POST | `/admin/notification-deliveries/{delivery}/replays` | Authorized audited operator replay |

AI creation body: `parent_type` (`request`/`project`), `parent_id`, `purpose`, optional `document_id`, `consent: true`. Purposes: `improve_description`, `suggest_category`, `analyze_document`, `extract_requirements`, `missing_information`. Mutations use `Idempotency-Key` and `If-Match`: parent version for creation, run version for decisions. A successful apply only changes the owning mutable content plus the AI decision record in one transaction.

Eight additive permissions bring the catalog to 50: `ai.self.use`, `ai.self.apply`, `ai.use`, `ai.apply`, `notifications.self.read`, `notifications.self.manage`, `notifications.delivery.read`, `notifications.delivery.replay`. AI staff grants do not bypass underlying intake/project/document/Discovery permissions. AI reads are currently limited to the requester, even when another staff member can read the parent.

## Schema and verification

Three additive migrations create five AI tables, seven notification tables and eight permissions. The prior 24 migrations are unchanged. Composite foreign keys bind AI sources to the correct customer/parent; exact-source checks and immutable-history triggers protect runs, suggestions, decisions, attempts and receipts. Runtime identity cannot truncate/delete protected history or change append-only receipts. Migration identity remains separate.

`docs/B7-VERIFICATION.md` records the actual gate results and corrections. Production adoption, a real AI supplier, external SMTP delivery acceptance, retention/erasure policy, paid budgets and B8 operational readiness remain separate approvals.
