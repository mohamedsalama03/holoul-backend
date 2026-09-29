# F4 intake display — verification and local handoff

**BACKEND FEATURE READY FOR DASHBOARD INTEGRATION.** Verified on 29 September 2026. The five display reads are live at `https://localhost:8443`. This is a local integration candidate, not Production Ready or acceptance of a new frontend implementation. Target-VPS performance certification remains pending. No production deployment occurred.

## Candidate and contract

- Parent checkpoint: `3bc85401e96a1f8b843d1db0f13a4ae9b86572ee` (PublicPortfolio + Contact).
- Application implementation/image checkpoint: `1f3cf6eb6a2b532cc882769f59ee80096b49c926`.
- Branch: `codex/intake-display-candidate`; checkout `/home/mohamed/projects/customers/holoul-api-reference`.
- OpenAPI: **1.5.0-intake-display-candidate**, OpenAPI 3.1.1, **202 operations / 260 schemas**.
- Previous SHA-256: `7739bd957665b285c3baea8a4883aa987d6d11dee3fb43bcc5ef1ff9fb0c8051`.
- New SHA-256: `e90a431d1c662e7fb2f2eb680694678d20c294ef3a53e17dec92fcaeba87c50f`.
- Runtime image: `holoul-app:intake-display-runtime`, ID `sha256:7baed8a2772318a317ae876c76af1e2c1934b4947bf0af0d7aa6f663f4b576f7`.
- Development image: `holoul-app:intake-display-development`, ID `sha256:5dce27e5b3c20ce169aea850b36724abf97360acb600ac8fedf9fceabb58b383`.
- Source-manifest SHA-256: `f914c8178e18b13bbb95200581925c764be982ec099d1fb7409ccf8ad7d72f53`; all **514** source entries match the runtime image.

## Verification results

| Gate | Result | Evidence |
|---|---|---|
| Full PHPUnit, real PostgreSQL | **1,026 tests / 65,785 assertions**, zero errors/failures/skips; 36m43.359s | `evidence/full-phpunit.xml`, `evidence/full-phpunit.txt` |
| New display HTTP and real reassignment concurrency scenarios | **8 tests / 217 assertions**, included in the full passing invocation | `evidence/phpunit-summary.json` |
| Authenticated/guest intake, claim and migration regressions | **157 tests / 2,313 assertions**, included in full run | same summary |
| Session/concurrency regressions | **94 tests / 1,846 assertions**, included in full run; 22 inherited files byte-identical to parent | `evidence/inherited-security-tests-preserved.json` |
| Document regressions | **117 tests / 1,373 assertions**, included in full run | same summary |
| Architecture | **9 tests / 51,544 assertions**, included in full run | same summary |
| PHPStan/Larastan level 10 | PASS; no suppressed new findings | `evidence/phpstan-final.txt` |
| Pint | PASS, **666 files** | `evidence/pint-final.txt` |
| Composer strict validation, platform and locked audit | PASS; no security advisories | `evidence/composer-validate.txt`, `evidence/composer-platform.txt`, `evidence/composer-audit.txt` |
| Fresh migrations and upgrade no-op | PASS on isolated `holoul_test` using migration credentials. All 34 migration files unchanged; no migration on the shared local database | `evidence/fresh-migrations.txt`, `evidence/upgrade-noop.txt`, `evidence/preserved-boundaries.json` |
| OpenAPI and captured responses | PASS: **2,870** full-run responses; **2,919** when actual HTTPS staff responses are included; no schema/status or required-coverage failures | `evidence/contract-final.json`, `evidence/contract-runtime.json` |
| Negative contract tests | **7 inherited + 5 display-specific tests** passed | `evidence/contract-negative-inherited.txt`, `evidence/contract-negative-display.txt` |
| Compatibility and route/spec drift | 202 routes matched, none missing/undocumented; 197 operations exact, five opt-in expansions; all 250 prior schemas unchanged | `evidence/contract-compatibility.json` |
| Docker development/runtime builds and bootstrap | PASS; FPM configuration, required extensions, autoload and module binding valid; runtime excludes development tools and local fixture runner | `evidence/build-runtime.txt`, `evidence/build-development.txt`, `evidence/runtime-image-verification.json`, `evidence/candidate-bootstrap.json` |
| Coherent local activation | All six application/worker/scheduler services use the same new image and are healthy; readiness, public categories and dashboard login return 200; Nginx configuration valid | `evidence/local-runtime-final.json`, `evidence/nginx-check.txt` |
| Real same-origin HTTPS | **69 checks** passed; customer ID link, five views, default payload equality, guest claim/provenance, typed actors, scoped directory denial and cookie behavior | `evidence/runtime-https.json` |
| Existing frontend F1 + Overview + F4 | **8/8 passed**, zero skipped/flaky/retried, 131.715s, existing production build | `evidence/frontend-final.json` |
| Frontend preservation | Dashboard and website tracked file contents unchanged during the batch; both contract copies unchanged | `evidence/frontend-preserved.json` |

The inherited and new subsets overlap; their counts must not be added to the full-run total. Tests ran against an isolated PostgreSQL/Redis pair; only explicitly reserved synthetic identities were used for the shared-stack HTTPS/browser checks. The real HTTPS check created two requests and rejected both, retaining immutable history. The frontend F4 run retains its own closed synthetic request under its existing cleanup policy. No request-reference range or historical draft was deleted.

The frontend regression uses the existing dashboard build and current test files at HEAD `2c916fa437b8214d922eb46230ef59f0b4773780`, with five pre-existing modified paths. Its pinned contract remains `af3c96ac459717fb3ab4c86ac68513d4516e843e410de36820af2e188eded43d`. The website contract remains `7739bd957665b285c3baea8a4883aa987d6d11dee3fb43bcc5ef1ff9fb0c8051`. The new UI display behavior is for the frontend team to integrate using the new contract; the eight passing tests prove existing UI regression, not adoption of the new fields.

## Preserved guarantees and scope

- Ordinary/stale reads do not issue replacement session cookies. Session, login/MFA, logout and CSRF implementations are unchanged. New real HTTPS ordinary reads and guest calls emitted no Set-Cookie.
- Request visibility, draft exclusion, assignment locks and grant policy remain unchanged. No arbitrary staff-name endpoint or additional staff-directory permission exists.
- Account IDs retain their meaning. Canonical request ownership supplies the distinct customer directory ID, including after a guest claim.
- Guest history is resolved from original submission provenance, not the latest revision's submitter. Disabled historical identities remain named. Display names are current labels, not historical name snapshots.
- Default staff/customer responses and all commands keep their existing shapes and ETags. `view=dashboard` is explicit, staff-only and rejected on customer reads/commands. The enriched list uses bulk reads rather than per-row lookups.
- All prior B8/P1/P2/P3/F1-E1/G1, identity, staff, customer-sort and PublicServices evidence remains in history and on disk. No squash or Production Ready tag. Historical failed local performance results (p95 662.590/699.075ms) remain preserved; this batch does not certify VPS performance.
- PublicPortfolio, Contact, launch taxonomy, B8 exclusion approval boundaries and pending message retention remain unchanged. No schema/grant migration, automatic redaction activation, legacy cutover or frontend modification.

## Failed/intermediate attempts retained

- Initial PHPStan found strict list/stdClass/index typing errors; corrected without suppressions. Final level-10 check passed.
- First focused run had test-setup errors: a reviewer was asked to reject without the relevant grant, and logout was incorrectly expected to return 204 instead of the existing 200. The second attempt still omitted the required review transition before rejection. The tests were corrected to exercise the existing authorized workflow; application grants and transitions were not relaxed. The final complete invocation passed all eight new scenarios.
- Two formatting invocations failed because `--dirty` requires Git inside the verification container and one helper path was mistyped. Corrected invocations and the complete Pint check passed. Existing console-helper import ordering was normalized without behavior change.
- Intermediate outputs remain in `evidence/`; raw authentication responses and fixture credentials remain in ignored private artifacts and are not exported.

## Local rollback and handoff

There is no schema rollback. To return to the immediate parent locally, use the preserved `compose.public-local.yaml` override and recreate the same six application/worker/scheduler services with `holoul-app:public-services-runtime` (image `sha256:576eac4e84397946f47004059d5a0512a08923cb757727fc8c97c94522909079`). Keep the existing volumes and database; do not run initialize or migrate:fresh on the shared stack. Recheck readiness and the dashboard origin. This rollback procedure was not invoked because the candidate passed.

`HANDOFF.md` answers R1–R4 and N1–N3 individually. `response-examples.json` contains twelve actual synthetic staff responses, without authentication/capability secrets. The portable Swagger package includes local assets and needs no server at port 8876. It is checked structurally and for JSON/YAML/hash consistency; no browser visual preview is claimed.
