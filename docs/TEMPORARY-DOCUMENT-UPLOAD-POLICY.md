# Temporary document upload policy

Base: release/unified-vps-candidate at c5c7a1335102370a54b93cbdbabdd2cf3c1d0e92.
Scope: reversible new-document upload restriction only. No deployment authorization.

## Backend setting

HOLOUL_DOCUMENT_UPLOADS_ENABLED=false disables new document reservation and content upload.
The default is true, preserving existing installations. Only a real boolean is accepted through
the existing Environment parser; production validation also rejects non-boolean resolved policy.

Deploy the same value to PHP-FPM, every worker, scheduler and migration process. Recreate all
application processes together, allowing in-flight requests to drain; configuration is cached
per process and this is not a live database switch. An old process must not remain serving
requests with the previous policy after cutover.

The shared Documents module enforces reservation and content authorization. StoreUpload
checks again before reading/writing document bytes, including previously issued reservations.
Identity, CSRF, ownership, role and parent/version checks remain in force. An unauthorized or
malformed request may retain its existing 401/403/404/422/precondition response; otherwise a
disabled upload receives the existing 503 SERVICE_UNAVAILABLE envelope.

Affected operations (all already document the ServiceUnavailable response):
- guestIntakeReserveDocument
- guestIntakeUploadDocument
- documentReserve
- documentUpload
- projectDocumentReserve
- projectDocumentUpload

Proposal attachment of an already Available document is not new content upload.
PublicPortfolio image processing is a separate, unchanged capability.

No changes to scanning verdicts, reconciliation, stored states, immutable object versions,
document history, claim semantics, or download authorization. Stored quarantined objects
can still be processed if a real scanner later becomes available; absence of a scanner cannot
promote them. Required document workers retain deletion/reconciliation duties. Uploads remain
optional for guest and authenticated intake.

## Website Agent handoff — implementation required in Website, not done here

Authenticated customer:
GET /api/v1/identity/me -> data.capabilities
GET /api/v1/identity/capabilities -> data.capabilities
The existing project_requests.documents.upload value is omitted when uploads are disabled.
Refresh identity on session/persona changes; do not assume previously cached capabilities
remain current. Keep unrelated capabilities intact. No API schema or endpoint was added.

Guest:
There is no public backend capability endpoint. Implement a Website SERVER deployment flag
named WEBSITE_DOCUMENT_UPLOADS_ENABLED, parsed as an explicit true/false value and passed
from the server to the intake UI. Temporary production must supply false. This is a handoff
requirement, not a flag already implemented by Website. Merely setting it on the current image
does not disable its UI. Require the explicit value in the reviewed production Website build.
Do not put secrets in browser settings.

Show upload controls only when the Website flag permits them AND, for authenticated customers,
the fresh identity capability permits them. Guest UI uses the explicit Website flag.
Hide/disable new file selection, uploading, AI document analysis and actions requiring a new
Available upload. Handle existing local draft selections without an empty/fake attachment:
allow the user to clear a pending selection and submit their project without a file.
Preserve legitimately stored historical document state. Handle server 503 safely if policy
changes while the page is open. A generic 503 alone is not a capability signal: other outages
use the same envelope. Determine availability using identity capabilities and the explicit
Website deployment flag, not by probing uploads or exposing operational scanner status.

Customer wording:
Arabic: رفع المستندات سيكون متاحًا قريبًا.
English: Document upload will be available soon.

Do not expose scanner names, RAM, infrastructure or operational failure details.
Frontend hiding is UX only: backend policy is authoritative.

## Dashboard Agent handoff

No Dashboard source changed. Customer upload capability disappears while disabled.
Staff did not receive project_requests.documents.upload before this change and do not receive
it now; do not use its absence to revoke staff project/portfolio permissions. Staff NEW document
uploads receive the documented 503 under the server policy. Keep metadata, immutable history
and authorized Available downloads visible. Never represent Quarantined/Rejected/Uploading as
clean or downloadable. No blanket hiding of document history or portfolio image functions.

## AI configuration

HOLOUL_AI_ENABLED=false
HOLOUL_AI_DRIVER=sandbox
HOLOUL_GEMINI_APPROVED=false
HOLOUL_GEMINI_DOCUMENTS_APPROVED=false

No Gemini key is supplied or needed. Sandbox driver selection does not enable AI.

## Contract freeze

OpenAPI 3.1.1; API 1.8.1-gemini-documents-candidate; 204 operations; 265 schemas.
SHA-256: 3c93a09f08ce199cc1c2867ad846a1ca554fd03b6d934070178c5101646df410.
Do not update either frontend contract copy.

## Full-mode restoration after 8 GB upgrade

1. Upgrade and verify actual VPS CPU/RAM/disk; keep public upload disabled.
2. Start the preserved isolated ClamAV daemon and FreshClam updater with the reviewed configuration.
3. Verify authenticated signature freshness and real readiness.
4. Verify EICAR rejection using controlled private synthetic test traffic.
5. Verify clean PDF/DOCX through the real scanner and isolated inspector, including 10 MiB.
6. Run the full document E2E and exact-version/ownership/session security regressions in isolation.
7. Pass the target capacity and inherited performance gates, including signature reload under load.
8. Recreate the application processes consistently with HOLOUL_DOCUMENT_UPLOADS_ENABLED=true
   only after gates pass and public rollout is approved.
9. Roll out the reviewed Website flag/UI change to expose uploads.
10. Confirm actual Upload -> Quarantine -> Scan -> Available/Rejected and denied unscanned downloads.

Do not bulk-mark quarantine clean. Exhausted historical scans use the existing authorized
bounded retry process; enabling the upload flag alone neither scans nor releases them.
