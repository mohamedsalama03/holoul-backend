# B8 reporting and audit investigation

The B8 reporting surface adds five GET routes under `/api/v1`. It does not create business tables, caches, materialized aggregates, external analytics infrastructure or new business workflows.

| Route | Explicit permission | Initial staff grants |
|---|---|---|
| `/admin/reports/dashboard` | `reporting.read` | Super Admin, Administrator, Project Manager |
| `/admin/reports/requests` | `reporting.read` | Same |
| `/admin/reports/projects` | `reporting.read` | Same |
| `/admin/reports/customers` | `reporting.read` | Same |
| `/admin/audit-events` | `audit.investigate` | Super Admin |

All routes retain the existing same-origin session, current enabled identity, staff MFA and verified-email requirements. Explicit staff kind is checked even if an erroneous customer role grant exists. Permission revocation is rechecked on every page. The response is `private, no-store`; rate limits allow at most 60 reporting/investigation calls per actor per minute. PostgreSQL statement time is bounded at five seconds for these optional reads.

The API opens a bounded-retry PostgreSQL `REPEATABLE READ` transaction before its first identity query. This gives all aggregate queries one database snapshot, while inherited identity row locks serialize security mutations. A simultaneous submission cannot make category/trend totals disagree with the earlier headline count. State counts still represent a current snapshot, not reconstruction of an earlier business state.

## Windows and metric definitions

`from` and `to` use `YYYY-MM-DD`, both inclusive in UTC. When omitted, the window is the current UTC date and preceding 29 days. Supplied dates must be paired, ordered, end no later than today and cover at most 366 calendar days. SQL uses an inclusive lower timestamp and exclusive midnight after `to`. A date range selects a cohort; state counts describe that cohort's current state, not reconstructed states at the historical window end.

| Metric | Cohort / denominator / timing |
|---|---|
| Total Requests | Requests first submitted within the selected window. Private unsubmitted drafts are excluded. Amendments do not add another request. |
| New Requests | Selected request cohort currently in `submitted`. |
| Under Review / Information Required | Selected request cohort currently in the corresponding state. |
| Request trends | First submissions by UTC date, at most 366 daily rows. Omitted dates have zero events. |
| Request status distribution | Current state counts for the selected submission cohort. |
| Requests by category/subcategory | Latest immutable submitted revision's taxonomy IDs. Optional category/subcategory filters apply only to `/reports/requests`; a subcategory requires its category. Top 100 groups by count, with deterministic ID tie-breakers and `other_requests` for the remainder. An unknown or mismatched ID pair yields an empty cohort. |
| Estimated budgets | Latest immutable submitted revision per request in the cohort. Unknown budgets have their own request count. Known totals are grouped independently by USD/LYD. |
| Request → Project conversion rate | Currently converted requests divided by all first-submitted requests in the window, including rejected/withdrawn requests in the denominator. One immutable conversion per request prevents duplicate counting. Empty denominator returns `null`, otherwise percentage is a decimal string. |
| Average review time | Elapsed seconds from first entry to `under_review` through first entry to `discovery`, `rejected` or `withdrawn`. Includes information-waiting periods. Only completed review intervals in the request cohort contribute; `reviewed_requests` is the denominator. Never-reviewed and still-open intervals are excluded; no samples gives `null`. |
| Pending Proposals | Proposal versions issued within the window that remain `issued` and unexpired at query time. This is pending customer decision, not unissued internal drafts. |
| Accepted proposal values | Currently accepted proposal versions issued within the window, grouped by currency. Not customer estimated budgets and not recognized revenue. Superseded, declined, withdrawn or rescinded terms do not inflate accepted value. |
| Average time to proposal | First request submission → first proposal issuance for requests in the submission cohort that have at least one issued proposal. Each request contributes once, irrespective of proposal revisions. `requests_with_issued_proposal` is the denominator; no samples gives `null`. |
| Project pipeline | Projects created within the window grouped by current state. Active means Planning, Design, Development, Testing or Deployment; On Hold is reported separately. |
| Project completion rate | Completed projects divided by all projects created within the window; cancelled and held projects remain in the denominator. Empty denominator returns `null`. |
| Completion events/trend/average duration | Completion transitions occurring within the selected window, including projects created earlier. Duration is Project creation → explicit guarded completion, including held time. `completed_in_period` is the denominator; no samples gives `null`. |
| Total Customers / customer growth | Customer profiles registered within the window and their registration counts by UTC date. No identity/contact fields are returned. |

Every money aggregate uses PostgreSQL `sum(bigint)` followed by a decimal **string** representation of integer `minor_units`. This avoids both floating-point conversion and overflow when the sum exceeds a single signed BIGINT. USD has exponent 2; LYD exponent 3. There is no combined-currency total, FX model, paid revenue measure or inferred payment receipt.

## Ownership and SQL review

`OperationalReports` depends only on `IntakeReportingReader`, `ProposalReportingReader`, `ProjectReportingReader` and `CustomerReportingReader` contracts. Implementations live in the owner modules; service-provider bindings live outside Reporting. Selected columns are counts, states, category IDs, monetary values and timestamps only. The explicitly reviewed Proposals→`project_requests` read-only join selects the first submission timestamp to calculate first-issuance elapsed time. It does not read customer PII or source text and does not grant a write path.

The dashboard has a fixed ceiling of 15 aggregate SELECTs, independent of row count; no per-record traversal occurs. Bounds are 366 daily rows, 100 category/subcategory groups, finite state/currency distributions, and 100 audit events per page. Query plans and any justified indexes are evaluated in the separate B8 profiling evidence. This implementation adds no speculative index or cache.

## Audit investigation

Allowed filters: exact `event_type`, `actor_id`, `subject_type`, `subject_id`, `request_id`, and the same bounded UTC window. `limit` defaults to 25 and is capped at 100. Unsupported parameters, malformed UUIDs, invalid event codes and invalid cursors fail validation with safe errors. No SQL, arbitrary column selection, sorting expressions or raw search language is accepted.

Results contain only event ID, nullable actor ID, event type, subject type/ID, request/correlation ID and the microsecond UTC event timestamp. No user/contact join, audit metadata, business free text, private documents, prompts, provider responses or credentials are returned. `request_id` is the persisted correlation identifier.

Cursor pagination orders by `(occurred_at DESC, id DESC)` to preserve tied microsecond timestamps. The opaque authenticated cursor contains the last tuple and a fingerprint of all filters and date bounds. Tampering or reuse with a different scope is rejected. Newer events do not move the seek position backward. Each authorized investigation is itself appended as `audit.investigation_viewed`, with only the safe number of returned rows; original records remain immutable under inherited database privileges/triggers.

## Verification

The focused PostgreSQL/Redis preflight passed **24 tests, 370 assertions**, covering the seven staff roles, customer forged grants, current permission revocation, absent/unverified identities, mixed currencies, private draft exclusion, real conversion/completion/hold workflows, metric denominators, bounded query counts, sanitized filters, exact audit cursor ties and append-only preservation. An independent PostgreSQL connection commits a new submission between aggregate queries; every section retains the original transaction snapshot. Scoped Pint and PHPStan/Larastan level 10 passed. Final inherited-gate and performance results belong to the final B8 verification report; this preflight alone is not production certification.

A subsequent exact B7 upgrade and report unsafe-filter preflight passed **11 tests, 919 assertions**, including explicit rejection of year `0000` before PostgreSQL timestamp parsing. Its artifacts are `b8-upgrade-report-bounds-preflight.log` and `.xml`.

The final complete quality rerun passed all **25 reporting/audit tests with 383 assertions** (Reporting HTTP 23/345; audit investigation 2/38). The exact B7-to-B8 upgrade passed **one test with 789 assertions**, within seven passing sequential upgrade tests totaling 2,244 assertions. Fresh per-class JUnit and aggregate totals are preserved under `artifacts/b8-quality/` and checked against `artifacts/quality.log`. Runtime load, image security and recovery acceptance remain recorded separately in the final B8 verification report.
