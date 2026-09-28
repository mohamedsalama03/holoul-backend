# HOLOUL API reference

<!-- impeccable:product-schema 1 -->

## Platform

web; portable static documentation for the existing backend.

## Product purpose

The product owner requested a professional Swagger edition of the API contract. Preserve the authoritative OpenAPI data and make it easy to inspect and share.

## Users

Inferred from the existing dashboard/backend handoffs: frontend and backend developers integrating HOLOUL. They need operation paths, access requirements, inputs, responses and downloadable contract data.

## Constraints

- Source is `../openapi.json` at candidate baseline `e3957df723ea01a6005feaec90d8130a26c93b62`.
- This is an integration candidate; production performance certification remains pending the target VPS.
- Preserve the API contract, session security guarantees, existing dashboard source and its contract copy.
- Use the existing HOLOUL brand mark. Swagger UI is explicitly requested; no new product visual identity is being designed.
- Include assets locally, avoid remote validators, and never ship account credentials or mail/session tokens.

## Working assumption and open decision

The optional question about live request execution has no answer at build time. Reference-only mode is the stated default, not a durable user preference. Operation inspection and downloads work offline. Authenticated execution would need a separately authorized, same-origin integration; it is outside this portable edition.
