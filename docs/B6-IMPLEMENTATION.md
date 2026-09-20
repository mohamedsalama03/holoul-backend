# B6 Projects and delivery lifecycle

B0–B5 remain authoritative. This batch implements explicit conversion and delivery
only. It adds no dependencies, public sharing, billing, time tracking, generic tasks,
AI, reporting or expanded notifications. No B7 work is included.

## Conversion and accepted baseline

`POST /api/v1/admin/project-requests/{projectRequest}/conversions` accepts an empty
object, the source request's `If-Match`, and an `Idempotency-Key`. The authorized
staff member needs both scoped Intake access and `projects.convert`. Conversion
locks the request first and its accepted proposal second, checks the accepted owner
decision and exact proposal version, creates the Project and initial PM membership,
then records Intake's terminal Converted state, histories, audit and receipt in one
real transaction. The converting staff member must be eligible to act as project
manager. PostgreSQL uniqueness is the final one-project-per-request guard.

The exact accepted proposal ID, accepted decision ID and accepted version are
immutable foreign-key references. Scope, deliverables, currency, exact decimal
prices, timeline and commercial reference are projected from B5's immutable terms.
They are never copied from a mutable draft. The deadline controls when a customer
can accept an offer; a contract accepted before the deadline does not expire merely
because conversion occurs later. Rescission or request withdrawal before conversion
invalidates conversion. Once converted, the source request and accepted contract
cannot be rescinded or rewritten. A later change-request policy is reserved for a
separately approved batch.

References use PostgreSQL `project_reference_sequence` and the database UTC year:
`PRJ-YYYY-00001`. Allocation is concurrent-safe; rollbacks may leave gaps. There is
no MAX+1 allocator or reference reuse.

## Lifecycle and evidence

Every command locks the Project, checks current authorization and the supplied
Project version, then writes its result, bounded activity, audit and replay receipt.
There is no generic status PATCH. Completed and Cancelled are terminal.

| Current state | Explicit command / requirement | Result |
|---|---|---|
| Planning | Advance after assigned PM records scope, team and plan approval | Design |
| Design | Advance after recorded design approval | Development |
| Development | Advance after delivery candidate and test scope evidence | Testing |
| Testing | Advance after passed QA evidence | Deployment |
| Deployment | Advance after successful deployment evidence and the owner's explicit confirmation of that evidence | Completed |
| Active phase | Hold with reason and customer communication | On Hold, saving previous phase |
| On Hold | Resume with reason and resolved conditions | Saved active phase |
| Testing | Failure with reason and customer communication | Development |
| Deployment | Failure with reason and customer communication | Testing |
| Active phase / On Hold | Cancel with reason and customer communication | Cancelled |

Only an assigned active project manager with `projects.transition` may transition,
including Super Admin. There is no administrator completion bypass. Evidence uses
`plan_approved`, `design_approved`, `delivery_candidate`, `qa_passed`, or
`deployment_succeeded` plus a meaningful bounded summary. Evidence records are
append-only. Renewing evidence retains the old record; guards use the latest record
for the current phase. Team changes invalidate an earlier plan approval. Hold,
resume and phase changes invalidate old phase evidence through a new phase epoch.
A customer confirmation refers to the exact latest deployment evidence; replacement
deployment evidence requires a new confirmation. Customer confirmation requires a
verified email and recent password authentication. Confirmation alone does not
complete the Project: the assigned PM must still explicitly advance it.

Hold, cancellation and failure communication is published in the customer update
feed. Internal reasons, evidence, team and audit details stay in staff projections.
Updates retain author, Project, content and publication time and cannot be edited.
No email or new asynchronous notification pipeline is introduced by these actions.
B4 continues to persist scan/delete work intent in PostgreSQL and transport identifiers
through Redis. Business transitions and audit history are synchronous and atomic.

## Authorization

All permissions are explicit. The 13 new permissions bring the catalog to 42.
Default grants are initial policy, not hard-coded role checks in Project actions.

| Permission | Initial staff roles |
|---|---|
| projects.read | Super Admin, PM, BA, Sales, Reviewer |
| projects.read_all | Super Admin |
| projects.convert | Super Admin, PM |
| projects.manage | Super Admin, PM, BA |
| projects.transition / projects.team.manage | Super Admin, PM |
| projects.milestones.manage / projects.updates.publish | Super Admin, PM, BA |
| projects.documents.read | Super Admin, PM, BA, Reviewer |
| projects.documents.upload | Super Admin, PM, BA |

Customer receives `projects.self.read`, `projects.self.confirm` and
`projects.self.documents.read`. Staff additionally need active project membership
unless they have `projects.read_all`; lifecycle and team management always require
assigned PM membership. Assignable internal roles are `project_manager`,
`business_analyst`, and `contributor`. Identity revalidates enabled staff and explicit
capabilities; customer identities cannot become internal members. Removing the last
PM or a member responsible for an unfinished milestone fails closed.

Sessions, current role grants, MFA, exact origin and CSRF are inherited from B2.
Customers are scoped by both customer ID and owner identity. Foreign Project and
child IDs return 404. An authenticated wrong persona or missing permission returns
403. Reads and replay requests reauthorize current access. Private-content bodies
are excluded from audit metadata and errors.

## HTTP contract

All paths below are under `/api/v1`. Both staff `/admin/projects` and customer
`/projects` provide GET list, GET `/{project}`, GET `/{project}/milestones`,
GET `/{project}/updates`, and document reads described below. Staff alone can read
`/{project}/activity`, `/{project}/team-members`, and `/{project}/evidence`.

| Staff suffix under `/admin/projects/{project}` | Method | Input |
|---|---|---|
| /evidence | POST | kind, summary |
| /advances | POST | empty object |
| /holds, /failures, /cancellations | POST | reason, customer_communication |
| /resumptions | POST | reason, conditions |
| /team-members | POST | staff_id, role |
| /team-members/{member} | DELETE | empty object |
| /milestones | POST | name, display_order, customer_visible; optional description, due_date, responsible_member_id |
| /milestones/{milestone} | PATCH | same complete editable fields; no status field |
| /milestones/{milestone}/starts | POST | empty object |
| /milestones/{milestone}/delays | POST | reason |
| /milestones/{milestone}/completions | POST | empty object |
| /updates | POST | content |

Customer completion uses POST `/projects/{project}/completion-confirmations` with
an empty object. All lifecycle/team/milestone/update/confirmation mutations require
Project `If-Match` and `Idempotency-Key`. Keys are 16–128 safe characters, hashed in
storage. Fingerprints bind operation, Project, child, original ETag and canonical
input. Receipts last 72 hours and return the exact minimal committed result after
fresh authorization. Expired or altered key reuse is 409; a new stale mutation is
412; a missing precondition is 428. Persistent uniqueness/history guards remain
after receipt expiry. Keys are never silently reused for a different command.

Conversion, membership/milestone creation and update publication return 201;
other commands return 200. Command `data.id` is the created child or Project ID,
`project_id` identifies the parent and `version` is the Project version. ETags
always refer to the Project, except the conversion request consumes an Intake ETag.
Lists use UUIDv7 `after` cursors and `limit` 1–100 (default 25); Project lists also
accept a lifecycle `state` filter. Milestone display order is an explicit field,
unique per Project. Milestones progress Upcoming → In Progress → Completed; either
Upcoming or In Progress may become Delayed, then restart In Progress. Completed
milestones are immutable. A responsible member must be active on that same Project.
Customer milestones require `customer_visible=true` and hide internal responsibility.

## Private Project documents

Staff use POST `/admin/projects/{project}/documents` with filename, bytes, sha256,
explicit visibility `internal` or `customer`, Project `If-Match` and an idempotency
key. PUT `.../{document}/content` uses bounded `application/octet-stream`, B4's
exact reservation/hash and Project ETag. Only staff upload. GET list, metadata
`.../{document}`, and `.../{document}/download` exist for staff and customer prefixes.
DELETE `.../{document}` cancels a pending upload only; POST
`.../{document}/scan-retries` invokes B4's guarded retry, both with Project ETag.
Project document lists use `page` 1–100000 and `per_page` 1–100 (default 25).

The Project owns pending and retained references. Documents owns all bytes,
versioned private objects, global quota, quarantine, scanner/parser, short-lived
download grants and durable deletion. Finalization promotes a pending reference
to immutable retained history. Reservation increments the Project version;
finalization/scan processing uses B4's own version so exact-byte upload retries
remain safe. Scan retries and pending cancellation increment the Project version.
Visibility cannot be rewritten after reservation. Cross-Project or cross-customer
attachment is forbidden by exact composite foreign keys, not just controller input.

Staff document access requires `projects.documents.read` and Project scope; customer
access requires owner scope, `projects.self.documents.read`, and retained `customer`
visibility. Pending/internal documents are hidden from customer list, metadata and
downloads. B4's Intake/Proposal download grants remain unchanged. Download access is
rechecked after storage I/O against the current identity, Project and visibility;
no permanent public URL exists. Terminal Projects retain authorized archive reads
and reject new uploads, attachments and scan retries.

`documents:expire-project-uploads --limit=20` runs every five minutes. The bounded
cursor-driven sweep removes expired pending reservations after 24 hours and records
B4 durable deletion intent. It never removes retained references. Terminal Projects
can release abandoned pending bytes without changing terminal Project versions.

## Schema and verification

Five additive migrations follow the 19 unchanged approved migrations: Intake
conversion coherence; ten core Project tables; two document reference tables plus
expiry cursor; append-only command receipts; and explicit permission seeds. There
are 13 new tables, one reference sequence and no separate storage subsystem.
Runtime and migration credentials remain distinct. Histories and receipts cannot
be updated, deleted or truncated by the runtime role; PostgreSQL triggers also
protect immutable source/baseline and valid state/history relationships independently
of Laravel. Retained Project history refuses destructive down-migration.

Run the inherited complete gate with `bash scripts/verify.sh`. It includes exact
B5→B6 upgrade preservation, PostgreSQL race barriers, B4/B5 regressions, production
HTTPS conversion-to-completion with real scans, static/style/architecture checks,
Composer audits and source plus image security scans. Actual outcomes and limits
are recorded in `B6-VERIFICATION.md` only after execution.
