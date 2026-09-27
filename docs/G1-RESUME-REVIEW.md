> **Current status (2026-09-27): BACKEND FEATURE READY FOR WEBSITE INTEGRATION.** The external F1 blocker has passed final verification. See [G1-FINAL-ACCEPTANCE.md](G1-FINAL-ACCEPTANCE.md) and the [accepted website handoff](G1-WEBSITE-INTEGRATION.md). The earlier report below is preserved as historical evidence, including its then-blocked classification. Production performance remains pending the target VPS.

---

# G1 resume: candidate baseline and pre-completion review

This is a backend feature candidate, not a production release. The Identity Session Rotation Race remediation was explicitly approved before G1 resumed. Target VPS performance certification remains pending.

## Candidate history

- Original main: `96445baded70ddd2e4d8b8617793c78a4a3e1816` (left unchanged, index untouched).
- `codex/candidate-pre-g1`: `55b3b79eb205721ac74c2481a92aeff1ae06db25`. Exact 693-file pre-G1 accumulated B8/P1/P2/P3/F1-E1 candidate; no acceptance or production claim.
- `codex/candidate-identity-approved`: `9940acf6d9e16c7da3cd08b1a6028c875d5accd0`. Exact reviewed pre-G1 runtime plus the approved identity remediation, permanent tests, report and historical evidence hash inventory.
- **Exact resumed G1 baseline: `9940acf6d9e16c7da3cd08b1a6028c875d5accd0`**. The final G1 checkpoint will be its child, so identity and G1 can be reviewed as independent diffs.

Local recovery bundle: `artifacts/g1-resume/candidate-checkpoints.bundle`. Original ignored evidence remains in place. SHA inventory: `artifacts/g1-resume/historical-evidence-sha256.json` (SHA-256 `fa35e81daa7a52578b03cee3ac4cc9ac8fe5ef32b6ee846e9054398dcb432dc5`), also committed in the identity checkpoint. Private evidence itself is not committed. Failed performance and verification attempts are retained, not squashed.

The baseline has 29 migrations, 164 OpenAPI operations, OpenAPI SHA-256 `1422e14ef0c18204c420080c33f0b597f39cce5093acf358aa15aa1ee980499b`. The paused draft G1 contract was already present in the working tree; it is not the accepted baseline.

## Inspection before further implementation

**Implemented:** canonical ProjectRequest plus immutable revision/contact snapshot shared by both paths; server-derived authenticated contact and owner; explicit incomplete-profile failure; bounded anonymous draft capability; exact validators, taxonomy/money parser, idempotent submission; independent expiring claim token; transactionally locked claim with immutable receipt; private quarantine/scan/claim document path; explicit additive nullable ownership guards; migration, constraint and real PostgreSQL domain races.

**Incomplete:** the last full pre-pause G1 run was not green; a nullable-only constraint correction had only focused verification. Guest/public requests had not been tested against the newly approved identity semantics. Final migration/contract/image/browser/F1 gates and final classification remained outstanding. Existing reports describing G1 as complete are historical draft statements, not acceptance.

**Unsafe until corrected:** GuestIntakeThrottle used the session for capability/persona/rate checks but did not suppress session persistence. Guest responses unnecessarily saved their bootstrap session. Public taxonomy could refresh a presented authenticated session or recreate a missing cookie-addressed session even though it needs no identity. The red run reproduced two unwanted session-write failures; the delayed guest/login scenario already preserved the newer login and remains a regression test. These are G1 integration defects; the approved identity middleware and tests are preserved.

**Redundant:** public taxonomy loaded the authentication session solely for a per-session throttle, in addition to the IP throttle. Public taxonomy will use exact-origin and IP throttling without loading or saving cookies/session state. Guest draft/upload/submit continue to require explicit CSRF bootstrap, but must not save or emit authentication state.

## Transition decisions

A guest draft capability is bound to the original session and CSRF generation and expires after 30 minutes. Login/MFA/logout rotation does not migrate anonymous draft capabilities into authenticated ownership. The website must keep unsent form fields locally, resolve current identity, and use authenticated intake after login. Original anonymous attachments are not silently attached to an authenticated draft. A completed guest submission has its independent claim token; that token survives login/logout and can be explicitly claimed by the verified matching customer. MFA currently authenticates staff: successful MFA does not confer customer claim permissions. No new identity/MFA feature is added.

The six new transition scenarios are covered by separate G1 tests, in addition to the immutable inherited identity tests. Schema and route activation remains gated on fresh/exact-upgrade migration and contract verification. No frontend source or frontend contract copy is changed.

## Additional review finding before final acceptance

The first resumed image gate was stopped after review identified a retired-cookie bootstrap edge case. A draft bound only to its cookie ID could be revived by explicitly bootstrapping that old cookie after login destroyed its stored session. A dedicated red test reproduced HTTP 201 instead of the expected 404. The G1 API now hashes both the session ID and the server-side CSRF generation for its opaque capability binding. This changes no identity middleware, cookies or schema. Claim tokens remain independent. The initial 148-test intake run and interrupted full gate are retained as earlier evidence; final acceptance requires the corrected HTTP/session run and a complete new image gate.

## Final resumed outcome

The backend image gate passed 946 tests / 54,717 assertions, and the byte-identical inherited Chrome race harness passed all six iterations. G1 remains **BLOCKED** because the required real frontend F1 suite did not pass 6/6 (last run: 0 passed, 1 failed, 5 serially skipped). Separate E2E helper changes were observed in the frontend during this backend run; application and contract files remain equal to the approved identity snapshot. No frontend source was edited or reverted by this task. `G1-VERIFICATION.md` records exact candidate hashes, passing and failed evidence, local-only activation and the remaining gate.
