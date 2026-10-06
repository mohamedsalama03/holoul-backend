# Frozen production image publication

This operational branch adds a manual publication workflow. It does not authorize
deployment, change the frozen application, or change the production topology.

Application source: `8b1ffea5c75dc4adb68f795a73d1c6c513cbbd8c` on
`release/unified-vps-candidate`. The workflow/tooling commit is separate and is
recorded as `workflow_sha` in every image evidence record and OCI label.

OpenAPI remains `1.8.1-gemini-documents-candidate`, 204 operations / 265 schemas,
SHA-256 `3c93a09f08ce199cc1c2867ad846a1ca554fd03b6d934070178c5101646df410`.

## Images and build contexts

All six builds use a separate clean checkout of the required full source SHA,
never the workflow checkout. The separately versioned `packaging.json` applies
only the authorized exact Alpine package-pin substitutions to a temporary
Dockerfile outside that checkout. Only `linux/amd64` is executable; OCI attestation
descriptors marked `unknown/unknown` are metadata, not additional platforms.

| Package under `ghcr.io/mohamedsalama03/` | Frozen Dockerfile | Target | Production Compose user |
| --- | --- | --- | --- |
| `holoul-backend` | `Dockerfile` | `runtime` | `1000:1000` |
| `holoul-postgres` | `docker/postgres/Dockerfile` | final | `70:70` |
| `holoul-redis` | `docker/redis/Dockerfile` | final | `999:999` |
| `holoul-storage` | `docker/documents/storage/Dockerfile` | final | `1000:1000` |
| `holoul-portfolio` | `docker/portfolio/Dockerfile` | final | `1000:1000` |
| `holoul-nginx` | `docker/nginx/Dockerfile` | final | `101:101` |

These are intended package names, not a list of published images. Actual image
users are inspected independently of Compose overrides. In particular, an
inherited root image default for PostgreSQL/Redis must not be reported as the
effective production process user.

## Publication controls

1. Manual `workflow_dispatch` only; empty, abbreviated, branch-name, and other
   source inputs are rejected. The repository and GitHub-hosted runner are checked.
2. All builds run sequentially on one GitHub-hosted Ubuntu 24.04 runner, retaining
   their OCI layouts privately until **all six** security/runtime gates succeed.
   Buildx, BuildKit, actions, the SBOM generator, Skopeo and Trivy are pinned.
   Base identities and existing patches remain intact; authorized package pin
   changes are isolated in the packaging manifest, not the frozen source files.
3. BuildKit exports one OCI layout locally on the runner, with minimal provenance
   and an SPDX SBOM. No registry or production credentials are supplied to the
   build. No external build cache or image archive is uploaded as a GitHub artifact.
4. Inspection validates every referenced blob's digest and size, one executable
   platform, source/workflow labels, expected non-root users where specified by
   the Dockerfile, and attestation subjects. It records ports and entrypoint/Cmd.
   The backend uses the existing runtime-stage allowlist assertions.
5. The same Trivy `0.74.0` identity as the frozen verification script scans the
   final image for HIGH/CRITICAL OS and library vulnerabilities. No ignore list,
   unfixed exclusion, suppression or allow-to-fail is added. Scanner failure also
   blocks publication. Database metadata is retained.
6. Secret checks cover image config/history and every final-image layer's regular
   files, including base layers and files subsequently deleted by another layer.
   Links/devices are not materialized during extraction. All built-in secret
   detection rules remain enabled. Raw reports and matched secrets stay in the
   runner's private temporary directory; artifacts contain only sanitized findings.
7. Only after all six successful gates does a step receive native `GITHUB_TOKEN`.
   The publishing command independently rechecks all six gates, packaging/run
   identities and layout digests, then all six package visibility/access checks,
   before the first registry write. Its
   auth file stays outside both checkouts, is supplied to Skopeo via a private
   file, and is removed in `finally`. Job permissions are `contents: read` and
   `packages: write`; other jobs only receive `contents: read`. No PAT is used.
8. Skopeo copies the original OCI output using `--all --preserve-digests`; there
   is no second build or conversion before publication. A unique navigation tag
   contains the source prefix, run ID and attempt. No `latest` tag is published.
9. The complete image and attestations are pulled back by registry digest and
   every blob is revalidated. A successful tag push alone is insufficient.
   Existing packages must be private and linked to this repository. Missing
   preflight metadata may allow a first publication; privacy/linkage must then
   be verified after publication. Any visibility/permission failure blocks the
   final verified status, even if bytes already reached GHCR.
10. Only six successful image records from the same run, attempt, source and
    workflow can produce the final inventory. Partial success does not produce
    a deployable inventory. GitHub artifacts retain sanitized per-image evidence
    for 30 days and the successful combined inventory for 90 days.

Provenance/SBOM are OCI-attached build metadata, **not signed GitHub attestations**
or an assertion of production certification. No OIDC/attestation-write permissions
are requested. Deployment consumes only `repository@sha256` references. The
reported top-level registry digest binds the single amd64 image and attestations;
the runtime manifest and config digests are also recorded. Sizes distinguish
compressed runtime layers, registry blobs including metadata, and uncompressed
regular-file bytes across all layers.

## Invocation and evidence

After GitHub permits dispatch of this workflow, invoke it with:

```sh
gh workflow run publish-production-images.yml \
  --repo mohamedsalama03/holoul-backend \
  --ref ops/production-image-publication \
  -f source_sha=8b1ffea5c75dc4adb68f795a73d1c6c513cbbd8c
```

Download `production-image-inventory-<attempt>` only from the reviewed successful
run. It contains `production-images.env` with exactly the six `HOLOUL_*_IMAGE`
variables and `production-images.json` with source/workflow/registry evidence.
There is deliberately no checked-in example using invented release digests.

The final job applies the six real identities to the **unchanged frozen**
`compose.production.yaml`, runs its existing static validator and checks every
service's resulting image/user. Website/dashboard digest placeholders and SMTP
`.invalid` values exist only in a temporary static-test fixture. They are never
included in the release inventory, used to start services, or presented as valid
production settings. SMTP and frontend acceptance remain deployment blockers.

`.github/workflows/ci.yml` is byte-for-byte preserved and still runs on ordinary
pushes/PRs. Its results remain separate evidence; this workflow grants no waiver
for existing CI failures, B8 performance certification, or integration acceptance.

## GHCR visibility and VPS authentication

The repository is public. That does not establish the visibility of an individual
container package. The intended policy is **private** and publication verifies
each package through the GitHub API. GitHub documents that a new container package
is private initially. An existing public package is stopped for review; this
workflow never changes repository/package visibility or permissions.

Git SSH deploy keys authenticate Git operations, not GHCR pulls. Outside Actions,
private GHCR pulls require an account with access to the packages and a separate
classic PAT with the minimum `read:packages` scope (and SSO authorization if
required by the account/organization). Repository association can grant access
to Actions' `GITHUB_TOKEN`; it does not make a VPS Git deploy key a registry
credential. No VPS token is created, requested, printed or installed here.

## Verification commands

```sh
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover \
  -s ops/image-publication -p 'test_*.py' -v
actionlint .github/workflows/publish-production-images.yml
git diff 8b1ffea5c75dc4adb68f795a73d1c6c513cbbd8c -- \
  .github/workflows/ci.yml Dockerfile docker compose.production.yaml docs/openapi.json
```

Tests use explicitly synthetic OCI/record fixtures and never publish them.
Local transport/secret/static-Compose smoke checks validate the tooling, not the
six production images. Actual image builds and current vulnerability results can
only be claimed after the GitHub-hosted publication jobs run successfully.

Review evidence on 2026-10-06 before the operations branch push:

- 26 Python guard tests passed, covering changed/corrupted identities, platform,
  modern and legacy attestation layouts, wrong runtime user, secret redaction,
  layer extraction, missing/failed/mixed-run publication evidence, and build target.
- Actionlint 1.7.12 passed syntax/context validation. Its download was verified
  against GitHub's asset SHA-256. Optional external ShellCheck was unavailable.
- A tiny **synthetic** local BuildKit fixture produced real SPDX and provenance v1
  attestations. Skopeo preserved all manifest digests through an OCI round trip;
  pinned Trivy successfully read and secret-scanned the resulting OCI layout.
  No HOLOUL production image was built locally, and nothing was pushed by this test.
- A transient synthetic private key caused Trivy to fail with exit code 1; the
  safe report omitted matched content. That key and its raw report were deleted.
- Static frozen-Compose fixture validation passed: 13 runtime / 2 preparatory
  services, 2888 MiB resource-limit sum, only public ports 80/443. This is not a
  runtime/capacity result and does not certify the 4 GB VPS.
- New operations files passed a Trivy secret scan. The frozen Dockerfiles,
  application, CI workflow, OpenAPI and production Compose are unchanged.
- Initial lint exposed a disallowed `runner.temp` expression in job-level env;
  it was corrected to read `RUNNER_TEMP` inside the script. The initial BuildKit
  smoke exposed modern OCI empty-config attestations and unnamed outputs with
  empty subjects; the parser now supports the artifact format and the build
  supplies its navigation name before export. Both checks passed after correction.
- The existing remote application CI failure below remains recorded. PHP/Pint/
  Larastan/PostgreSQL/Composer suites were not rerun locally for these operations-
  only files; no new application verification or production-readiness claim is made.

## Known blockers found before initial dispatch (2026-10-06)

- The repository default branch is `release/g1-vps-candidate`. GitHub documents
  that a `workflow_dispatch` definition must exist on the default branch before
  it can be manually triggered. The new definition is isolated on this operations
  branch. No default/release branch is changed to work around that restriction.
- Existing CI on the exact frozen source failed on 2026-10-04:
  [run 37232918843](https://github.com/mohamedsalama03/holoul-backend/actions/runs/37232918843).
  Redis's reviewed Dockerfile requests `libcrypto3=3.5.8-r0` and `libssl3=3.5.8-r0`;
  the runner's Alpine repository offered `3.5.9-r0`, producing
  `ERROR: unable to select packages`. This is direct remote build evidence, not
  a vulnerability finding. No pin has been relaxed or candidate rewritten.
- Local GitHub CLI credentials cannot list packages: the API returned HTTP 403
  requiring `read:packages`. No package visibility or existing package absence
  can be claimed from that result. Per-package Actions checks remain necessary.

These are retained historical findings. The subsequent remediation authorization
permits the narrowly scoped packaging changes below and default-branch workflow
registration. Neither changes the frozen application's accepted identity.

## Authorized publication remediation

The user authorized adding only the reviewed workflow file to the default branch
`release/g1-vps-candidate`. No merge from the unified candidate is required. The
workflow is dispatched using the **operations branch**; its first guard rejects
execution from the default branch itself. Both the tooling and packaging SHA
are `github.workflow_sha`; `org.opencontainers.image.revision` still names the
frozen application SHA. The default registration commit is recorded separately
in the final publication report.

Official Alpine repository checks executed in the pinned amd64 base images on
2026-10-06 found:

| Image / repository | Package | Frozen pin | Available / effective pin |
| --- | --- | --- | --- |
| Redis / Alpine 3.22 | libcrypto3 | 3.5.8-r0 | 3.5.9-r0 |
| Redis / Alpine 3.22 | libssl3 | 3.5.8-r0 | 3.5.9-r0 |
| Redis / Alpine 3.22 | setpriv | 2.41.6-r1 | unchanged |
| PostgreSQL / Alpine 3.24 | libcrypto3 | 3.5.8-r0 | 3.5.9-r0 |
| PostgreSQL / Alpine 3.24 | libssl3 | 3.5.8-r0 | 3.5.9-r0 |
| PostgreSQL / Alpine 3.24 | libuuid / gosu | 2.42.3-r1 / 1.19-r5 | unchanged |
| Nginx / Alpine 3.24 | libexpat / pcre2 | 2.8.5-r0 / 10.49-r0 | unchanged |

Only those four OpenSSL token replacements are authorized in `packaging.json`.
The helper first verifies the frozen Dockerfile SHA-256, requires an exact single
match, restricts replacement syntax to approved package/version pins, and rejects
command injection or a package series change. It records original/effective
Dockerfile hashes and packaging manifest/commit identity with the image evidence.
Redis remains 8.2.9 with the same base digest and entrypoint.

Before any publish, each exported image is loaded for a runtime/version check,
with its loaded config digest matched to the scanned OCI config. Redis additionally
must pass TLS startup, authenticated PING and write/read, anonymous/wrong-password
rejection, wrong-CA rejection and plaintext rejection. Synthetic credentials are
generated only after building, never enter image layers or artifacts, and are
removed after this network-isolated test. Expected package versions are checked
inside the resulting Redis and PostgreSQL runtimes.

The remediation has 33 passing guard tests, including the invariant that a missing,
failed or changed image prevents **every** registry write. The Redis smoke helper
also passed locally against the original pinned base; that helper check is not
a substitute for the remediated release image's GitHub-hosted security/runtime gates.
Raw OCI images remain private on the runner and are never uploaded to this public
repository's Actions artifacts. Only sanitized evidence and a verified inventory
are uploaded. A failed release gate produces no registry push and no inventory.

## Primary references

- [GitHub manual workflow requirements](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow)
- [GHCR authentication, package linkage and first-publication visibility](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry)
- [Docker OCI exporter](https://docs.docker.com/build/exporters/oci-docker/)
- [Docker attestations](https://docs.docker.com/build/metadata/attestations/)
- [Skopeo copy and digest preservation](https://github.com/podman-container-tools/skopeo/blob/main/docs/skopeo-copy.1.md)
- [Trivy secret scanner and base-layer limitation](https://trivy.dev/docs/latest/guide/scanner/secret/)
