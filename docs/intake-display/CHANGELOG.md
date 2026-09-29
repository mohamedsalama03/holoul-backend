# 1.5.0-intake-display-candidate

Baseline: 1.4.0-public-services-candidate at `3bc85401e96a1f8b843d1db0f13a4ae9b86572ee`.

- Added opt-in `view=dashboard` to five staff intake GET operations. Existing paths, IDs, filters, pagination and command ETags are retained.
- Added canonical customer-directory ID, submitted project title, current customer display name, guest origin and claim status to the display summary/detail.
- Added current staff names to assignments and typed actors to state history. Guest and automated transitions remain distinct after claim or amendment.
- Bulk identity/revision reads avoid per-row queries. Disabled historical identities remain readable only through an authorized request.
- Added seven HTTP scenarios and a real PostgreSQL reassignment barrier regression; kept inherited session/concurrency tests unchanged.
- Added ten schemas: IntakeDisplayActor, IntakeDisplayAssignment, IntakeDisplayAssignmentsResponse, IntakeDisplayDetail, IntakeDisplayDetailResponse, IntakeDisplayHistory, IntakeDisplayHistoryResponse, IntakeDisplayPageResponse, IntakeDisplayStaff and IntakeDisplaySummary.
- Added IntakeDashboardView parameter; no operation removed/added and no existing component changed.
- Applied Pint import ordering in the pre-existing legacy-portfolio console helper. No behavior change to that helper.

No migrations, grant changes, history cleanup, contact-retention changes, frontend-copy changes or production deployment.
