---
version: 1
slug: "docs-swagger-index-html"
primary_target: "docs/swagger/index.html"
related_targets: ["docs/swagger/assets/reference.css","docs/swagger/assets/reference.js"]
---

# HOLOUL Swagger reference

Scope: docs/swagger/index.html and its local asset/download bundle. Visitor mode: Read.
Audience/job: developers inspecting the current HOLOUL contract and integrating the dashboard or website.
Primary tasks: find an operation, read its inputs/responses/security requirements, download the unchanged contract.
Proof: actual 182-operation, 221-schema OpenAPI 3.1.1 data; candidate version and exact JSON SHA-256.
Constraints: preserve authoritative JSON, no backend or frontend application changes, no API execution/credentials/remote validators in this portable reference.
Direction: user-pinned Swagger standard, refined within existing HOLOUL blue/slate brand; code-led, no comp tournament. Wide domain rail and dense method/path list; mobile domain selector. Signature interaction is combined operation search and domain filtering with instant counts and a clear recovery path.
Open decision: live same-origin execution was offered as an optional choice; no answer at build time, so the stated working default is reference-only.

## Implemented finish

Visual system: `docs/swagger/DESIGN.md` records the extracted tokens, standard Swagger method-color exceptions, component states and responsive behavior. It is scoped to this documentation bundle, not the backend or dashboard.

Current evidence: API `1.3.0-directory-sort-candidate`, OpenAPI `3.1.1`, 182 operations, 221 schemas and 17 domains. The wide rail is 252px; at 760px and below it becomes the domain selector. The candidate status lives in the release note beneath the header facts. Introductory and expanded operation prose is limited to 75ch.

Finish review: the final reviewer returned `ship` after confirming the candidate-status placement and prose-measure fixes. This verdict covers those requested finish fixes; it is not a new whole-surface accessibility audit. Review images are retained at the worktree's `.impeccable/review/desktop-viewport.png`, `mobile-viewport.png` and `operation-desktop.png`.

Reference-only execution remains the working default for this edition. The optional live-execution question is unanswered; do not record that default as a durable user preference.
