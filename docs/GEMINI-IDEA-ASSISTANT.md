# Gemini project-idea assistant — local pilot candidate

**Later approved extension, 3 October 2026:** [Customer intake document analysis](GEMINI-DOCUMENT-ANALYSIS.md) now also runs locally after explicit owner authorization, with separate document opt-in, free-tier disclosure and real PDF/DOCX verification. The description-only scope and evidence below describe the earlier checkpoint and remain preserved.

2026-10-03. Scope approved by the owner: help a customer write and improve their project idea. No production deployment is authorized.

## Implementation

The existing authenticated customer intake assistant now has a Gemini adapter behind the existing asynchronous AI provider boundary. It requests a description suggestion against the customer's current editable draft. The original stays unchanged until the customer explicitly applies the suggestion through the existing version-checked owner action.

No new HTTP operation, schema, migration, role grant or session middleware is introduced. The authoritative contract remains **1.7.0-portfolio-editor-candidate**, SHA-256 **ef0a849656234b937a1d76e7258835de5438bcb7bbd57a87f38b4ce011275761**, 204 operations. Provider and model were already string fields in the AI-run contract. Guest AI, document analysis, private delivery content, tools, URL fetching and autonomous business actions are outside this external pilot. Existing sandbox purposes remain available for tests.

Only the draft description is passed through the existing contact/credential minimizer and transmitted. Profile name/email/phone, budgets, ownership IDs and document bytes are not sent. Redaction is a best-effort safeguard, not a guarantee that arbitrary prose contains no personal or confidential information. Input, output and credentials are excluded from operational error messages; provider errors become stable codes without chained raw exceptions.

The server fixes the HTTPS Google endpoint, verifies TLS, uses a header key, forbids redirects and automatic HTTP retries, and bounds transport time and response size. Compressed response decoding is disabled to keep the wire-size bound meaningful. Google JSON output is validated again by the existing strict application schema. Thinking tokens count toward measured output cost. A timeout, ambiguous response or unknown usage keeps the reservation; it is never silently redispatched or refunded.

The website identifies Google Gemini before the optional action, supports Arabic/English draft text, and preserves a pending run across long waits. Checking a pending suggestion reads the same run instead of creating a new paid generation. Human review remains mandatory. Website changes are in the existing website checkout; the dashboard contract copy is unchanged by this provider work.

## Candidate settings for owner review

- Provider: Google Gemini Developer API, fixed model `gemini-3.5-flash-lite`, standard text generation, minimal thinking, maximum output 4096 tokens.
- Maximum input: 20,000 characters; application JSON output: 32,000 bytes; transport response: 128 KiB.
- Up to 20 admitted/run requests per customer per UTC day; 2 concurrent per customer, 8 globally.
- Local aggregate budget: USD 1 per UTC day. Reserve USD 0.10 per dispatch conservatively; settle integer micro-USD from returned input and output/thinking usage. Unknown outcomes retain their reservation and consume concurrency until separately reconciled.
- Rates reviewed 2026-10-03: USD 0.30 per million text input tokens; USD 2.50 per million output tokens including thinking. No free-tier or caching discount is assumed. These are estimates under the reviewed supplier tariff, not a guarantee about future Google billing changes.
- No automatic provider/model failover, paid retry, document upload, training-data opt-in, regional-residency promise or production activation.

For real customer ideas, use a Google Cloud project with active billing. Google's paid-service terms say prompts/responses are not used to improve its products; limited safety/abuse logging still applies and processing is not restricted to a chosen country by this API integration. Unpaid services may use prompts/responses for product improvement and human review, and Google instructs users not to submit confidential/personal information there. Review these terms before activation.

The approved architecture, `docs/B0-ARCHITECTURE.md` section 7 and its AI supplier/spending decision, requires accepted provider terms and spending limits before enablement. Selecting Google authorizes implementation; it does not by itself resolve paid-project setup, data terms or the concrete USD 1/day proposal. Until those and the private key are supplied, the existing local stack remains AI-disabled. Production configuration rejects this Gemini pilot even if its local approval flag is set.

## Private key setup

Run `C:\Users\Mohamed\Documents\HOLOUL\Set-Gemini-Key.cmd`, or in Ubuntu:

```sh
cd /home/mohamed/projects/customers/holoul-staff-direct-create
python3 scripts/set-gemini-key.py
```

Paste the key into the hidden prompt, not the conversation. The helper writes only `/home/mohamed/.config/holoul/secrets/gemini_api_key` (owner-only mode 0600, directory 0700), outside Git. It makes no external request and does not enable AI. `--check` reports file availability without printing the key. Obtain the key from [Google AI Studio](https://aistudio.google.com/api-keys).

After owner acceptance, layer `compose.gemini-local.yaml` after the normal local compose override, set `HOLOUL_GEMINI_APPROVED=true` in that deployment process, and switch all six backend processes together. The key is a read-only mount. Do not put it in frontend variables, screenshots, logs, command arguments or Git. Use a synthetic description for the first live call, inspect the actual output/usage without printing credentials, then exercise create/read/apply/dismiss through the same-origin website. A key alone does not prove model availability, billing or regional access.

## Evidence and remaining activation gate

Provider/configuration/schema/architecture focused suite: 89 passed (54,262 assertions) before the final bounded-response refinements. Existing AI/assistance PostgreSQL regressions: 51 passed (1,340 assertions), including real concurrency, ownership, retained original content, budgets and ambiguous dispatch tests. The final Gemini suite passed 31 tests (173 assertions), including the final output-schema and startup-configuration guards. PHPStan/Larastan level 10 and Pint passed. Composer strict validation and audit passed with no dependency changes. Contract source-manifest verification and `git diff --check` passed; the contract hash above is unchanged by Gemini.

Website: 22 focused tests passed, including slow-poll resume without duplicate generation, explicit human application, unmount cancellation, guest isolation and existing intake/i18n behavior. TypeScript, scoped ESLint, formatting, API compatibility checks and production build passed. The website source/build is prepared; its live process on port 3100 was not restarted for this change.

Runtime candidate: `holoul-app:gemini-pilot-verified-runtime`, image identity **sha256:944082b4171a3396151cbba82a6e5b0bef9ce7a6175ffc156354d119eeae735d**. The ordinary clean Docker build stalled downloading its compiler dependencies and was canceled; its failed evidence remains in `artifacts/gemini/build.log`. A network-disabled packaging build then succeeded against the retained, previously verified PHP/Composer runtime images, checking that `composer.lock` matched and rebuilding the production authoritative autoloader. Development dependencies and local fixture tools are absent from the final image. This verifies the retained-runtime candidate, not a successful fresh dependency download or production certification. See `artifacts/gemini/Dockerfile.retained-runtime` and `build-retained-final.log`.

All six backend processes started healthy with that image on the isolated 8444 stack. PHP-FPM validation and readiness HTTP 200 passed. A synthetic configuration check resolved the Gemini provider through the existing observation wrapper with no configuration violations. A runtime adapter check used an HTTP fake with stray requests prohibited: valid schema, 100 input tokens, 24 output/thinking tokens and 90 micro-USD computed cost. Real-browser staff password → MFA → current user → dashboard → logout passed 1/1 against this runtime. These are runtime and authentication checks, not a live Google generation. Evidence: `artifacts/gemini/runtime-configuration.json`, `runtime-adapter.json`, `default-runtime.json`, and dashboard `.data/portfolio-editor/gemini-runtime-auth.log`.

The ordinary 8443 stack remains on the independently verified Portfolio Editor image **sha256:1fae82f3b1602e2cf7a857c2d66a6dee749e166cc65f607c1c0ee7e65ab4da6b**, with AI disabled. The running isolated processes also default to AI disabled; the approved Gemini settings were exercised only in a one-shot process with a synthetic key and faked HTTP. The opt-in Gemini compose overlay has not been applied to the live local stack. No real account role was changed and no production service was deployed.

The initial candidate above had no private key and no real Google verification. The following follow-up supersedes that limitation without enabling customer access.

## Free-tier key and real supplier verification — 2026-10-03

The owner saved the key with the private helper and stated that the project uses the free tier. No billing setting was changed. A read-only model lookup returned HTTP 200 and confirmed access to `gemini-3.5-flash-lite` with `generateContent` support. Manually initiated one-shot checks transmitted only an invented Arabic bicycle-maintenance booking idea; they did not read or modify customer/project records or enable any web/worker service. The key remained a read-only secret-file mount and was never printed or copied into the repository.

The live check found a real compatibility defect hidden by the earlier HTTP fixtures: the REST `responseFormat.text.mimeType` field requires the protobuf enum `APPLICATION_JSON`. The guide's `application/json` example returned HTTP 400 `INVALID_ARGUMENT`; the rejection was repeated once to obtain a safely redacted diagnostic. The authoritative [REST enum reference](https://ai.google.dev/api/generate-content#MimeType) and real service response confirm the corrected value. A subsequent generation returned valid JSON but translated the Arabic draft into English. The adapter now states Arabic explicitly when Arabic predominates in the draft, and rejects a non-Arabic description in that case without automatic paid retries. Other input languages retain the original-language instruction. These remain suggestions for human review, not a guarantee of factual completeness or model accuracy.

The final real request returned HTTP 200 and a valid Arabic description: 230 input tokens and 236 output/thinking tokens. The application's conservative paid-tariff estimate is 659 micro-USD; that estimate is not a Google invoice or proof of a charge on the owner's free tier. Evidence, including the preceding failures and the synthetic output, is retained in `artifacts/gemini/live-provider-verification.json`. No further automatic or repeated generation is configured.

The revised provider/workflow/architecture suite passed **42 tests / 52,564 assertions**. The first run exposed a unit-fixture dependency on the separate test database's DNS alias; the configuration-only test now explicitly describes the approved topology. Production startup validation is unchanged. PHPStan/Larastan level 10, Pint, Composer strict validation and audit, source-manifest verification, and whitespace checks passed. Existing PostgreSQL tests still use the separate test database. No OpenAPI operation or schema changed.

The corrected image is `holoul-app:gemini-pilot-free-tier-verified`, **sha256:7b7de889a8e961bb6a5daa2d2bb83f3901316d47666107bb0aa341839fd1f98d**. Network-disabled packaging against the retained tested images and PHP-FPM validation passed. `compose.gemini-local.yaml` now points to this corrected image; it remains opt-in and has not been applied. The previous image/failed evidence above is retained for history.

**Status at the end of the key-verification step:** private key and real Arabic generation verified; customer-facing assistant still disabled. The prior proposed paid-project activation was not approved: the owner instead supplied a free-tier key. A free-tier local trial must use invented, non-personal, non-confidential ideas under the supplier terms. At that point, customer enablement, paid billing, and production deployment had not been approved or performed. The activation below supersedes this local enablement status.

## Owner-approved local website activation — 2026-10-03

After the free-tier terms and test-only scope were explained, the owner instructed: “الأن أريد تفعيلة على الموقع”. This authorizes the local free-tier trial, not Google billing or production deployment. Applied the Gemini overlay together with the existing local candidate compose files to all six backend processes on `https://localhost:8443`. Every process is healthy on image **sha256:7b7de889a8e961bb6a5daa2d2bb83f3901316d47666107bb0aa341839fd1f98d**. No migrations, reset, real-account changes or production deployment occurred. No Google billing setting was changed. The secret is mounted read-only outside the repository.

The original application network is internal. Added a dedicated local outbound network only to `ai-queue` so the fixed, TLS-verified Gemini endpoint is reachable from the actual worker. The app, PostgreSQL, storage and other workers retain their private networks. Startup validation reports zero violations; the driver is `gemini`, model `gemini-3.5-flash-lite`, limit 20 runs/customer/day, and the existing aggregate conservative estimate cap remains 1,000,000 micro-USD/day. That internal accounting cap does not activate billing, and supplier quota restrictions still apply.

Website build **s2hLfJyUuaJdrmiMFXCD9** now serves through the same-origin ingress from the background process on port 3101. The description assistant displays an Arabic/English free-tier notice before the optional action: fictional ideas only, no personal/confidential information, and possible Google product improvement/human review. Authenticated eligible customers can use the existing description improvement action; guest AI and document analysis are not enabled by this batch. The key is never included in frontend settings.

Real Chrome verification used the existing synthetic customer: login → saved profile → taxonomy → Arabic fictional draft → explicit Improve Description → one HTTP 202 AI creation → durable worker generation → suggestion displayed → original unchanged → explicit Use This Version updated the draft. The run `01a10271-7955-7308-b350-bcef6671286d` is confirmed in PostgreSQL as `provider=gemini`, `model=gemini-3.5-flash-lite`, `state=succeeded`, `reservation_state=settled`, 223 input tokens, 277 output/thinking tokens, estimated cost 760 micro-USD. Only a synthetic draft was created; no project request was submitted. Evidence: `artifacts/gemini/live-activation-state.json` and website `.data/gemini/live-website-initial-result.json`.

The browser script's first final logout assertion ran before the asynchronous logout completed; it is preserved as a failed test result rather than relabeled a complete pass. The assertion now waits for the completed 401 result. A separate real-browser login/logout rerun passed, sending zero additional AI requests (`.data/gemini/live-auth-result.json`). No identity behavior or session security was modified.

Activation validation: 28 focused website tests across five files passed, TypeScript/scoped ESLint/formatting and production build passed, authoritative OpenAPI source verification and whitespace checks passed, readiness HTTP 200 and all six runtime health checks passed. The normal Windows `Start-HOLOUL.ps1` launcher now starts/checks the website on 3101 as well as the dashboard and existing Docker containers; its warm-start check passed. It preserves the enabled Gemini container configuration on subsequent normal starts. A prior launcher copy is retained as `Start-HOLOUL.before-gemini-20261003.ps1`.

**Current status: ENABLED ON THE LOCAL WEBSITE FOR THE APPROVED FREE-TIER TRIAL.** Sign in with an eligible customer account at `/submit-idea`, enter a description in the project-details step, and choose Improve Description. Human application remains required. Production and real confidential customer-data use remain outside this approval.

Sources reviewed: [model](https://ai.google.dev/gemini-api/docs/models/gemini-3.5-flash-lite), [API reference](https://ai.google.dev/api/generate-content), [structured output](https://ai.google.dev/gemini-api/docs/generate-content/structured-output), [pricing](https://ai.google.dev/gemini-api/docs/pricing), [key security](https://ai.google.dev/gemini-api/docs/api-key), [provider terms](https://ai.google.dev/gemini-api/terms).
