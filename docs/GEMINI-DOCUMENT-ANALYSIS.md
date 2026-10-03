# Local Gemini intake document analysis

3 October 2026. The owner explicitly requested activating document analysis and asked whether it works on the free tier. This extends the earlier approved local description pilot to the available attachment of a customer's own editable intake draft. It does not enable external processing of staff revision sources or private delivery documents, and is not a production release.

## Processing and limits

The existing authenticated, CSRF-protected AI operation accepts `analyze_document` with the parent ETag, idempotency key, document ID and explicit consent. Ownership, draft attachment, available/scanned state, exact object version and checksum are verified before admission, around extraction, before dispatch and again before saving the result. The durable queue and dispatch fence prevent duplicate provider calls after ambiguous failures.

PDF and DOCX text is extracted in the existing isolated networkless inspector. Only this text passes through the existing contact/credential minimizer and reaches the fixed Google HTTPS endpoint. Original files, filenames, storage URLs, customer profiles, other draft fields, and chat history are not sent. Regex minimization is not a guarantee of anonymization: fictional, non-confidential content only during this local free-tier pilot.

The source must contain readable text of at most 20,000 characters; oversized text is rejected rather than silently truncated. Image-only scans, charts and layout interpretation are not supported. The upload limit remains 10 MiB. Results contain the existing `summary` and `findings` fields, are displayed as plain text, and cannot overwrite the document or project description. Arabic input receives an explicitly Arabic prompt and an Arabic-summary check. Suggestions still require human review for accuracy.

The existing local model `gemini-3.5-flash-lite`, maximum 20 runs per customer per day, 1,000,000 micro-USD daily conservative estimate budget, 100,000 micro-USD reservation, timeouts, TLS, response size cap and no automatic external retry are unchanged. Description improvement remains enabled. A separate `HOLOUL_GEMINI_DOCUMENTS_APPROVED` flag defaults false and is checked both at admission and execution. The owner-authorized local activation sets it true alongside `HOLOUL_GEMINI_APPROVED`; normal startup reuses those running containers.

## Free tier

Google lists free input/output for this model. Actual project quotas and capacity are account-specific; they are not the application's own 20-run limit. No billing upgrade was requested or performed. The [official pricing page](https://ai.google.dev/gemini-api/docs/pricing) and [rate-limit documentation](https://ai.google.dev/gemini-api/docs/rate-limits) were checked on 3 October 2026. The [unpaid-service terms](https://ai.google.dev/gemini-api/terms) say submitted content and responses may be used for product improvement and reviewed by humans; sensitive, confidential and personal information must not be submitted. The website now discloses Google, the extracted-text transfer, free-tier data use and the supported document limits before the user requests analysis.

## Compatibility

Previous contract `1.8.0-customer-intake-candidate`: `ca3608446def45a245ead525d69b83ca345b3202fb1deb1f7d4be081c8d13e63`.

New contract `1.8.1-gemini-documents-candidate`: `3c93a09f08ce199cc1c2867ad846a1ca554fd03b6d934070178c5101646df410`.

All 204 operations, schemas, fields, responses, parameters, permissions and security schemes retain their structure. Only the candidate version and the AI creation description change. The website pin/types are refreshed; the dashboard copy is unchanged. No migration, account change or new provider package is required.

## Verification

- Focused/provider/document/ownership/architecture/route regression: **76 tests, 54,355 assertions passed** on PostgreSQL.
- Real offline PDF/DOCX parser/security suite: **17 tests passed** in the pinned inspector image.
- Website assistant/intake/i18n regression: **22 tests passed**; explicit document consent by button, disclosure, findings rendering, unavailable-document blocking and no automatic description replacement covered.
- PHPStan/Larastan level 10, Pint (**661 source/test files**), Composer strict validation/audit, TypeScript, ESLint, website production build, SwaggerParser, source-contract drift, and PHP-FPM configuration validation passed.
- Docker packaging used the retained pinned runtime with unchanged Composer dependencies and no package download. This is not a fresh dependency-install certification or VPS performance test.

The initial new test incorrectly expected the reservation state `released` when approval is withdrawn after admission. Existing semantics settle that admitted reservation at zero; the assertion was corrected and strengthened to check zero cost and zero reserved budget. The initial failure remains in `artifacts/gemini-documents/focused.log`; the passing complete regression is `regression.log`.

## Live acceptance

All six local application/worker/scheduler processes are healthy on `holoul-app:gemini-documents-local`, image identity `sha256:6322064c5e70b6728e520d9cc52a72888bef9bbe9cae3b51f0b7029c1d25412a`. Runtime inspection confirms both approval flags enabled and zero startup violations; readiness is HTTP 200. The website build is `Zja8t0Y47ZXjrbzeQGNN1`, served on loopback 3103 through `https://localhost:8443`. The Windows launcher was updated to that port and its warm-start check passed.

Real Chrome verification used a synthetic customer and two invented documents, no real customer material. Both went through upload, the actual safety scan, private storage, the isolated extractor, the durable AI queue and Google generation. The UI displayed summary/findings, Arabic output for the Arabic DOCX, no automatic apply control for analysis, and the unchanged original description. Each analysis sent exactly one provider request. Neither draft was submitted. Login/logout succeeded in the final run.

| Source | Durable run | Result | Input/output tokens | Conservative cost estimate, micro-USD |
| --- | --- | --- | --- | --- |
| Synthetic text PDF | `01a102a8-4f6e-7141-8058-5ae75178e3ae` | succeeded / settled | 207 / 180 | 513 |
| Synthetic Arabic DOCX | `01a102a8-714a-73f3-9886-92f4184405b4` | succeeded / settled | 262 / 213 | 612 |

The figures are the application's conservative paid-tariff estimates, not a Google invoice or proof of a charge on the owner's stated free tier. No billing configuration was changed.

The first browser attempt immediately after the local runtime switch stopped at login with a temporary-service error and made no analysis request. Readiness and configuration were checked, and the subsequent complete run passed without changing authentication code. Both results are retained as `artifacts/gemini-documents/live-result-*.json`; the initial transient's cause was not conclusively isolated. Metadata-only runtime evidence is `live-runtime.json`. Raw prompts, credentials and API keys are absent from these reports.

Existing customer email-admission behavior, staff MFA and session rotation safeguards are preserved. No account role, real customer's verification state, production service or Git release was changed. The prior application image and ingress/launcher backups are retained for rollback.
