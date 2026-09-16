# Identity

B2 owns users, session authority, authentication, email verification and password recovery, staff MFA, and the initial role/permission catalog. Other modules consume the identity read contracts; they do not import Identity models or write its tables.

The first-party SPA uses Sanctum with PostgreSQL sessions, exact HTTPS origin checks and strict CSRF. Staff password proof creates only a pending MFA session. Authentication and security changes use versioned session revocation and append-only audit events. Redis provides atomic throttling and transports durable recovery-mail operation identifiers; PostgreSQL remains authoritative.

See [the B2 implementation contract](../../../docs/B2-IMPLEMENTATION.md) for routes, ownership rules, timeout policy, delivery behavior and integration requirements. Later business permissions and workflows are outside this batch.
