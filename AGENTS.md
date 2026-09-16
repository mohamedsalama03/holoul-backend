# HOLOUL engineering guidance

The user-approved architecture is docs/B0-ARCHITECTURE.md. Implement only the currently authorized batch. B1 contains infrastructure; authentication and all business domains belong to B2 or later.

Use the pinned Docker PHP/Composer runtime; host PHP installation is unnecessary. Do not add Laravel Boost, frontend tooling, or unrelated packages merely because they were present in the upstream skeleton's setup instructions.

Use PostgreSQL for all application and integration tests. Keep runtime and migration database identities separate. Never commit secrets or enable production debug. New code must pass Pint, PHPStan/Larastan level 10, PHPUnit, architecture guards, Composer audits and Docker verification.

Modules own writes. Technical infrastructure belongs in app/Infrastructure. Preserve real transactions, after-commit semantics, append-only audit and durable PostgreSQL work intent. Redis transports identifiers and is never authoritative.

Do not change the approved architecture silently. Record implementation evidence honestly, including failed or unavailable checks. Do not start another batch.
