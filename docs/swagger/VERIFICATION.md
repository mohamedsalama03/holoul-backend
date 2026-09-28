# Swagger reference verification

Date: 28 September 2026. Scope: portable documentation; this report does not certify production readiness.

## Contract integrity

- Authoritative candidate: `e3957df723ea01a6005feaec90d8130a26c93b62`.
- JSON SHA-256: `fafbc8b62976788dae0cc98492add8bed014228ad42844d2c7498ca57d1a7f4e`.
- JSON download is byte-identical to `docs/openapi.json`; YAML is structurally identical after parsing.
- OpenAPI 3.1.1 validation passed in the existing pinned `holoul-contracts:b8-p3` validator with network disabled.
- Existing generated-source check passed: 182 operations. All 3,004 internal `$ref` occurrences resolve.
- No operation, schema, response, parameter, server or security definition changed for this reference.

## Browser checks

Chromium 153.0.8010.12. Sixteen checks passed; machine-readable results are in `verification.json`.

- All 182 operations and 221 schema models render.
- Operation-ID search, combined search terms, domain filtering and empty-result recovery work.
- Customer-directory sorting documentation retains both values and the existing default.
- Operation deep links survive reload.
- No executable request controls; no browser errors or external asset/API requests observed.
- No page overflow at 390px or 320px; an expanded operation also fits the 390px viewport.
- JSON download matches the authoritative hash; YAML download is available.
- The Windows `file://` edition renders and searches offline without a server.

Desktop and mobile first viewports, full pages and settled expanded-operation captures were visually inspected. The first exploratory expanded screenshot caught a loading indicator and was replaced by settled captures. An initial browser test used the older Swagger schema selector and timed out; it was corrected to the actual OpenAPI 3.1 renderer, and all 221 schemas were then verified. These were test/capture issues, not backend changes.

## Design checks

The mechanical detector reported no regex findings but ran in degraded mode because its optional HTML parser modules were unavailable. It did not evaluate computed contrast or selector behavior. Raster provenance scan passed for the one reused HOLOUL mark; the original frontend asset was not modified.

Independent finish review requested two presentation corrections: moving candidate status below the title and narrowing long operation prose. Both were applied, the same desktop/mobile views were recaptured, and the sixteen browser checks passed again. The reviewer returned **ship**, scoring **both findings resolved**. This verdict concerns those two findings, not a fresh whole-surface accessibility audit. The final design system is recorded in `DESIGN.md`.

## Boundaries

The portable edition is a reference: search and schema expansion are interactive; API execution is disabled. It does not create or replace application sessions. CSP blocks page network connections, Swagger's remote validator is disabled, and authorization persistence is disabled. No credentials are bundled.

This change contains documentation assets and a deterministic bundle generator. It does not change PHP application code, migrations, routes, dependency manifests, the dashboard source, its contract copy or the frozen VPS candidate. Full backend, architecture, database, Pint, PHPStan, Composer and runtime integration suites were not rerun for this static documentation change; the existing C1 baseline evidence remains in `docs/C1-CUSTOMER-ORDER.md` in the repository.

After any documentation correction, regenerate `SHA256SUMS`. All delivered files must be nonempty and match that manifest.
