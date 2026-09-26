# HOLOUL B8-P2 — final backend review and frontend contract

**API classification: FRONTEND INTEGRATION BLOCKED for the complete requested dashboard scope.** The existing API is documented for integration, but staff customer-directory and staff/eligible-assignee APIs are absent, current-user capability data is absent, and approved visual-dashboard parity cannot be established from the repository. These are explicit product/integration gaps, not a claim that the implemented routes are unusable. No missing business feature was added.

**B8 remains NOT PRODUCTION READY. Production performance certification is pending the unavailable target VPS.** No additional WSL performance measurement/tuning, SLO change, external AI enablement, production deployment or commit is part of this review.

## 1. Backend final-review findings

Reviewed B1–B7 and the current uncommitted B8 implementation against B0: architecture/module dependencies, shared infrastructure, routes, identity/session/CSRF/MFA/recovery, authorization and customer isolation, taxonomy/intake, private documents, discovery/proposals, projects, optional AI, notifications, reporting and audit. Domain-level findings and source references are in [Identity/Intake](B8-P2-IDENTITY-INTAKE.md), [Commercial/Projects](B8-P2-COMMERCIAL-PROJECTS.md), and [Files/Async/Reporting](B8-P2-FILES-ASYNC-REPORTING.md).

| Area | Finding and review decision |
|---|---|
|Architecture|Module-owned actions and read contracts remain; technical facilities stay in Infrastructure. Real PostgreSQL transactions, after-commit publication, durable operation intent, identifier-only Redis messages, append-only audit and separate runtime/migration identities remain enforced. No architecture relaxation or external I/O shortcut was introduced.|
|Authorization/isolation|Reviewed scoped DTOs and parent/customer/assignment/member checks; no demonstrated ownership bypass found. Staff/customer paths intentionally differ. This is scoped engineering review, not proof of universal defect absence or production security certification.|
|Duplicate paths|Parent-checked customer/profile/request aliases retained and identified. Reference lookup is intentional. No stylistic rename/removal.|
|Response/status differences|Documented existing200 logout,202 pending MFA/AI-unavailable,201 immutable receipts; command receipts differ from read DTOs. No blanket status/shape rewrite.|
|Pagination/filtering|All reviewed frontend collections have page, window, write or database bounds. Existing cursor vocabulary/order/filter differences are explicit; no unbounded UI collection was identified.|
|Versions|Parent ETags differ from child versions. Identity/profile has no optimistic precondition; document/project/proposal/AI scopes are explicit.|
|Internal fields|Private storage locators/versions, provider credentials, raw AI source text and scanner diagnostics are absent from public document projections. Some approved technical IDs/hash/cost fields remain in proposal/AI DTOs; label them as technical, not customer presentation. Customer updates contain author_id without a safe staff-name lookup.|
|Timestamps/money|ISO model timestamps and raw PostgreSQL timestamp strings both exist; frozen without a breaking rewrite. Decimal/minor-unit values stay exact strings and currencies separate. Reporting accepted proposal value is not revenue.|
|Unused code/TODO|Lexical review found no TODO/FIXME/HACK/XXX markers in application/routes/configuration and no application type name referenced only by its declaration. Container/route/DI bindings and existing architecture tests were reviewed. This does not prove every method reachable; no speculative source deletion was made.|
|Unused permission controls|The four seeded `identity.self.read/update` and `customers.self.read/update` permission codes have no direct action checks. Those operations currently authorize through current identity/session and owner/persona policy. Do not present these catalog entries as independently revocable UI capabilities; no permission behavior was silently changed.|
|Documentation coverage|Every frontend operation is in OpenAPI and the generated matrix; health/operational endpoints are excluded. Next.js hosting itself is not implemented in this backend batch.|

## 2. API inconsistencies corrected

Only two narrow product corrections:

1. Invalid taxonomy `cursor` now returns `error.fields.cursor`, matching the actual public query parameter, instead of `error.fields.after`. A focused HTTP regression covers malformed/overlength cursors on all four public/admin category/subcategory listing variants.
2. A validation error whose unsafe field names are all filtered now serializes `error.fields` as `{}`, consistently preserving the field-map object contract instead of changing its JSON type to `[]`. A regression checks object shape and absence of private field/message text.

No route, permission, state transition, collection ordering, version lock, durable-write rule or idempotency replay behavior was changed. Existing response variations are explicitly documented instead of cosmetically normalized.

## 3. OpenAPI specification status

[docs/openapi.json](openapi.json) is OpenAPI3.1.1, assembled from three canonical reviewed JSON fragments. It covers **158 frontend operations**:65 identity/customer/taxonomy/intake,49 commercial/project,43 file/AI/notification/reporting/audit, and1 API identity operation. GET also supports implicit HEAD. `/sanctum/csrf-cookie` is the sole framework exception outside `/api/v1`; `/health/live` and `/health/ready` are not frontend endpoints.

Every operation supplies method/path, security, personas, named permissions, parameters/body, success/error schemas, frontend feature and implementation-source references. ETag/If-Match, CSRF, idempotency and continuation metadata are operation-specific. Shared error components include all twelve requested status examples plus405/500. Success DTOs and bounded objects are explicit, with separate customer/staff and receipt/read schemas.

The source-hash manifest, deterministic assembly check and route inventory detect unreviewed implementation/route/spec drift. Real HTTP samples provide runtime response evidence for the operations listed in the final verification report. Request/query contracts are manually cross-checked against validators and existing request-validation tests; schema validation is not represented as exhaustive runtime proof of every possible request or state.

## 4. Customer endpoint matrix

The [complete generated matrix](B8-P2-ENDPOINT-MATRIX.md) contains every method/path/persona/permission. Its customer operations cover authentication/profile, active taxonomy, drafts/submissions/revisions/information/history, proposal decisions, Projects/milestones/updates/files, optional AI, inbox/preferences and session security.

The [integration package](B8-P2-FRONTEND-INTEGRATION.md) connects these into the requested journey. Exact wire enums and visible/internal distinctions are in the domain guides. A request `approved` means customer acceptance; it is not internal proposal approval. A converted Project is a separate resource. Customer completion confirmation does not itself advance Project state.

## 5. Admin endpoint matrix

The same [complete matrix](B8-P2-ENDPOINT-MATRIX.md) maps overview statistics, request operations, discovery, proposals, Projects/team/milestones/updates/evidence, taxonomy, scoped documents, notifications/delivery investigation, reporting and audit. Staff MFA, read visibility and action-specific permission checks remain required.

The [admin integration table](B8-P2-FRONTEND-INTEGRATION.md#admin-workflow-and-gaps) distinguishes aggregate customer reporting from customer administration and current membership/assignment APIs from staff enumeration. The domain guides contain the detailed staff workflow and chained permission checks.

## 6. Frontend/backend gaps and readiness decision

| Priority | Gap | Consequence / next decision |
|---|---|---|
|Blocking for a complete Customers dashboard|No staff customer directory/detail/search/edit API. `/customers` is owner-only; `/admin/reports/customers` is aggregate statistics.|Define the minimum approved customer administration screen/API scope before implementation. Do not use another customer's owner endpoint as a workaround.|
|Blocking for self-contained assignment/team pickers|No staff list or eligible-assignee lookup. Existing authorization/member actions accept a known UUID.|Define an authorized bounded staff/eligibility projection. Do not expose all identities or manufacture IDs in the frontend.|
|Open integration decision|`identity/me` has no roles/permissions/capabilities, MFA-enabled flag or recent-password-expiry metadata.|Agree a capabilities contract or explicitly accept a reduced navigation approach. Backend authorization remains authoritative.|
|Design evidence unavailable|No approved visual Admin design asset/link was found in the repository. B0 architectural guidance and the supplied P2 dashboard-area list are available.|Review the actual approved screens before claiming UI parity. The listed gaps are observed API capabilities, not invented assertions about unseen designs.|
|Optional/deferred|No global discovery/proposal inbox, Project name/reference search/arbitrary sort, safe staff author labels, global file library, push transport, revenue/invoicing/export or arbitrary message composition.|Report first; do not add nonessential features in P2. Some absences reflect intentional domain restrictions.|

**FRONTEND INTEGRATION BLOCKED** is the classification of the complete requested integration scope. Frontend work on the documented, implemented journeys can proceed against this candidate contract with those limitations visible. This classification is separate from the unchanged production block and does not request or imply a new batch.

## 7. Authentication integration guide

Use the [concise Next.js guide](B8-P2-FRONTEND-INTEGRATION.md#authentication-and-csrf) and [exact identity flow](B8-P2-IDENTITY-INTAKE.md#exact-same-origin-nextjs-authentication-flow). They cover same-origin HTTPS,204 CSRF initialization, readable XSRF header proof, HttpOnly browser-managed session cookies, generic registration, customer login, staff MFA pending/full sessions, current user, verification/reset URL fragments, recent-password confirmation, expiry/revocation, logout and401/403/404 semantics. JavaScript never reads the authentication cookie.

## 8. Upload integration guide

The [file guide](B8-P2-FILES-ASYNC-REPORTING.md#upload-scan-download-and-removal-guide) defines reservation→raw PUT/finalization→quarantine→available download, exact PDF/DOCX and10MiB limits, SHA-256/size validation, parent ETags, expiry, scan retries, error statuses and state labels. Request draft replacement/removal preserves historical references; finalized Project evidence is immutable. No private storage key, presigned/public object URL or separate finalize endpoint is invented.

## 9. AI integration guide

The [AI guide](B8-P2-FILES-ASYNC-REPORTING.md#optional-ai-integration-guide) documents creation/purposes, actor-private run states, validated suggestions, explicit apply/dismiss/cancel, stale-source rejection, idempotency and budget/rate/failure cases. Disabled AI yields a durable202 unavailable result; ordinary project submission never requires assistance. Backend `HOLOUL_AI_ENABLED=false` remains unchanged, and no external provider was enabled.

## 10. Contract-test evidence

Final measured gate results are recorded in [B8-P2-VERIFICATION.md](B8-P2-VERIFICATION.md). The independent P2 runner builds pinned PHP/Composer and verification-only Python images; runs Pint, PHPStan/Larastan10, Composer validation/platform/audit, PostgreSQL migrations, all existing Unit/Feature and architecture tests; captures real synthetic HTTP responses; verifies OpenAPI, route inventory, schemas and reviewed source hashes; and checks Docker/FPM/nginx and the document inspector.

The full suite passed 839 Unit/Feature tests and nine architecture tests. A fresh HTTP run passed 231 tests (230 repeated, one added) and supplied 1,535 validated responses: 125 operations observed, 102 with successes. All 158 operations have route/spec coverage; 56 lack a captured success response. The report preserves the first wrapper's failure and explains the successful independent recovery stages instead of claiming that wrapper passed end to end.

Checks preserve the original Feature/Authorization tests. Response verification includes safe success headers, exact binary media and bodyless204, canonical errors/code correlation, and negative schema sentinels. Private raw test samples can contain synthetic MFA/recovery material and are not public artifacts. The report separates static coverage, observed runtime coverage, missing samples, earlier failed attempts and final evidence. No WSL performance acceptance is rerun here.

The normal CI entrypoint `verify.sh` now includes the docs mount, private capture and offline contract validation before its **unchanged** subsequent performance gate. Hosted CI and branch protection are not claimed to have run or been configured by this local review.

## 11. Git/worktree recommendation

The **APPROVED B1–B7 BASELINE** remains HEAD `96445baded70ddd2e4d8b8617793c78a4a3e1816` (parent `bf738302657f8da1bca911058186a24e87b7ea37`). The **UNCOMMITTED B8 CANDIDATE** includes pre-existing B8/P1 evidence and the P2 contract/corrections. No B8 acceptance or production-ready commit was manufactured; no existing evidence was destroyed.

Recommended later organization: preserve the complete candidate on a clearly unaccepted branch such as `codex/b8-candidate-contract-review`, based on the approved HEAD. Review any future checkpoint commit as **candidate contract/operations work; performance pending**, never as an accepted B8 release. Do not cherry-pick only the spec while omitting the unaccepted reporting implementation it describes.

Frontend work can use a separate branch such as `codex/frontend-api-v1` and pin the reviewed OpenAPI hash plus the candidate status. A new worktree at HEAD does not copy dirty/untracked files: preserve/review the candidate patch and untracked files explicitly before moving work. Do not reset, clean, discard or falsely squash away failed B8 evidence. No branch creation, commit, push or PR is claimed by this recommendation.

A local [preservation manifest](../artifacts/p2-preservation/manifest.json) accompanies the complete tracked candidate patch and nonignored untracked source/document archive. This protects the reviewable working state without representing B8 as accepted. Private runtime configuration and raw response captures remain outside that source archive.

## 12. Remaining production blockers

The [B8 launch checklist](B8-LAUNCH-CHECKLIST.md) remains authoritative. The target remains Ubuntu VPS2CPU/8GB RAM/1TB disk, approximately150 customers/100 Projects/20 concurrent users, but is unavailable. The prior strict performance result remains failed: p95 must be<300ms, p99<1000ms, zero unexpected errors,480 authenticated HTTPS requests in24 synchronized waves of20 five seconds apart, with unchanged endpoint/correctness checks. P1's final p95 results662.590ms and699.075ms remain failures. No staggered diagnostic or passing contract test replaces them.

Still required: target host/private-service commissioning and measured capacity; public domain/TLS/ingress; PostgreSQL/Redis/storage TLS/access/credential separation; production email; scanner signatures/isolation; worker/scheduler operation; secret rotation/key escrow; durable monitoring/alert owners; offsite backups/PITR/exact object/key restore; approved RPO/RTO and retention/privacy/supplier policies; target migration/security/recovery rehearsal and release/deployment approval. External AI may remain disabled and is not required to close core integration.

Earlier B8/P1 documents describe the historical state at their dates. Their performance evidence is preserved; this P2 review does not initiate another local performance-remediation round or erase an outstanding launch control.

References for verification tooling: [OpenAPI3.1.1 specification](https://spec.openapis.org/oas/v3.1.1.html), [OpenAPI Spec Validator](https://openapi-spec-validator.readthedocs.io/en/latest/), [JSON Schema validation](https://python-jsonschema.readthedocs.io/en/stable/validate/). Dependency versions and wheel hashes are pinned in the verification-only lockfile; no frontend framework or application dependency was added for documentation.

B8-P2 BACKEND CONTRACT REVIEW COMPLETE — PRODUCTION PERFORMANCE CERTIFICATION PENDING VPS
