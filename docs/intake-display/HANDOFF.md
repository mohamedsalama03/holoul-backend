# Dashboard F4 — Project Requests handoff

Implementation checkpoint: `1f3cf6eb6a2b532cc882769f59ee80096b49c926` on `codex/intake-display-candidate`.
Parent: `3bc85401e96a1f8b843d1db0f13a4ae9b86572ee` (PublicPortfolio + Contact handoff).

Contract: **1.5.0-intake-display-candidate**, OpenAPI 3.1.1, 202 operations, 260 schemas.
SHA-256: `e90a431d1c662e7fb2f2eb680694678d20c294ef3a53e17dec92fcaeba87c50f`.
Authoritative file: `docs/openapi.json`. Verification and activation status are recorded separately in `VERIFICATION.md`.

## Per-item response

| Item | Reply | Implementation / decision |
|---|---|---|
| R1 | **change** | `customer_id` on the opt-in staff summary/detail is the canonical customer-directory ID, null for an unclaimed guest. Use it with `adminCustomerDetail` when the viewer has that operation's existing capability. `submitted_by` remains an account ID; the directory endpoint does not accept ambiguous alternate IDs. |
| R2 | **change** | Opt-in summaries/details include `assigned_staff: {id, display_name}` or null. Assignment history includes `previous_staff`, `assigned_staff`, and `assigned_by_staff`; state history includes `actor`. Names remain available on closed requests and for disabled historical accounts. No staff-directory grant or arbitrary name lookup endpoint was added. |
| R3 | **change** | Use `actor.kind`: `customer`, `staff`, `guest`, or `system`. The explicit fourth kind avoids calling an anonymous guest a staff member or an automated process. Guest submissions keep their original guest name/kind after claim and customer amendments. For system entries, ID and display name are null. |
| R4 | **change** | Opt-in summaries/details include `project_name`, `customer_display_name`, `provenance` (`guest` or `customer`) and `claimed`. Project name is the latest submitted revision, never an unsubmitted amendment. The customer label is the current account name when owned and the submitted guest name otherwise. `claimed` is true only for a claimed guest-origin request; authenticated-origin requests have provenance `customer` and claimed false. |
| N1 | **use 1.5.0-intake-display-candidate** | The memo's runtime observation predates the completed PublicPortfolio + Contact 1.4 batch. The immediate backend baseline for this change is 1.4 (202 operations, hash below). Adopt 1.5 in a separate dashboard contract commit after reviewing this handoff; frontend adoption and acceptance belong to the frontend team. No frontend contract copy was changed by this batch. |
| N2 | **use the current synthetic closed-history policy** | Retaining each run's rejected/withdrawn synthetic request locally is acceptable. Preserve immutable revisions and history. The two unsubmitted drafts may remain; this batch neither deletes them nor adds a delete-draft API. Do not select records for cleanup by a reference-number range: unrelated records can share that range. Existing reset remains restricted to its exact synthetic manifest and does not erase business history. |
| N3 | **use the recovered local stack** | No factory reset, volume deletion, database initialization or schema migration is required for this batch. Keep the documented socket-recovery incident as environment evidence. Ensure Ubuntu is available before starting containers that depend on its bind mounts; recovery steps that stop WSL are not routine test setup. |

## Opt in without breaking F4

Add `?view=dashboard` only to these existing GET operations:

| Operation | Path | Display response schema |
|---|---|---|
| intakeStaffList | `/api/v1/admin/project-requests` | `IntakeDisplayPageResponse` |
| intakeStaffDetail | `/api/v1/admin/project-requests/{projectRequest}` | `IntakeDisplayDetailResponse` |
| intakeStaffReference | `/api/v1/admin/project-requests/by-reference/{reference}` | `IntakeDisplayDetailResponse` |
| intakeStaffHistory | `/api/v1/admin/project-requests/{projectRequest}/history` | `IntakeDisplayHistoryResponse` |
| intakeStaffAssignments | `/api/v1/admin/project-requests/{projectRequest}/assignments` | `IntakeDisplayAssignmentsResponse` |

Omit the parameter to receive the unchanged legacy response. Customer reads, revision reads, eligible-assignee reads and command responses retain their existing schemas. Unsupported views, a view on a command/customer read, and arbitrary identity lookup fields are rejected. The option does not initialize a new session or reissue cookies on an ordinary authenticated read.

After a successful command, refresh the enriched detail for display fields. Keep the command response's existing ETag/version handling. A 412 still requires a refresh and an explicit user decision, not automatic retry. Display names are current labels and may change independently of the request's command ETag. They are not authorization evidence or name-at-action snapshots.

Only an authorized staff request scope can supply IDs to the internal name reader. The existing MFA, role permissions, assignment visibility, draft exclusion and request locks still apply. For example, a Project Manager loses visibility after assigning to another staff member unless independently permitted to read it. This batch does not change that behavior. An unclaimed guest request still permits only assign/review/reject from the existing staff commands; the new flags explain the state but never authorize a command.

## Compatibility and deployment scope

Previous SHA-256: `7739bd957665b285c3baea8a4883aa987d6d11dee3fb43bcc5ef1ff9fb0c8051` (1.4.0-public-services-candidate).
New SHA-256: `e90a431d1c662e7fb2f2eb680694678d20c294ef3a53e17dec92fcaeba87c50f`.

- No new operations; five GET operations gain one optional parameter and an alternate success schema. The other 197 operations are exactly unchanged.
- All 250 pre-existing schemas, parameters, response components and security schemes remain unchanged. Ten strict display schemas and one parameter are added.
- Default responses remain suitable for the dashboard's pinned 1.2 contract. An enriched response intentionally requires the new display schema; do not validate it as the old strict `IntakeStaffSummary`.
- No schema migration or data backfill. No role/permission migration. No historical row updates or retention changes.
- Read-only local application candidate; no production deployment, frontend implementation, public-user cutover or production-performance certification.

See `CHANGELOG.md`, `response-examples.json`, `VERIFICATION.md` and `evidence/contract-compatibility.json` for the contract delta and evidence.
