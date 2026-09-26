# B8-P2 — Discovery, proposals and projects review

Scope: the 49 frontend operations implemented by CommercialController and ProjectController, including proposal attachment commands. File metadata/streaming controllers are covered by the separate file contract. Reviewed against B0 architecture, B5/B6 implementation records, current routes, request validators, application coordinators, module actions, serializers and PostgreSQL constraints. This review does not certify production performance.

## Findings and disposition

| Finding | Effect on frontend | Disposition |
|---|---|---|
| Parent aggregate preconditions differ from child versions | Discovery/proposal commands consume the Intake request ETag; Project commands consume Project ETag. Using proposal or milestone lock_version causes 412. | Frozen explicitly in each operation. No behavior change. |
| Cursor vocabulary differs across modules | These collections use `after`/`limit` and `meta.next_after`, not `cursor`/`per_page`. Revision cursors are integers; Project cursors are UUIDv7. | Preserve existing contract; adapter must follow endpoint parameters. No cosmetic rewrite. |
| Command and resource projections differ | Commands return minimal receipts; read endpoints return richer resources. Project command `state`/`version` always describe the parent, even when `id` is a milestone/member/update. | Separate exact OpenAPI schemas; refresh detail or collection after mutation. |
| PATCH milestone is a full editable-field replacement | name, display_order and customer_visible remain required. Omitted nullable optional fields normalize to empty/null. | Documented; no method rename. |
| Two timestamp text forms | Eloquent date fields use ISO timestamps; direct PostgreSQL projections use strings such as `2026-09-21 12:00:00+00`. | Explicit timestamp schema for direct projections. Normalize in frontend date adapter; no wire-format rewrite. |
| Creation Location is not always a child endpoint | Project membership/milestone/update creation Location points to parent Project; conversion points to created Project. | Exact headers documented; use response id plus known collection routes. |
| Customer proposal includes some technical fields | `discovery_revision_id`, `content_version`, `internally_approved` identify/describe the signed-off baseline. They do not embed private discovery notes or requirements. | Matches approved B5 projection. Keep out of customer presentation unless needed; no unapproved breaking removal. |
| Customer updates include author_id only | Customer cannot resolve it through staff administration endpoints. | Show neutral author label unless a later approved safe display-name projection is added. |
| No global proposal/discovery collection, project search or staff directory picker | Dashboard features requiring those must not invent URLs or send unsupported query fields. | Report as capability gaps; no new business feature. |
| No TODO/FIXME markers found in reviewed modules/coordinators | No deferred inline task identified there. | Read-only inspection; not a formal proof that every code path is reachable. |

No concrete ownership bypass, transaction break, unbounded collection, accidental secret/private-storage exposure, or duplicate same-persona endpoint was identified in this scope. Separate customer and staff paths are intentional: they enforce persona and scope and have different projections. This statement is limited to the inspected code; final runtime evidence belongs in the consolidated B8-P2 verification report.

No product code was changed by this scoped review. It adds specification and integration documentation. Existing B5/B6 checks remain required; they are not replaced by documentation or schema validation.

## Authorization and state invariants

Commercial staff access first scopes Intake through `intake.read`; access normally requires current assignment, with the explicit `intake.read_all` exception. The operation-specific permission is also required. Editing or acting on an existing proposal additionally uses `proposals.read`. Discovery completion requires `discovery.complete`, `discovery.manage` and `discovery.read` through the underlying action chain. Attaching/detaching a proposal document additionally needs `documents.read`.

Customer proposals require the authenticated owner identity and self permission. Only issued/historical proposals are visible; foreign request/proposal pairs return 404. Acceptance, decline and rescission require verified email and recent password authentication (default 15 minutes). Staff never decide for a customer. Generic 403 also covers missing recent authentication; the client must not infer a unique cause from that code.

A proposal author or any contributor cannot approve their own terms, including Super Admin. Editing content or attachment invalidates approval. Issued terms are immutable; replacements use new revisions. Acceptance rechecks current state, current approval and database time. Expired offers are refused even if the expiry scheduler is stopped. Customer rescission is only possible before conversion. The original acceptance is retained.

Project reads require `projects.read` and active membership or `projects.read_all`. Lifecycle and team changes always require active `project_manager` membership; read_all does not bypass that rule. Planning evidence also requires PM membership. Customers require both their customer ID and owner identity. Customer milestones omit internal responsibility and include only customer-visible records. Activity, team and evidence have staff-only routes.

Conversion requires scoped Intake access, `projects.convert`, an accepted immutable proposal and no previous Project. The converter must qualify as PM: enabled staff with `projects.read`, `projects.transition` and `projects.team.manage`. Creation, initial PM membership, request Converted state, receipts and histories commit atomically. Uniqueness and composite foreign keys enforce source/baseline identity. Conversion consumes the request ETag but returns the new Project ETag.

Transactions, module-owned writes, persisted authorization rechecks, durable audit and append-only business history remain intact. No external service call was introduced. No performance tuning, performance rerun, runtime deployment or AI enablement occurred in this scoped review.

## Visible and internal enums

| Resource | Customer-visible values | Staff/internal values and distinction |
|---|---|---|
| Discovery revision | No customer discovery endpoint | `draft`, `in_progress`, `completed` |
| Discovery requirement category | Not exposed | `functional`, `non_functional`, `constraint` |
| Discovery requirement priority | Not exposed | `must`, `should`, `could` |
| Discovery requirement status | Not exposed | `proposed`, `confirmed`, `excluded` |
| Proposal state | `issued`, `accepted`, `declined`, `expired`, `superseded`, `withdrawn`, `rescinded` | Adds `draft`, `internally_approved`; never return these unissued records on customer routes |
| Proposal decision | `accepted`, `declined`, `rescinded` | Same immutable decision values |
| Pricing mode / currency | `fixed`, `items` / `USD`, `LYD` | Same; amounts are exact decimal strings |
| Project state | `planning`, `design`, `development`, `testing`, `deployment`, `on_hold`, `completed`, `cancelled` | Same state values, but internal reasons/evidence/team are absent from customer detail |
| Project previous_phase | `null` or `planning`, `design`, `development`, `testing`, `deployment` | Set only while on_hold; not an independent state |
| Milestone state | `upcoming`, `in_progress`, `completed`, `delayed` on visible milestones | Same states; staff also sees internal milestones, responsibility and visibility |
| Team role | No customer team endpoint | `project_manager`, `business_analyst`, `contributor` |
| Evidence kind | Only a boolean deployment_evidence_recorded in customer detail | `plan_approved`, `design_approved`, `delivery_candidate`, `qa_passed`, `deployment_succeeded` |
| Project activity event | No customer activity endpoint | Safe machine event strings, at most 64 characters; schema does not falsely close an extensible event namespace |

Customer completion confirmation records agreement with the latest deployment evidence. It does not change Project state to completed. The assigned PM must explicitly advance it. New deployment evidence invalidates the old confirmation. Completed/cancelled are terminal; post-conversion change requests and post-completion support are not currently available as business APIs.

## Collection bounds and filtering

| Collection | Cursor / order | Bounds | Filters |
|---|---|---|---|
| Discovery revisions | integer revision_number, ascending | default 25, maximum 100 | None |
| Proposal revisions (customer/staff) | integer revision_number, ascending | default 25, maximum 100 | None; customer issuance visibility enforced server-side |
| Projects (customer/staff) | UUIDv7 id, ascending | default 25, maximum 100 | Exact `state` only |
| Milestones | UUIDv7 cursor locating unique display_order, ascending | default 25, maximum 100 | Customer visibility enforced server-side |
| Updates, activity, team, evidence | UUIDv7 id, ascending | default 25, maximum 100 | None |
| Embedded discovery requirements | position, ascending | Maximum 100 | No independent pagination; collection is write-bounded |
| Embedded proposal items / deliverables | position, ascending | Maximum 100 / 50 | No independent pagination; collections are write-bounded |
| Embedded proposal decisions | decided_at, ascending | Maximum two through immutable lifecycle (one decision and optional rescission) | No independent pagination |
| Accepted baseline items / deliverables | same immutable proposal order | Maximum 100 / 50 | No independent pagination |

Page envelope is `{"data":[...],"meta":{"next_after":null}}`; non-null next_after is the exact next cursor. There is no total count, page number, include, sort or search in these endpoints. Project documents use their separate documented pagination contract. Restart milestone pagination after reordering; concurrent edits can change the cursor's display-order position.

All discovery/proposal responses and Project detail/subcollection responses carry the parent ETag. Top-level Project list responses do not. Private responses are `Cache-Control: private, no-store`.

## Mutation and retry integration

1. Read parent/request detail or relevant collection; retain the quoted ETag unchanged.
2. For proposal and Project commands generate a fresh 16–128 character safe Idempotency-Key. Discovery commands have no receipt.
3. Send the documented JSON object, session cookies, CSRF header, trusted Origin/Referer, If-Match and applicable idempotency key.
4. After timeout, retry proposal/Project command with exactly the same original body, key and If-Match. Do not silently replace the ETag on a retry. Exact retries return the committed receipt within the 72-hour window after fresh authorization.
5. A changed/expired key is 409. A new command with stale ETag is 412; missing precondition is 428. Missing/malformed idempotency key is 422. Forbidden state is 409; forbidden action is 403; hidden/foreign record is 404.
6. Use returned ETag for the next command. Refresh detailed resource/collection when a receipt only contains IDs/state/version. After an uncertain discovery mutation, reread and reconcile before attempting a new command.

## Customer endpoint matrix

| Frontend feature | Endpoint | Method | Persona | Required permissions |
|---|---|---|---|---|
| Customer proposals | `/api/v1/project-requests/{projectRequest}/proposals` | GET | customer | `proposals.self.read` |
| Customer proposal detail | `/api/v1/project-requests/{projectRequest}/proposals/{proposal}` | GET | customer | `proposals.self.read` |
| Customer proposal acceptances | `/api/v1/project-requests/{projectRequest}/proposals/{proposal}/acceptances` | POST | customer | `proposals.self.read`, `proposals.self.accept` |
| Customer proposal declines | `/api/v1/project-requests/{projectRequest}/proposals/{proposal}/declines` | POST | customer | `proposals.self.read`, `proposals.self.decline` |
| Customer proposal rescissions | `/api/v1/project-requests/{projectRequest}/proposals/{proposal}/rescissions` | POST | customer | `proposals.self.read`, `proposals.self.accept` |
| Customer project list | `/api/v1/projects` | GET | customer | `projects.self.read` |
| Customer project detail | `/api/v1/projects/{project}` | GET | customer | `projects.self.read` |
| Customer milestones | `/api/v1/projects/{project}/milestones` | GET | customer | `projects.self.read` |
| Customer project updates | `/api/v1/projects/{project}/updates` | GET | customer | `projects.self.read` |
| Confirm project deployment | `/api/v1/projects/{project}/completion-confirmations` | POST | customer | `projects.self.read`, `projects.self.confirm` |

## Admin endpoint matrix

All staff rows also require current MFA and the scope rules above; permission strings are additive, not alternative roles.

| Frontend feature | Endpoint | Method | Persona | Required permissions |
|---|---|---|---|---|
| List discovery revisions | `/api/v1/admin/project-requests/{projectRequest}/discovery` | GET | staff | `intake.read`, `discovery.read` |
| Create discovery revision | `/api/v1/admin/project-requests/{projectRequest}/discovery` | POST | staff | `intake.read`, `discovery.manage` |
| Read discovery revision | `/api/v1/admin/project-requests/{projectRequest}/discovery/{revision}` | GET | staff | `intake.read`, `discovery.read` |
| Replace discovery content | `/api/v1/admin/project-requests/{projectRequest}/discovery/{revision}` | PUT | staff | `intake.read`, `discovery.manage`, `discovery.read` |
| Replace discovery requirements | `/api/v1/admin/project-requests/{projectRequest}/discovery/{revision}/requirements` | PUT | staff | `intake.read`, `discovery.manage`, `discovery.read` |
| Start discovery | `/api/v1/admin/project-requests/{projectRequest}/discovery/{revision}/starts` | POST | staff | `intake.read`, `discovery.manage`, `discovery.read` |
| Complete and sign off discovery | `/api/v1/admin/project-requests/{projectRequest}/discovery/{revision}/completions` | POST | staff | `intake.read`, `discovery.manage`, `discovery.read`, `discovery.complete` |
| Staff proposals | `/api/v1/admin/project-requests/{projectRequest}/proposals` | GET | staff | `intake.read`, `proposals.read` |
| Create proposal draft | `/api/v1/admin/project-requests/{projectRequest}/proposals` | POST | staff | `intake.read`, `proposals.create` |
| Staff proposal detail | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}` | GET | staff | `intake.read`, `proposals.read` |
| Replace unissued proposal terms | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}` | PUT | staff | `intake.read`, `proposals.read`, `proposals.edit` |
| Approve proposal internally | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/approvals` | POST | staff | `intake.read`, `proposals.read`, `proposals.approve` |
| Issue approved proposal | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/issuances` | POST | staff | `intake.read`, `proposals.read`, `proposals.issue` |
| Supersede issued proposal | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/supersessions` | POST | staff | `intake.read`, `proposals.read`, `proposals.issue` |
| Withdraw issued proposal | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/withdrawals` | POST | staff | `intake.read`, `proposals.read`, `proposals.withdraw` |
| Attach available proposal document | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/documents` | POST | staff | `intake.read`, `proposals.read`, `proposals.edit`, `documents.read` |
| Detach proposal document | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}` | DELETE | staff | `intake.read`, `proposals.read`, `proposals.edit`, `documents.read` |
| Convert approved request to project | `/api/v1/admin/project-requests/{projectRequest}/conversions` | POST | staff | `intake.read`, `projects.convert`, `projects.read`, `projects.transition`, `projects.team.manage` |
| Staff project list | `/api/v1/admin/projects` | GET | staff | `projects.read` |
| Staff project detail | `/api/v1/admin/projects/{project}` | GET | staff | `projects.read` |
| Staff milestones | `/api/v1/admin/projects/{project}/milestones` | GET | staff | `projects.read` |
| Create milestone | `/api/v1/admin/projects/{project}/milestones` | POST | staff | `projects.read`, `projects.milestones.manage` |
| Staff project updates | `/api/v1/admin/projects/{project}/updates` | GET | staff | `projects.read` |
| Publish customer project update | `/api/v1/admin/projects/{project}/updates` | POST | staff | `projects.read`, `projects.updates.publish` |
| Project activity | `/api/v1/admin/projects/{project}/activity` | GET | staff | `projects.read` |
| Project team | `/api/v1/admin/projects/{project}/team-members` | GET | staff | `projects.read` |
| Add project team member | `/api/v1/admin/projects/{project}/team-members` | POST | staff | `projects.read`, `projects.team.manage` |
| Project phase evidence | `/api/v1/admin/projects/{project}/evidence` | GET | staff | `projects.read` |
| Record phase evidence | `/api/v1/admin/projects/{project}/evidence` | POST | staff | `projects.read`, `projects.manage` |
| Advance project phase | `/api/v1/admin/projects/{project}/advances` | POST | staff | `projects.read`, `projects.transition` |
| Hold project | `/api/v1/admin/projects/{project}/holds` | POST | staff | `projects.read`, `projects.transition` |
| Resume project | `/api/v1/admin/projects/{project}/resumptions` | POST | staff | `projects.read`, `projects.transition` |
| Record failed delivery phase | `/api/v1/admin/projects/{project}/failures` | POST | staff | `projects.read`, `projects.transition` |
| Cancel project | `/api/v1/admin/projects/{project}/cancellations` | POST | staff | `projects.read`, `projects.transition` |
| Remove project team member | `/api/v1/admin/projects/{project}/team-members/{member}` | DELETE | staff | `projects.read`, `projects.team.manage` |
| Replace milestone editable fields | `/api/v1/admin/projects/{project}/milestones/{milestone}` | PATCH | staff | `projects.read`, `projects.milestones.manage` |
| Milestone starts | `/api/v1/admin/projects/{project}/milestones/{milestone}/starts` | POST | staff | `projects.read`, `projects.milestones.manage` |
| Milestone delays | `/api/v1/admin/projects/{project}/milestones/{milestone}/delays` | POST | staff | `projects.read`, `projects.milestones.manage` |
| Milestone completions | `/api/v1/admin/projects/{project}/milestones/{milestone}/completions` | POST | staff | `projects.read`, `projects.milestones.manage` |

## Frontend/backend gaps and approved-design evidence

The repository contains B0 architecture and B5/B6 implementation narratives, but no approved visual Admin Dashboard artifact was available in this scope. Consequently this review can establish API capability coverage, not pixel-by-pixel or interaction-by-interaction parity with an unseen approved design.

The following capabilities have no endpoint in the current Commercial/Projects surfaces: global discovery/proposal inboxes across requests, arbitrary proposal/discovery filters, Project name/reference search, selectable Project sort, staff directory/assignee search, customer-safe author names, generated proposal PDFs, customer Project uploads, generic tasks/time tracking/billing, post-conversion scope-change requests, post-completion support and editable published updates. Some are explicitly deferred in B0/B5/B6. Do not implement them in B8-P2; do not show working controls for them without a separate approved API.

Existing supported screens can integrate through the matrix: request-scoped discovery and proposals, Project list/detail, accepted baseline, milestone list, append-only customer updates, team membership and evidence/phase actions. Exact shared errors, authentication, file flow and optional AI are defined by the consolidated integration package.

## Verification ownership

The canonical fragment is `docs/contracts/commercial-projects.json`. It contains 49 operations, 41 paths and 69 domain schemas with bounded response objects and separate customer/staff projections. The consolidated OpenAPI build, route drift check, captured-response schema validation and full PHP/architecture/static/style/audit gates are run by the coordinating B8-P2 workflow. This document does not label an unexecuted gate as passed and does not replace the separate final verification report.

B8 remains NOT PRODUCTION READY. Failed performance acceptance remains pending validation on the unavailable target VPS; no result in this document waives that gate.

