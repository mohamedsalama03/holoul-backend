# B3 verification evidence

The complete inherited B1+B2 gate plus B3 passed locally on **16 September 2026**. The final `bash scripts/verify.sh` run started at **18:13:27 UTC**, finished at **18:24:04 UTC**, and exited **0** with `All B1, B2 and B3 verification gates passed.` The wrapper retained pipeline failures and recorded start, finish and exit status in `artifacts/b3-gate-*.txt`; complete output is in `artifacts/b3-full-gate.log`.

## Baseline and review boundary

The approved baseline and required parent of this one B3 commit is **`8d7baa66da9f56f2b6cb26b09e0ad4268203fa14`** on `main`. Its parent is `a8881df992758e765c4a9d1f8b360b6f7ef71b79`. The baseline tree was clean and no remote or `origin/main` was configured; see [the recorded baseline](B3-BASELINE.md). The final commit identity, parent, diff statistics and clean-tree result are reported with the handoff; they can also be inspected with `git show --format=fuller --stat HEAD` and `git status --porcelain=v1`.

B0, all eight B1/B2 migration files, Composer manifests/lockfile and the Dockerfile are unchanged. The historical B1-to-B2 upgrade still executes exactly its five additions and checks its original eight-migration/seven-permission state. B3 separately hash-checks all eight approved B2 migrations before building its upgrade fixture. Architecture guards explicitly add only approved B3 modules, tables and routes.

The batch implements Categories and Project Intake, their authorization/contact contracts, additive database constraints, exact-money support, APIs, tests and operational verification. Discovery is an intake state boundary only. Documents, AI, Discovery domain records, Proposals, Projects, expanded notification delivery and Reporting remain outside this batch. [Implementation and API catalog](B3-IMPLEMENTATION.md) and [authorization matrix](B3-AUTHORIZATION.md) describe the reviewable behavior.

## Final quality results

| Check | Final result |
|---|---|
| Unit and feature PHPUnit suite | **430 tests, 2,321 assertions**, 280.111 seconds; no failures, errors or skipped tests |
| Inherited B1/B2 unit and feature cases | **251 tests, 1,098 assertions** retained |
| Added B3 unit and feature cases | **179 tests, 1,223 assertions** |
| Architecture suite | **9 tests, 15,840 assertions**, 0.355 seconds |
| PHPStan/Larastan level 10 | No errors; no new suppression or baseline |
| Pint | **229 files**, pass |
| Composer | Strict validation, locked install, platform checks and locked audit passed; no vulnerability advisories |
| Fresh migrations | All **12** applied; repeated migrate calls were no-ops; final fresh rebuild passed |
| Exact B2-to-B3 upgrade | **1 test, 114 assertions**, included above |
| Docker | Compose configuration, development build and production runtime build passed |
| Cached configuration and route catalog | Passed; **38 added routes / 68 total**; GET/HEAD pairs count once |
| Production runtime | App, queue and scheduler used the identical release image, production mode, debug false, no testing dependencies |
| Actual HTTPS identity smoke | All **16** inherited checks passed |
| Actual HTTPS intake smoke | All **13** B3 checks passed |
| Ingress regression | **100** requests; **58** safely rate-limited; all responses were 200 or 429 |
| Source secret scan | Exit **0**, no secret findings |
| Five runtime image scans | Every scan exited **0**, reporting **0 HIGH / 0 CRITICAL** |

PHPUnit class accounting for the additions, as printed from JUnit in `artifacts/quality.log`:

| Class (namespace abbreviated) | Tests | Assertions |
|---|---:|---:|
| Unit / MoneyTest | 34 | 64 |
| Authorization / IntakeAuthorityTest | 20 | 80 |
| Categories / CurrencyCatalogTest | 5 | 10 |
| Categories / TaxonomyConcurrencyTest | 2 | 14 |
| Categories / TaxonomyHttpValidationTest | 1 | 55 |
| Categories / TaxonomyTest | 25 | 71 |
| Customers / CustomerContactReaderTest | 4 | 30 |
| ProjectIntake / IntakeConcurrencyTest | 4 | 94 |
| ProjectIntake / IntakeConstraintsTest | 20 | 171 |
| ProjectIntake / IntakeHttpTest | 45 | 366 |
| ProjectIntake / IntakeWorkflowTest | 17 | 139 |
| ProjectIntake / MigrationUpgradeTest | 1 | 114 |
| ProjectIntake / ReadConcurrencyTest | 1 | 15 |

The competing amendment/withdrawal test checks the effects of whichever valid action wins, so an earlier passing run had two additional assertions. The table records this final run's actual counts, not an estimate or sum of repeated runs.

## Migrations and database integrity

Four additive migrations introduce **13 tables**, bringing the migration count to 12:

| Migration | Added scope |
|---|---|
| `2026_09_16_020000_create_currencies.php` | Read-only USD/LYD catalog with exponents 2 and 3 |
| `2026_09_16_020100_create_categories.php` | Categories/subcategories, parent consistency, stable identities and version guards |
| `2026_09_16_020200_add_intake_permissions.php` | Eight explicit permission codes/grants; 15 permission codes total |
| `2026_09_16_020300_create_project_intake.php` | Requests, drafts, revisions, assignment/information/state history, submission keys and passive notification intents |

The exact upgrade test checks baseline migration hashes, constructs the approved B2 schema, retains representative identity/profile/session/role/recovery/MFA/audit/async data and privileges, applies only the four B3 migrations, checks all 13 new tables and verifies a subsequent no-op. The local runtime database also reports all eight previous migrations in their original batches and all four additions in batch 3.

Direct PostgreSQL tests independently reject mismatched customer/user ownership, taxonomy parent mismatches, invalid budgets including nullable-boolean loopholes, incorrect latest-revision ID/number linkage, invalid actor personas and foreign submission-key relationships. Revisions, assignments, questions, responses, resolutions, state history and notification intents reject UPDATE, DELETE and TRUNCATE. Runtime catalog grants remain restricted. Audit failure rolls back the business mutation, history, intent and idempotency result together.

Submission uses integer minor units and API decimal strings, immutable contact/taxonomy snapshots and a PostgreSQL sequence for immutable first-submission references. Idempotency claims precede effects in the same transaction. Authenticated text intake still commits safely when real Redis connections fail; its durable state and passive intent are held in PostgreSQL.

## Concurrency and isolation evidence

B3 adds **six independent-process race tests** and **four further tests using separate PostgreSQL connections**. Process tests observe blocked workers through `pg_stat_activity`, clear the statistics snapshot before polling and release controlled row-lock barriers; bounded timeouts prevent indefinite waits. These are real PostgreSQL races, not mocked lock expectations.

The four `IntakeConcurrencyTest` races establish distinct sequence references for different customers; one committed revision/receipt/intent/history/audit effect for simultaneous same-key submissions; one winner and one stale-write rejection for submit versus edit; and one valid outcome for amendment submission versus withdrawal while preserving revision 1 and the request reference. These workers call domain actions directly; their worker success code is separate from the API's 201 submission status.

The taxonomy process race commits exactly one version and one update audit when two edits compete. A separate-connection selection test permits shared readers while blocking both category and subcategory deactivation until snapshot commit. An additional query-interleaving regression rechecks parent activity between taxonomy queries; it is not counted as an independent-process race.

The private-read process race starts reassignment after the scoped parent read but before child queries. Reassignment remains blocked until the authorized revision read and audit transaction commits, then increments one version and appends one assignment audit. The former assignee subsequently receives 404. The same shared request-lock mechanism protects the API's child-read paths.

Two separate-connection Identity tests block user disablement/authentication-generation changes/session deletion during the authorized callback and block candidate disablement during assignment eligibility checking. The contact snapshot test blocks name/phone changes while snapshot rows are locked and verifies the returned snapshot does not change afterward.

The 45 intake HTTP tests use the B2 real encrypted cookie, persisted session, CSRF and MFA paths. They cover direct/reference/nested/list/search/revision/information isolation, malformed UUIDs, forged owner/contact/state/reference fields, staff permission combinations, hidden unpublished drafts, versions, exact amounts, error envelopes and bounded query counts. Neither `actingAs` nor disabled authentication middleware substitutes for those HTTP boundaries.

## Production smoke and image identities

Seven long-running local services finished healthy. Only Nginx publishes loopback ingress ports; PostgreSQL, Redis, PHP-FPM and SMTP remain private. Inherited liveness/readiness/API headers, the oversized JSON 413 check and the private-query log-leak check passed. The actual scheduler → Redis → worker → PostgreSQL probe passed for operation `01a0ab74-818c-70bc-a79b-5e5f2708698d`.

The B3 HTTPS smoke trusted the generated local certificate and used four randomly credentialed identities, actual sandbox verification mail, real session cookies and staff TOTP. Its 13 checks cover four identity flows; taxonomy authorization/versions; two exact USD/LYD draft/submission/replay flows; bidirectional customer isolation; immutable original snapshots and an amendment; assigned staff visibility; clarification/response/acknowledgement/Discovery boundary/withdrawal; rejection; and taxonomy deactivation without historical mutation. Ordinary 200 responses are checked for absence of redirect Location headers, while 201 responses retain their resource Location.

The final B2 cleanup removed its three temporary users and retained append-only audit. The final B3 cleanup disabled all four synthetic accounts, removed all fixture roles and sessions, and left zero nonterminal fixture requests. It retained two terminal requests, three immutable revisions, two assignments, one question/response/resolution, nine state changes, three passive intents and 56 audit events. Earlier runs also retained disabled synthetic identities and immutable history according to the same rules. No immutable guard was disabled to clean fixtures; credentials and token files were removed from the bounded temporary directory.

| Image | Docker Engine image identity | HIGH / CRITICAL |
|---|---|---|
| Application B3 | `sha256:80f20a1e3fd78acfce1df1894904c818d2686565fb6b4cd0199e704027a59ac4` | 0 / 0 |
| PostgreSQL B1 runtime, rebuilt unchanged | `sha256:cfe00fe99ffc60fe87e617806d2d9913764e4b4348eb3d6160a70af364bdb557` | 0 / 0 |
| Redis B1 runtime, rebuilt unchanged | `sha256:d265f6a6c32b0c7a992e207f6d0647cb8629324e27f00ecd21142776c66e7a8f` | 0 / 0 |
| Nginx 1.30.5 Alpine | `sha256:73c75df4075c918f91017fdda46ad81e55e5af77ba3a64ca3d5014bd9244fe7f` | 0 / 0 |
| Mailpit 1.31.1 | `sha256:98b916bd3c8d61f7633a52d3ea2f58d00620cb01ca57ab59edde68c347a95365` | 0 / 0 |

These identities can identify OCI manifest indexes; they are not all configuration digests. The application build configuration digest is `sha256:afece2c6a543301accb0f3c516e38d05bf2b382b00abedbbb5c71d81aa25ca49`. App, queue and scheduler image equality was checked in the gate. PHP 8.4.25, PostgreSQL 18.6 and Redis 8.2.9 were observed; no Composer dependency or runtime-container addition was introduced.

Scans used pinned Trivy 0.74.0 (`sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969`), normal database freshness checking, and no vulnerability ignores, unfixed exclusions or skipped updates. Database UpdatedAt was `2026-09-16T07:08:16.324557942Z`, NextUpdate `2026-09-17T07:08:16.324557561Z`. Source secret scanning excludes vendor/artifacts/.git; production image scans include installed production dependencies. Dated zero HIGH/CRITICAL findings do not claim all severities or future advisories are absent.

## Corrected findings and remaining limits

Review and verification corrected PostgreSQL nullable-budget checks and latest-revision number linkage, held staff read authorization through child reads, rejected numeric-string taxonomy orders, and attached the reference sequence to its owning table so inherited `migrate:fresh` is repeatable. That sequence issue caused an earlier full preflight failure; the later complete suites passed.

The first complete-gate attempt stopped when the smoke tried to redeem a staff verification token after MFA enrollment had changed its authorization generation. B2 correctly rejected the stale token; the smoke now verifies before enrollment. A focused HTTPS run then exposed a real production response defect: Location on an ordinary 200 became a FastCGI 302. Only creation responses now carry Location, and HTTP plus actual production regressions cover it. The [Nginx FastCGI implementation](https://raw.githubusercontent.com/nginx/nginx/release-1.30.0/src/http/modules/ngx_http_fastcgi_module.c) explains the fallback when Location is present without an explicit upstream status.

The next full run passed every code test but stopped at a temporary 503 after switching application images while Nginx retained its old upstream address. Verification now recreates Nginx both to refresh a changed single-file mount and during release activation. It passed the subsequent readiness check and the entire final gate. Earlier failed runs are retained as `artifacts/b3-attempt-1.log` and `b3-attempt-2.log`; they are not presented as complete passes. Strict expected HTTP statuses remain in place; the smoke pacing prevents the 10-request/second edge limit from replacing a policy assertion with 429, without retrying that response.

The final scans retain inherited metadata warnings: Alpine 3.24 is absent from Trivy's EOL table although the correct 3.24 vulnerability repository is used; some severities come from other vendors; PostgreSQL's scan lacks details for `CVE-2026-80256`. The [upstream curl advisory](https://curl.se/docs/CVE-2026-80256.html), checked on 16 September, describes a Medium-severity Windows-only wcurl issue, assessed as outside these Linux containers. This does not claim the older libcurl package was patched. [Trivy's detail-lookup fallback](https://raw.githubusercontent.com/aquasecurity/trivy/v0.74.0/pkg/vulnerability/vulnerability.go) can retain or substitute unknown severity, so the zero HIGH/CRITICAL count alone does not settle that record. No warning was hidden or suppressed.

No unresolved B3 functional defect was observed in the completed checks. This is local production-mode verification, not external deployment acceptance: hosted CI, real external SMTP, production database TLS, public certificates, trusted proxy configuration and frontend integration were not exercised. B1/B2 operational requirements remain, including key rotation, time synchronization, monitoring, backup/restore drills, privacy retention and load-based tuning. The ingress rate and lock contention need deployment-specific measurements. Expired submission keys are removed lazily on reuse; bulk retention is future operational work. Passive notification intents do not imply delivery, and retained immutable synthetic history requires the same controlled retention policy as other domain history.

The evidence document was written after the passing build and gate; application code was not changed afterward. Final source secret scanning also covers the completed evidence document before the single B3 commit.
