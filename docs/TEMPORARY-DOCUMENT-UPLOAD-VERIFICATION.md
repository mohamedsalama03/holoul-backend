# Temporary document upload policy — verification

2026-10-04. Backend-only candidate change; no deployment and no production performance certification.

## Baseline and scope

Branch `release/unified-vps-candidate`; parent `c5c7a1335102370a54b93cbdbabdd2cf3c1d0e92`.
The dedicated policy commit containing this report follows that parent. Deployment files are a subsequent, separately reviewable change.

`HOLOUL_DOCUMENT_UPLOADS_ENABLED` is a typed Boolean, defaults to true, and must be false for the temporary launch. Changes are limited to config/documents.php, ProductionConfiguration, ManageDocuments, StoreUpload, CurrentCapabilities, .env.example, focused tests, the reviewed contract source manifest, and these policy documents.

All six reservation/content operations already document 503 SERVICE_UNAVAILABLE. Guest, customer and staff uploads, including old reservations and direct StoreUpload invocation, are rejected before new bytes are accepted. Recreate/drain every application process when changing cached configuration; this is not an instantaneous database switch.

## Results

| Gate | Result |
|---|---|
| New focused policy coverage | 11 tests, 151 assertions, PASS |
| Complete PHPUnit Unit + Feature + Architecture, PostgreSQL | 1,108 tests, 68,355 assertions, zero errors/failures/skips |
| Architecture included above | 9 tests, 52,399 assertions |
| Portfolio image publishing and Contact under uploads=false, AI=false | 2 tests, 107 assertions, PASS |
| Python contract/intake/manifest regressions | 16 tests, PASS |
| Document parser regressions | 17 tests, PASS |
| PHPStan/Larastan level 10 | PASS |
| Pint | PASS |
| Composer strict validation, platform requirements, locked audit | PASS; no security advisories |
| Fresh/repeated migrations, inherited upgrade/concurrency tests | PASS |
| Config cache, route enumeration, OpenAPI route/spec checks | PASS |
| Contract response validation | 3,315 responses; 179 operations observed; 156 success-covered; all 148 required success samples present |
| Website compatibility | 36/36, no structural differences |
| Dashboard compatibility | 67/67, no structural differences |
| Docker development/runtime builds and FPM config | PASS |
| Runtime source equivalence | 512 PHP files compared in each image; no differences |
| Source secret scan | PASS |
| Runtime vulnerability/secret scan | zero HIGH/CRITICAL, zero secrets; cached DB timestamp disclosed below |
| git diff --check | PASS |

Compatibility is static consumed-operation/reference-closure verification, not a new browser E2E run. Dashboard has one inherited annotation-only difference on identityGetCurrentUser, no structural/security difference.

The full suite retains the inherited real PostgreSQL concurrency, identity/session cookie race, document quarantine/scanning, and upgrade tests. No old security assertions were removed or relaxed.

## Security evidence

- Guest and authenticated customer intakes complete without a document; immutable revision count/identity and guest claim ownership remain intact.
- Both existing identity capability responses omit only `project_requests.documents.upload` for customers when disabled. Staff capability sets remain unchanged; staff upload operations are still enforced on the server.
- Existing Available download succeeds only for its authorized owner; a different customer receives 404. Metadata/state and exact-version access checks remain intact.
- With a real ClamAvScanner adapter pointing to an absent socket, processing throws, the document stays Quarantined, the inspector is never called, no scan_passed event exists, and download is denied.
- Policy changes do not synthesize scanner verdicts. Existing quarantined work can still run through the unchanged scanner/inspector pipeline when services are present. Re-enabling restores reservation/content behavior.
- The isolated full suite had a real scanner and freshly downloaded signatures. This is test infrastructure, not the temporary production service set.

## AI-disabled boot

The rebuilt runtime boots with APP_ENV=production, uploads disabled, sandbox AI disabled, no Gemini key and no scanner socket. All nine recorded boot assertions pass. This isolated boot uses the explicit local-verification deployment profile; it does not certify real production SMTP or TLS. Strict ProductionConfiguration acceptance and rejection cases are separately covered in the full suite, including invalid policy types.

Launch settings: HOLOUL_AI_ENABLED=false, HOLOUL_AI_DRIVER=sandbox, HOLOUL_GEMINI_APPROVED=false, HOLOUL_GEMINI_DOCUMENTS_APPROVED=false.

## Unchanged contract

OpenAPI 3.1.1; API 1.8.1-gemini-documents-candidate; 204 operations; 265 schemas.
SHA-256: `3c93a09f08ce199cc1c2867ad846a1ca554fd03b6d934070178c5101646df410`.

The initial contract build correctly failed on a stale source manifest after the five application/config files changed. Those exact changes were reviewed and only their five source hashes refreshed; the second build passed with byte-identical OpenAPI. Both results are retained.

## Images and evidence

Runtime: `sha256:8c9d1fa23721d19949bf17387dedd5d0614d2ac1bb0aac3cf0a81fe12cdd400d`.
Development: `sha256:cf295f8f4b1c99945473cadc6898391c0c752ee380f8f09024267e6f36a0736b`.
Trivy 0.74.0: `sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969`.
Vulnerability DB updated 2026-10-03T14:28:08Z, downloaded 2026-10-03T18:58:03Z; cached scan is not a claim of future advisory coverage.

Private local artifacts: `/home/mohamed/.local/share/holoul-freezes/upload-policy-20261004`: JUnit XML, quality-results.json, contract-validation.log, frontend-compatibility.json, disabled-boot.log, runtime-scans.json and source-image-equivalence.json. Raw contract samples and runtime secret volumes are not committed. WSL startup failures before execution were retried; failed launch attempts are not successful checks.

See [the exact Website/Dashboard handoff and restoration procedure](TEMPORARY-DOCUMENT-UPLOAD-POLICY.md). Neither frontend repository nor the live same-origin stack was changed. Historical capacity failures and B8 evidence remain preserved. Production deployment remains unauthorized.

