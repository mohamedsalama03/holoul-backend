# G1 — dual project intake

Resumed baseline: `9940acf6d9e16c7da3cd08b1a6028c875d5accd0` (`codex/candidate-identity-approved`); see `G1-RESUME-REVIEW.md` for independently reviewable candidate history.

Authorized scope: the G1 request supplied on 26 September 2026. This is an explicit additive extension of B0's customer-only ownership assumption, not a new project domain. Baseline: `96445baded70ddd2e4d8b8617793c78a4a3e1816`, branch `main`, with the existing uncommitted B8/P1/P2/P3/F1-E1 candidate. Its 693 source files and hashes are preserved in `artifacts/g1/baseline.json` and `baseline-source.tar.gz`.

## Ownership and history

`project_requests.guest_origin` is immutable. Only guest-origin requests may initially have NULL customer and customer-user IDs. Every normal customer foreign key remains in force. A single append-only claim receipt permits exactly one NULL-to-customer association, in the same transaction. Already owned requests cannot be reassigned. Guest revisions retain NULL original author/customer and their immutable contact snapshot forever. Mutable draft ownership and document authorization ownership may acquire the claimant once; bytes, versions, checksums, storage keys, original uploader and historical attachments are never rewritten.

Unclaimed requests can be inspected by authorized intake staff, but downstream Discovery/Proposals/Projects and requests for customer information require a claim first. No fake user/customer is created.

## Guest workflow

The browser retains pre-submission contact/project fields locally. An empty canonical request/draft exists only to authorize optional document upload. Creation returns a 256-bit capability; only its hash and a browser-session/CSRF-generation binding are persisted. It expires after 30 minutes. No public draft/request detail or document download endpoint exists. Public taxonomy reads are stateless with exact-origin/IP limits. All guest mutations require an explicit first-party CSRF bootstrap, HTTPS origin and session binding; their responses never persist authentication state or emit session/CSRF cookies. They also require dedicated fail-closed limits, exact fields and bounded payloads. Authenticated or partially authenticated sessions cannot silently use guest mode.

The final submit operation validates all contact/project fields, uses the same exact-money parser, active taxonomy reader, canonical immutable revision and private document pipeline, and atomically records its idempotency receipt. One draft produces one submission; another key cannot create another revision. A PostgreSQL advisory lock and unique browser/key pair also prevent reusing that final key on another draft in the same browser session. Same-key retries return the same safe confirmation only while the capability is valid. This does not deduplicate separate intentional ideas by email/content.

Guest document bytes remain subject to the B4 10 MiB PDF/DOCX limit, generated quarantine keys, bounded type inspection, malware scanning, private storage and retention. Upload authorization is checked before storage I/O and again under the request lock before finalization. A guest never downloads a document, even when it is Available.

Guest reservations additionally have a PostgreSQL-serialized global cap of 500 MiB, 100 active documents and 50 pending uploads. Claims acquire the existing customer quota lock and must fit its 1 GiB / 1000-document limits; an over-quota claim rolls back the complete association. The existing scheduled expiry command retires attachments on expired, unclaimed drafts older than 24 hours through durable B4 deletion. Submitted history is excluded. Empty draft rows and unclaimed submitted history are retained; no new automatic history-erasure policy is introduced.

## Claim

Every guest receives the same continuation: create/sign into an account, verify its email and explicitly submit the single-purpose claim token. There is no public account lookup, automatic account creation or association by email. The token is bound to the submission, expires after 72 hours and is persisted only as a SHA-256 hash. It is reproducible for an uncertain submission retry using a server-keyed derivation bound to the original capability; neither reference nor email derives it.

Claim requires a currently authenticated, enabled, email-verified customer whose normalized current email matches the immutable submission email, plus the independent claim token. It uses the persisted identity lock, customer lock, request lock and claim receipt uniqueness. An exact same-key retry by the same authenticated customer replays its recorded result; a different key, user, expired token or competing claim receives the same generic rejection. The claim token itself cannot authenticate or retrieve request details. Claim and subsequent withdrawal serialize on the request. Claim does not revise original content.

Expiry prevents a new association. An already completed exact replay remains safe after token expiry because it requires the same currently verified owner and returns only the immutable prior receipt; it grants no new access. The frontend must fetch the owned request for a current ETag before further mutations. Lost/expired unconsumed tokens cannot be recovered by email/reference; no public reissue endpoint exists in G1.

## AI and deployment boundary

No anonymous AI operation is approved in G1. Guest submission works with AI disabled; existing authenticated B7 operations remain available after claim under their original source binding, explicit application, privacy, availability and cost rules. No paid provider or frontend changes are included. Production performance certification remains pending the target VPS; a local feature gate cannot resolve that blocker.
