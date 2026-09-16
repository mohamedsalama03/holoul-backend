# B3 — Taxonomy & Project Intake

This batch implements Categories and Project Intake on the approved B0–B2 foundations. Documents/uploads, AI, Discovery domain records, Proposals, Projects, notification delivery expansion and Reporting remain unimplemented. Discovery is a state boundary only.

## API and authorization boundary

All routes below use `/api/v1` and the same exact HTTPS origin, encrypted Sanctum session cookie, strict CSRF, request IDs and safe error envelope established in B2. Customer and staff intake routes reject the other persona. Staff must complete persisted MFA; a password-only pending session has no intake access. Active taxonomy reads are available to authenticated customers and staff.

The outer application workflow locks and revalidates the current enabled user, authorization generation and persisted session, resolves current permissions, then invokes domain actions within that transaction. It holds identity authority until commit and allows at most two transaction attempts, including one retry after a deadlock. Assignee eligibility is also locked and rechecked. Intake never imports Identity models or writes Identity/Customers/Categories tables.

[The permission matrix](B3-AUTHORIZATION.md) documents the eight new permission codes. Super Admin has the explicit `intake.read_all` permission; all ownership and workflow invariants still apply. Project Manager, Reviewer and Business Analyst reads are limited to their assigned requests. The assignment permission also exposes the unassigned, already-submitted intake queue. Project Managers can assign or reassign their own and unassigned requests; Super Admin can manage other assignments. Administrator controls taxonomy but has no private intake access. Sales and Support receive no B3 private-content access by default. Staff cannot view initial drafts or unsubmitted amendments, including through search. Staff detail, revision, information, assignment-history and state-history reads emit audit events; list responses contain only summaries.

Customer ownership is always derived from the authenticated identity/customer contract. Direct UUID, exact reference, nested customer route, list/search, revision and information paths apply ownership/assignment predicates before retrieval. Foreign or malformed IDs return 404. A visible operation without its permission returns 403. No generic status PATCH exists.

## Endpoint catalog

B3 adds **38 route definitions**, bringing the application to **68 total**. GET routes also accept HEAD; each GET/HEAD pair counts as one route definition. Each mutation rejects unknown fields, including ownership, status, reference, role, contact snapshot, minor-unit and version fields.

| Method | Path | Server operation |
|---|---|---|
| GET | /categories | taxonomy.categories |
| GET | /categories/{category}/subcategories | taxonomy.subcategories |
| GET | /admin/categories | taxonomy.admin_categories |
| GET | /admin/categories/{category} | taxonomy.category |
| GET | /admin/subcategories/{subcategory} | taxonomy.subcategory |
| GET | /admin/categories/{category}/subcategories | taxonomy.admin_subcategories |
| POST | /admin/categories | taxonomy.create_category |
| POST | /admin/categories/{category}/subcategories | taxonomy.create_subcategory |
| PATCH | /admin/categories/{category} | taxonomy.update_category |
| PATCH | /admin/subcategories/{subcategory} | taxonomy.update_subcategory |
| GET | /project-requests | customer.list |
| POST | /project-requests | customer.create |
| GET | /project-requests/by-reference/{reference} | customer.reference |
| GET | /project-requests/{projectRequest} | customer.detail |
| GET | /customers/{customer}/project-requests/{projectRequest} | customer.nested |
| PATCH | /project-requests/{projectRequest}/draft | customer.update |
| POST | /project-requests/{projectRequest}/amendments | customer.amend |
| POST | /project-requests/{projectRequest}/submissions | customer.submit |
| GET | /project-requests/{projectRequest}/revisions | customer.revisions |
| GET | /project-requests/{projectRequest}/revisions/{revision} | customer.revision |
| GET | /project-requests/{projectRequest}/information-requests | customer.information |
| POST | /project-requests/{projectRequest}/information-requests/{information}/responses | customer.response |
| POST | /project-requests/{projectRequest}/withdrawals | customer.withdraw |
| GET | /project-requests/{projectRequest}/history | customer.history |
| GET | /admin/project-requests | staff.list |
| GET | /admin/project-requests/by-reference/{reference} | staff.reference |
| GET | /admin/project-requests/{projectRequest} | staff.detail |
| GET | /admin/project-requests/{projectRequest}/revisions | staff.revisions |
| GET | /admin/project-requests/{projectRequest}/revisions/{revision} | staff.revision |
| GET | /admin/project-requests/{projectRequest}/information-requests | staff.information |
| GET | /admin/project-requests/{projectRequest}/history | staff.history |
| POST | /admin/project-requests/{projectRequest}/assignments | staff.assign |
| GET | /admin/project-requests/{projectRequest}/assignments | staff.assignments |
| POST | /admin/project-requests/{projectRequest}/reviews | staff.review |
| POST | /admin/project-requests/{projectRequest}/information-requests | staff.ask |
| POST | /admin/project-requests/{projectRequest}/information-requests/{information}/acknowledgements | staff.acknowledge |
| POST | /admin/project-requests/{projectRequest}/discovery-handoffs | staff.discovery |
| POST | /admin/project-requests/{projectRequest}/rejections | staff.reject |

## Drafts, exact money and immutable submissions

Create a possibly incomplete draft with `POST /project-requests`. Editable fields are `category_id`, `subcategory_id`, `project_name`, `project_description`, `budget_unknown`, `estimated_budget`, `currency`. Category/subcategory are supplied together or both omitted/null. Names are bounded to 200 characters and descriptions to 20,000; text is normalized to Unicode NFC. Contact and ownership are never accepted from intake input.

Draft PATCH updates only supplied fields. There is exactly one draft row per request. Initial drafts may be incomplete; a complete submission requires the selected active taxonomy pair, name, description, budget choice and verified account email. After submission the draft closes. Opening an amendment reuses the current draft content, records its base revision number and advances the request version. Permitted amendment phases are Submitted, Under Review and Information Required. Amendment submission creates a new immutable revision without automatically acknowledging a clarification or changing the review phase. Discovery handoff, rejection and withdrawal close any open amendment. B3 exposes no amendment or reopening action in those states.

Budget amounts are API decimal strings and stored as non-negative signed-BIGINT minor units. Currency is a foreign key to the read-only USD/LYD catalog:

| Currency | Exponent | Example | Stored units |
|---|---:|---|---:|
| USD | 2 | "12.34" | 1234 |
| LYD | 3 | "12.345" | 12345 |

Numeric JSON amounts, scientific notation, negative values, unsupported currencies, overflow and excess precision are rejected. There is no float conversion, silent rounding or FX. Shorter precision is exactly padded in responses. Zero is valid. `budget_unknown=true` requires null amount and currency; a known draft may temporarily omit both, but a known submission requires both. Changing an existing known budget to unknown explicitly clears amount and currency in the same PATCH.

Submission snapshots the current customer full name, normalized account email and E.164 phone, plus selected category/subcategory IDs and labels, project text, exact budget, actor, provenance and UTC time. User/contact rows and the selected taxonomy are locked through snapshot creation. Taxonomy edits/deactivation and contact changes cannot rewrite old revisions. A valid phone format is not proof of phone control. Revision 1 always remains the original; all submitted revisions are guarded against UPDATE, DELETE and TRUNCATE.

The first successful submission allocates `REQ-<UTC year>-<sequence>` using a PostgreSQL sequence with a minimum five-digit suffix. The sequence never resets annually; gaps and longer suffixes are valid. The reference and first submission timestamp are immutable. No MAX+1 allocation is used.

Submission also appends a passive `intake_notification_intents` row with request/revision identifiers and event kind in the same transaction. The revision/kind pair is unique. This preserves durable notification intent for B7 without dispatching an unimplemented handler or implying delivery. No new provider, recipient preference, template or notification endpoint is introduced.

## Versions and submission idempotency

Mutable request responses include `version` and an ETag such as `"<request-uuid>:3"`. Send that exact quoted value in `If-Match` for draft updates, amendment creation, submission, assignment, transitions, information requests/responses/acknowledgements and withdrawal. Missing precondition returns 428; a stale or nonmatching precondition returns 412 after scoped access is checked. Successful changes increment the request version once. Taxonomy mutations use the same ETag format and expose `lock_version`. PATCH `display_order` reorders taxonomy; stable UUID tie-breaking handles equal order values.

Submission additionally requires a 16–128 character `Idempotency-Key` consisting of ASCII letters, digits, underscore, dot, colon or hyphen. A missing or malformed key returns 422. An empty JSON submission body is required. PostgreSQL claims the key by actor and operation, storing a SHA-256 key hash, canonical request/body/precondition hash and safe relational result with a 72-hour replay validity period. The claim precedes effects; rollback removes it. Matching completed requests replay the original 201 result and ETag only after current identity and ownership authorization; replay can describe the original submission result even if the current workflow has advanced. A key reused with a different request or precondition returns 409. An expired claim is removed when that actor next attempts to reuse the key; B3 does not introduce a bulk retention job. Ordinary version, state and revision constraints still prevent duplicate effects after expiry.

A request row lock serializes competing draft changes, submissions, assignment and transitions. Staff detail and child reads also hold a shared lock on the scoped request until their outer transaction commits. Reassignment therefore cannot invalidate the access decision while revisions, information, assignment history or state history are read and audited. List summaries use the scoped query's database snapshot. Composite foreign keys connect the latest revision ID and number to the same request, and tie an idempotency actor to its customer request. Direct database tests cover these relationships separately from input validation.

## Workflow and clarification

| From | Command | To | Required authority |
|---|---|---|---|
| Draft | Submit | Submitted | Verified customer owner and complete draft |
| Submitted | Start review | Under Review | Assigned Reviewer or Project Manager, or explicit Super Admin grant |
| Under Review | Request information | Information Required | Assigned Reviewer, Business Analyst or Project Manager, or explicit Super Admin grant |
| Information Required | Customer response | Information Required | Owner; one immutable response per question |
| Information Required | Acknowledge response | Stored Under Review phase | Responsible authorized staff; response must exist |
| Under Review | Discovery handoff | Discovery | Assigned Reviewer or Project Manager, or explicit Super Admin grant |
| Under Review / Information Required | Reject | Rejected | Assigned Project Manager or explicit Super Admin grant; required reason |
| Submitted / Under Review / Information Required / Discovery | Withdraw | Withdrawn | Customer owner |

B0 explicitly permits Project Manager rejection while Information Required; that rule is retained. The originating phase is recorded with each question; B3 can originate information requests only from Under Review. Clients never supply the return state. Question, response and resolution are separate immutable relational records with actor and time linkage. Assignment changes do not themselves authorize a transition or move its phase. Reassignment makes the current authorized assignee responsible for acknowledgement.

Rejected and Withdrawn are terminal. Discovery has no B3 staff domain actions beyond the handoff; the customer may still withdraw before conversion. No reopening, conversion, generic status mutation, draft deletion, documents or Discovery records are exposed. Information outstanding at rejection/withdrawal is closed with preserved history. There are no external provider calls inside business transactions.

## Queries, indexes and operational behavior

Request lists use selected summary columns, a default of 25 rows and a maximum of 100, and opaque keyset pagination with stable `created_at` plus UUID ordering. Allowed sorts are `created_at` and `-created_at`; filters are state, exact reference, category and assigned staff, plus bounded text search. Unknown filters, includes and sorts are rejected. There are no automatic totals or unrestricted includes.

Reference lookup uses a unique B-tree index. Customer/time, state/time, assignee/time, submitted time, current-revision category and child-history paths have explicit indexes. Name/description search uses PostgreSQL `simple` full-text GIN indexes and parameterized plain-text queries; bilingual Arabic/English fixtures verify literal matching, without claiming stemming. Staff search uses submitted revisions, never an unsubmitted amendment. List query count remains bounded as page size grows. Revisions, clarification, assignment and state history use bounded continuation values and selected columns. API timestamps are ISO 8601 UTC.

Ingress enforces 10 requests per second per client address with a burst of 40 independently of Redis, returning safe JSON 429, a request ID and `Retry-After`. B2 authentication limiters still fail closed when required Redis is unavailable. Existing authenticated intake workflows use PostgreSQL and the ingress limit; Redis is not authoritative for intake state. The edge rate is an initial operational setting requiring deployment and load tuning with correctly trusted proxy addresses.

Audit contains event/subject/actor/request IDs and bounded safe outcomes, not project descriptions, questions, rejection text or contact snapshots. Private reasons remain in authorized domain history. Every state mutation fails if audit cannot commit. The runtime role cannot mutate history or the currency/permission catalogs. Privileged database operations and future privacy retention remain separately controlled.

## Verification and integration limits

The shared verification gate retains every B1/B2 check and adds B3 tests, an upgrade from the exact eight-migration B2 schema to the 12-migration B3 schema, a production-image HTTPS intake workflow and edge throttling. The historical B1 → B2 upgrade test remains bounded to its original migration paths and catalog counts. [B3 verification evidence](B3-VERIFICATION.md) records actual final results; the source code alone does not imply a pass.

The production smoke uses random, synthetic local sandbox identities and real HTTPS cookies, email verification and staff TOTP. Immutable intake/history fixtures remain retained as terminal records; cleanup disables their accounts, revokes sessions, removes fixture role grants and deactivates their taxonomy. Credentials/tokens are not printed or committed.

No external deployment, real SMTP onboarding, hosted CI, production database TLS or Next.js frontend is provided by B3. B1/B2 launch considerations remain, including backup/restore drills, key rotation, retention, monitoring and trusted ingress configuration. No new Composer package or runtime container is added.
