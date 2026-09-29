# Public services candidate implementation

Owner approved development/testing on 2026-09-29 against proposal R2 at 0f3be1e4c1ec31a6faed7a4d71cfbb4a02e3003d.
Branch: codex/public-services-candidate. Application baseline: e3957df723ea01a6005feaec90d8130a26c93b62.
Baseline OpenAPI SHA-256: fafbc8b62976788dae0cc98492add8bed014228ad42844d2c7498ca57d1a7f4e (182 operations).

Scope: PublicPortfolio + Contact, 20 new operations; six named permissions granted only to super_admin/administrator. Preserve every existing operation/component and session race test. Catalogue 12/49 approved; prepare repeatable import and B8 exclusion procedure, no actual B8 deactivation without cutover review. Retention duration pending; automatic redaction disabled. Recipient info@holoul.ly. No frontend source changes, production deployment or user cutover.

Implementation and isolated verification are complete. At this implementation checkpoint, shared local 8443 still uses C1; coordinated local activation and frontend regression evidence will follow in a separate handoff commit.

Plan: approved ADR and baseline; Contact persistence/idempotency/mail/admin; portfolio editorial/publications/images and isolated processor; catalogue/import tools; 20-operation contract; focused/security/concurrency and full quality gates; fresh/exact upgrade; isolated Docker runtime and local 8443 checks; sanitised samples/report/handoff.

Existing retained evidence: docs/website-readiness/2026-09-28 and historical B8/P1 runbooks. Production performance certification remains pending target VPS.

## Verification progress, 29 September

Implemented the 20 additive routes, Contact encrypted receipt/idempotency/mail/admin workflow, immutable portfolio publications and private bounded image processing, six role grants, launch catalogue preparation and resumable legacy draft import. Added no frontend code. No existing G1/Staff operation or component changed.

Isolated checks passed: focused functionality, PostgreSQL contact/publication races, session isolation, legacy draft import/alias, exact migration 31→34, real private S3/image processor, PHPStan level 10, Composer validate/audit, and Docker runtime/development builds. Focused contract samples cover all 20 new operations with zero schema/status violations. Full inherited-suite verification and shared local switch are still in progress.

Failed evidence is retained. The first full run was interrupted after identifying empty-schema rollback guards that were too broad; down migrations now allow empty removal only and still reject retained records. A second run was interrupted after the real isolated HTTP check exposed Contact dispatch to the default queue. The publisher now follows its notifications policy, and real Redis after-commit/replay tests cover this permanently. Unit configuration checks initially used isolated DNS aliases incompatible with the exact local topology guard; explicit private /etc/hosts mappings solved this without weakening tests or the guard.

Real B8 inventory remains read-only (16 parents/16 children), and legacy inventory contains one project/four images. No B8 deactivation or legacy source cutover has occurred.

## Implementation checkpoint gate

Completed full PHPUnit: 1018 tests, 65110 assertions, one historical Staff upgrade-fixture scope failure. The fixture now explicitly targets migration 31 without weakening its count, preserved-value, repeatability or rollback checks; final Staff/PublicServices upgrade rerun passed 2 tests/220 assertions. No application source changed after the full run. Full-run and rerun evidence are both retained; this is not represented as one green full invocation. No unresolved failure remains. All 20 inherited session/guest/concurrency files are byte-identical to the proposal parent.

OpenAPI validation passed 2778 real responses, 151 success-covered operations (all 146 required), no schema/status failures or undocumented successes; 7 negative validator tests passed. Pint passed 660 files. PHPStan/Larastan level 10 and Composer validation/audit passed. Local catalogue dry-run: all 12/49 can be added, zero existing rows changed. Shared activation, encrypted local snapshot, 8443 HTTP checks and existing frontend F1/Overview regression remain the next verification steps at this checkpoint.
