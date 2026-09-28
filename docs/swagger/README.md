# HOLOUL — Swagger API Reference

نسخة Swagger احترافية من عقد HOLOUL، قابلة للمشاركة والعمل دون اتصال بالإنترنت.

## Open the reference

Extract the entire ZIP, then open **index.html** in a modern browser. Keep `assets/`, `openapi.json` and `openapi.yaml` beside it. There is no installation step or CDN dependency.

If your browser restricts local files, serve this directory with a local static server (for example `python3 -m http.server 8876 --bind 127.0.0.1`) and open `http://127.0.0.1:8876/`.

## Included

- Swagger UI 5.32.11 with searchable operations, a domain index and expandable inputs/responses/schemas.
- The exact authoritative JSON contract plus semantically equivalent YAML.
- Browser authentication guidance, candidate identity and contract hash.
- Local renderer assets, their license, provenance and a SHA-256 file manifest.

Use the search field for paths, operation IDs, summaries, HTTP methods or domains. Space-separated terms must all match. Domain selection and search combine; **Clear filters** restores all operations. Deep links use Swagger's standard operation hashes. Schema models remain available even when operation filters are active.

## Contract identity

| Field | Value |
| --- | --- |
| API version | `1.3.0-directory-sort-candidate` |
| OpenAPI | `3.1.1` |
| Operations / schemas / domains | 182 / 221 / 17 |
| Candidate baseline | `e3957df723ea01a6005feaec90d8130a26c93b62` |
| Authoritative source | `docs/openapi.json` |
| JSON SHA-256 | `fafbc8b62976788dae0cc98492add8bed014228ad42844d2c7498ca57d1a7f4e` |

This is a local integration candidate, **not production certification**. The frozen VPS candidate and website/dashboard source are outside this documentation change.

## Authentication and execution

This portable edition is **read-only**: operations expand interactively, but **Try it out** is disabled. It does not call the backend, bootstrap CSRF, authenticate, create data or store credentials. Its content security policy also blocks network connections from the page.

The application API uses same-origin HTTPS sessions. When a session is needed, obtain the CSRF cookie through `GET /sanctum/csrf-cookie`, then follow the documented login/MFA flow. The browser manages the HttpOnly `__Host-holoul_session` cookie; JavaScript must not read it. Send the URL-decoded readable `XSRF-TOKEN` as `X-XSRF-TOKEN` where the operation requires it, and refresh it after session rotations. Guest/public operations retain their individual security and capability requirements. The portable documentation origin is not an authenticated API server.

## Rebuild and verify

From the backend repository, run `python3 scripts/build-swagger-reference.py` with Python 3 and PyYAML available. It copies the authoritative JSON byte-for-byte, generates YAML and the offline embedded contract, then refreshes metadata and `SHA256SUMS`. It does not connect to application services.

After changing any bundle file, regenerate the manifest. On Linux, verify the delivered directory with `sha256sum -c SHA256SUMS`. The renderer is pinned; updating it requires checking upstream compatibility, refreshing vendor files/notices and repeating browser checks.

The generator pins the candidate baseline and source hash. For a future contract candidate, review the new contract and update that pin, the page's provenance and this guide together; a changed contract is rejected until its identity is updated explicitly.

See [VERIFICATION.md](VERIFICATION.md) for the checks actually performed on this edition, and [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md) for upstream sources and brand provenance.
