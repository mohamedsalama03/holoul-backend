# F4 intake display candidate

Baseline: `3bc85401e96a1f8b843d1db0f13a4ae9b86572ee`, contract 1.4.0-public-services-candidate, SHA-256 `7739bd957665b285c3baea8a4883aa987d6d11dee3fb43bcc5ef1ff9fb0c8051` (202 operations).

R1–R4: opt-in `view=dashboard` on the five staff intake GET operations (list, detail, by-reference, history, assignments). Existing default reads, customer reads and all command responses stay unchanged. Separate strict schemas describe enriched responses; no role grants or new directory endpoint. Reads remain request-scoped, including closed requests and disabled historical staff. Bulk display lookups avoid per-row queries.

Directory IDs come from canonical request ownership; immutable revision submitter IDs keep their existing meaning. Customer names use the current account display name, guest names use the submitted revision. Guest origin remains visible after claim. History distinguishes guest submission, customer, staff and system without rewriting history.

No schema migration, cleanup of historical requests, frontend edits or production deployment. Retain prior failed performance evidence. Verification will include focused PostgreSQL tests, inherited intake/session tests, architecture, static analysis, formatting, Composer, contract compatibility/drift, image checks and the existing eight real-backend frontend tests.
