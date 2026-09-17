# B5 Discovery and Proposals

## Boundary and lifecycle

B5 extends the approved B4 baseline `5c4e753cdc05736d28cdb8d3b843d8202eb9a175`.
Four additive migrations add 14 module-owned tables and extend Intake's allowed
states. All 15 earlier migrations remain byte-for-byte unchanged. No Composer
package is added or upgraded. There are 26 new API routes (102 total).

The existing explicit `discovery-handoffs` action moves Under Review to Discovery.
Only then may staff create a discovery record/revision. Revisions move Draft →
In Progress → Completed. Completion requires an authorized explicit action, at
least one confirmed requirement, no unresolved proposed requirements, and a
relational sign-off of the exact revision/version. Each requirement has a title,
description, category (`functional`, `non_functional`, `constraint`), priority
(`must`, `should`, `could`), optional notes and status (`proposed`, `confirmed`,
`excluded`). The bounded collection contains at most 100 requirements.

Completed rows, requirements and sign-offs are immutable. A subsequent discovery
revision starts as an explicit new draft; the prior content is preserved. Internal
discovery notes/requirements are staff-only in B5. Customer proposal resources
contain the signed-off baseline identifier but never embed those private notes.

Proposals have an independently numbered revision per request. The latest
completed discovery revision is required for drafting, approval and issuance.
Draft → Internally Approved → Issued → Accepted/Declined/Expired/Superseded/
Withdrawn. Issued terms never change. New terms use a new proposal revision.
A pending newer draft does not silently revoke the current issued offer.
Supersession explicitly closes that offer and returns the request to Discovery;
the replacement is approved and issued through ordinary commands.

The creator and every material editor are recorded as contributors. None can
approve that proposal, including Super Admin and users holding multiple roles.
An edit or attachment change invalidates approval and advances content_version.
The historical approval remains, while issuance requires an approval referencing
the exact current content version. Expiry is checked again at issuance.

Issuance assigns an immutable `PROP-YYYY-00001` reference using the non-cycling
PostgreSQL sequence and UTC allocation year. The sequence does not reset yearly;
gaps and longer suffixes are valid. Revision counters are serialized beneath the
request lock; neither references nor revision allocation use MAX+1.

## Transactions and customer decisions

The HTTP boundary holds the persisted identity/session locks, then the request,
then proposal and related rows. It rereads current roles, verification, ownership
and assignment. Every mutation requires the current request ETag/If-Match. B5
responses return that request ETag; entity `version`/`lock_version` is separate.
Proposal commands require a 16–128 character Idempotency-Key. Receipts are scoped
to actor and key and retain the operation, parent, exact input hash and result
versions. Same-key exact retries replay one committed result after fresh
authorization within B0's 72-hour replay window. Changed payload/parent/operation/
precondition conflicts. Expired keys return 409 and require a new key; immutable
receipt and decision history remains, preserving permanent business uniqueness.

Only the authenticated verified customer owner can accept or decline, with B2's
recent-password check (15 minutes by default). Staff cannot decide for customers.
Acceptance locks and revalidates the current issued offer, database server time,
approval and parent state, appends the immutable decision, changes the request to
Approved, appends history/audit and the receipt, then commits. Decline returns the
request to Discovery. No email, document I/O or notification service is called
inside these core actions; external delivery cannot roll back their commit.

Withdrawal/supersession/expiry use the same request-first lock order. A due offer
cannot be accepted even if the scheduler is stopped. `proposals:expire --limit=100`
runs every minute, processes at most 1,000 rows per explicitly bounded invocation,
uses PostgreSQL `clock_timestamp()` after locking and is safe to repeat. System
expiry history has a NULL actor rather than attributing it to a customer.

The existing customer request-withdrawal endpoint closes an issued offer or
appends a rescission of an accepted offer atomically. Original acceptance stays
immutable. The explicit owner rescission endpoint returns Approved to Discovery
before any conversion exists, as B0 requires. Projects/conversion are not present.

## Exact prices

API amounts and unit prices are decimal strings. USD uses two decimal places;
LYD uses three. B3 `Money` parses to nonnegative signed-BIGINT minor units without
rounding or float arithmetic. Commercial amount is independent of intake budget.

`pricing_mode=fixed` requires no items and uses the supplied exact amount.
`pricing_mode=items` requires 1–100 lines. Each quantity is a JSON integer from
1 through 1,000,000; line total is quantity × unit price, and proposal total is
the sum. The submitted amount must match exactly. Multiplication/addition are
checked against BIGINT overflow before execution. All lines inherit the proposal
currency; line-level currency fields are rejected. PostgreSQL checks line products
with exact numeric intermediates and defers aggregate equality until commit.
There are 1–50 relational deliverables. No tax, payment, FX or fractional quantity.

`valid_until` is an explicit UTC instant formatted `YYYY-MM-DDTHH:MM:SSZ`.
Scope is bounded to 20,000 characters, timeline to 5,000, commercial notes to
10,000, line descriptions and deliverables to 5,000. Unknown fields are rejected.
Optional notes and line descriptions normalize missing/null values to empty text.

## Permissions and scope

| Permission | Initial staff grants |
|---|---|
| discovery.read | Super Admin, PM, BA, Sales, Reviewer |
| discovery.manage / discovery.complete | Super Admin, PM, BA |
| proposals.read / create / edit | Super Admin, PM, BA, Sales |
| proposals.approve | Super Admin, PM |
| proposals.issue / withdraw | Super Admin, PM, Sales |

Customer role grants `proposals.self.read`, `.accept`, `.decline`; Policies add
actual ownership/current issuance/verification/recent authentication. Ordinary
staff must be assigned. Super Admin's existing explicit `intake.read_all` grant
permits audited business access but never bypasses approval separation or states.
Administrator/Support have no default commercial/private-content grants. Sales
gains assigned intake reading and becomes an eligible assignee for commercial
work; no review, assignment or document permission is added for Sales.

## API

All routes use the inherited session, exact origin, CSRF, request ID, safe errors,
UUIDv7, private/no-store, and edge rate controls. Commercial requests also have a
120-per-minute authenticated identity limit. Collection reads use `after` revision
number (exclusive), `limit` 1–100, and `meta.next_after`.

Staff prefix: `/api/v1/admin/project-requests/{projectRequest}`.

| Method | Suffix | Command |
|---|---|---|
| GET / POST | /discovery | List / create revision |
| GET / PUT | /discovery/{revision} | Read / replace draft summary/notes |
| PUT | /discovery/{revision}/requirements | Replace bounded draft requirement set |
| POST | /discovery/{revision}/starts | Start work |
| POST | /discovery/{revision}/completions | Complete/sign off |
| GET / POST | /proposals | List / create draft revision |
| GET / PUT | /proposals/{proposal} | Read / replace unissued terms |
| POST | /proposals/{proposal}/approvals | Separate internal approval |
| POST | /proposals/{proposal}/issuances | Issue and transition request |
| POST | /proposals/{proposal}/supersessions | Close current offer; reason required |
| POST | /proposals/{proposal}/withdrawals | Withdraw current offer; reason required |
| POST | /proposals/{proposal}/documents | Attach cleared historical document_id |
| DELETE | /proposals/{proposal}/documents/{document} | Detach before issuance |
| GET | /proposals/{proposal}/documents/{document} | Authorized metadata |
| GET | /proposals/{proposal}/documents/{document}/download | Private verified bytes |

Customer prefix: `/api/v1/project-requests/{projectRequest}`.

| Method | Suffix | Command |
|---|---|---|
| GET | /proposals | Own issued/historical proposals |
| GET | /proposals/{proposal} | Own issued terms, items and decisions |
| POST | /proposals/{proposal}/acceptances | Accept current offer |
| POST | /proposals/{proposal}/declines | Decline; optional reason |
| POST | /proposals/{proposal}/rescissions | Rescind acceptance; reason required |
| GET | /proposals/{proposal}/documents/{document} | Own issued attachment metadata |
| GET | /proposals/{proposal}/documents/{document}/download | Own issued private bytes |

Create discovery/proposal returns 201 with Location; other commands return 200. No generic
status PATCH exists. Foreign parents and mismatched child IDs return 404; a
visible forbidden action returns 403. Conflicts return 409, stale ETags 412,
missing preconditions 428, invalid input 422 and throttling 429.

## Documents, history and operations

Structured relational proposal terms are the canonical commercial record; PDF
generation and a new staff upload surface are not required by this batch. A
proposal may reference one optional existing, historical B4 intake attachment
from the same request/customer. It must already be Available. A proposal's
attachment becomes immutable with issuance and remains retained with history.
Composite FKs reject foreign owners/parents. No other storage system is introduced.

Staff attachment access requires both proposal scope and explicit existing
documents.read/download permission. Customer proposal routes expose only the
owner's issued proposals, including preserved historical versions. Downloads use
the B4 60-second actor/version grant, exact object checksum/version verification,
bounded private stream, fresh session/assignment/parent reauthorization after I/O,
and separate authorized/started audits before bytes are released. No public or
presigned links exist. The Documents reference contract composes both owner
modules; DB guards also prevent deletion of retained proposal documents.

Audit records IDs and bounded outcomes, never full requirements, prices/items,
private notes or document bytes. Relational proposal events retain bounded
reasons and versions. Completed discovery, approvals, customer decisions and
events are guarded against UPDATE/DELETE/TRUNCATE, including runtime privileges.

Run `bash scripts/verify.sh` for the inherited B1–B4 gate plus B5, production-image
acceptance/document checks and all runtime vulnerability scans. The local smoke
uses guarded synthetic fixtures, retains immutable history and disables accounts,
revokes sessions/grants and closes active offers during cleanup. B5 downgrade
refuses once commercial history exists; use a separately planned verified backup
restore instead of deleting/reinterpreting that history.

B0 deployment/retention/backup decisions and B4's conservative document-inspection
limits remain applicable. No Projects, AI, Reporting, payments, FX, tax engine,
e-signature, public sharing or expanded Notifications are implemented.
