# B2 — Identity & Customer Isolation

Scope: B0 and B1 remain authoritative. This batch adds Identity and Customers only. Categories, intake, documents, AI, discovery, proposals, projects and reporting remain unimplemented.

## Browser contract

The first-party SPA and API must share one exact HTTPS origin (scheme, host and port). Configure `APP_URL` to that origin; `APP_TRUSTED_HOSTS` is an exact host allowlist. No wildcard domains, parent-domain cookies, credentialed cross-origin CORS or browser bearer tokens are supported. This batch provides the backend contract; a SPA is not included.

1. Call `GET /sanctum/csrf-cookie` with credentials.
2. Send the URL-decoded `XSRF-TOKEN` cookie as `X-XSRF-TOKEN` on every mutation, including registration, login, logout and recovery. Send credentials on all requests. Mutations require a matching `Origin` or `Referer`; any supplied `Origin` or `Referer` must identify the configured origin.
3. Call `POST /api/v1/auth/login`. A customer receives `next_step=authenticated`. Staff receive HTTP 202 with `mfa_enrollment` or `mfa_challenge`; follow that step before requesting authenticated resources. A protected request made during pending MFA returns HTTP 401 and clears that pending session.
4. Read `GET /api/v1/identity/me`. Success responses use `data`; errors retain B1 `error.code`, `error.message`, `error.fields` and `request_id`. `X-Request-ID` is present throughout.

Sanctum's session guard is registered explicitly. Its personal-token retrieval callback is disabled, `User` does not use `HasApiTokens`, and no `personal_access_tokens` table or token issuance route exists. The explicit SPA middleware group always applies exact-origin validation, encrypted cookies, PostgreSQL sessions and strict CSRF. This avoids Sanctum's optional origin-dependent session pipeline and Laravel 13's `Sec-Fetch-Site`-only CSRF shortcut. Tests exercise the production CSRF check, not Laravel's testing bypass.

Session cookie: `__Host-holoul_session`, `Secure`, `HttpOnly`, `Path=/`, no `Domain`, `SameSite=Lax`. The separately encrypted `XSRF-TOKEN` cookie is readable by the SPA. Session ID and CSRF token rotate on login; logout destroys the prior session. PostgreSQL stores encrypted session payloads. Public session listings return independent UUIDv7 metadata identifiers, never secret cookie IDs or their hashes.

Staff require confirmed TOTP/recovery proof before full authentication, with 30-minute idle and 12-hour absolute limits. Customers have 2-hour idle and 7-day absolute limits. Database metadata enforces limits regardless of cookie lifetime. Every authenticated request checks enabled status and the captured `auth_version`. Password resets, MFA security changes and role/status changes increment the version and revoke sessions; an in-flight stale session write cannot restore authority. Metadata pruning runs every 15 minutes in batches of 500 and skips locked rows.

## Endpoints added

All paths below use `/api/v1`, except the CSRF initialization route above.

| Method | Path | Purpose |
|---|---|---|
| POST | /auth/register | Customer-only registration; generic HTTP 202 |
| POST | /auth/login | Password first factor |
| POST | /auth/logout | Invalidate the current authenticated session |
| POST | /auth/email/verify | Consume a purpose-bound verification token |
| POST | /auth/email/resend | Generic HTTP 202; throttled by normalized account |
| POST | /auth/password/forgot | Generic HTTP 202; no account-existence disclosure |
| POST | /auth/password/reset | Consume reset token; revoke all sessions; no automatic login |
| POST | /auth/password/confirm | Confirm current password for five-minute recent-auth window |
| POST | /auth/password/change | Check current password, change password and revoke other sessions |
| POST | /auth/mfa/enrollment | Start staff enrollment from a valid password-only pending session |
| POST | /auth/mfa/enrollment/confirm | Confirm TOTP and return recovery codes once |
| POST | /auth/mfa/challenge | Complete staff authentication with TOTP |
| POST | /auth/mfa/recovery | Complete staff authentication with a recovery code |
| POST | /auth/mfa/recovery-codes | Regenerate codes after recent password; revoke other sessions |
| DELETE | /auth/mfa | Recent password and full MFA required; revoke all sessions and require reenrollment |
| GET/PATCH | /identity/me | Read own identity; update `full_name` only |
| GET | /identity/sessions | At most 100 active session metadata records with current-session marker |
| POST | /identity/sessions/revoke-others | Recent password required; rotate current session and revoke others |
| GET | /customers | Own-profile list; bounded optional search |
| GET/PATCH | /customers/{customer} | Own profile only; update phone only |
| GET/PATCH | /identities/{identity}/customers/{customer} | Both parent and child must belong to the caller |
| GET | /identity/staff/{user} | Explicit authorized staff identity read |
| PATCH | /identity/staff/{user}/authorization | Reviewed role/status changes with MFA and recent password |

Registration fields are `full_name`, `email`, `password`, `password_confirmation` and `phone`. Unknown fields and nested values are rejected, including attempted ownership, role, permission, staff, enabled and verification fields. Names use Unicode NFC and collapsed whitespace; email addresses use trim/lowercase with no provider-specific dot/plus rewriting. Public registration accepts ASCII email syntax; display email retains case. International phone input must include a `+` country calling code; no default country is guessed. libphonenumber validates and stores E.164 plus a bounded display value.

Email verification status is exposed by the identity API for future business authorization. B2 login does not require a verified email; staff still require MFA before full authentication.

Passwords require 12–128 characters including uppercase, lowercase and numbers. Argon2id uses 64 MiB, four iterations and one thread. Every client plaintext boundary explicitly hashes input, including text that resembles an encoded password hash. Authentication errors are generic for missing, disabled and incorrect-password accounts.

## Authorization and isolation

Eight seeded roles: Super Admin, Administrator, Project Manager, Business Analyst, Sales, Reviewer, Support and Customer. The seven permission codes cover only own identity/profile and B2 staff/security management. Runtime may read but cannot mutate the role/permission catalogs. Customer ownership is inherent in customer identity; staff roles do not grant customer-persona access.

Customer queries start with the caller's `user_id` before direct lookup, list, search or nested lookup. Malformed and foreign identifiers return HTTP 404; a visible but prohibited staff operation returns HTTP 403. PostgreSQL composite foreign keys enforce customer/staff persona consistency; customer ownership cannot be reassigned. Super Admin has explicit B2 grants and no global policy bypass.

Staff authorization mutations revalidate a persisted MFA session and recent password, prohibit Customer assignments, prohibit grants the actor lacks, reserve security-role management to an authorized Super Admin, and serialize changes around the last enabled Super Admin. Concurrent demotion/disable is tested with separate PostgreSQL processes. Changed authorization invalidates the affected session generation.

Initial provisioning is a protected console operation:

```sh
docker compose exec app php artisan identity:bootstrap-super-admin --name="..." --email="..."
```

It requires interactive hidden password confirmation, rejects noninteractive/visible-fallback input, and works only before any Super Admin assignment exists. It supplies no default/demo credential. Provisioning leaves the email unverified and issues a verification message; MFA enrollment is required before full staff authentication. Routine staff invitation/onboarding UI is outside this foundation.

## Recovery, MFA and delivery

Verification/reset tokens use 32 random bytes, store only SHA-256 hashes in token records and bind purpose, user, email hash, `auth_version` and a 60-minute expiry. Reset is single-use under a user/token row lock. Repeated valid verification is harmless. New issuance invalidates the previous pending token. Reset revokes pending verification links as well as sessions; unverified accounts can request a fresh verification link afterward.

Links point to same-origin SPA paths `/verify-email#token=...` or `/reset-password#token=...`. Fragments keep tokens out of HTTP request targets and referrers. The SPA must read the fragment and submit the token by POST; there are no mutating GET links or signed-URL CSRF exceptions. Those SPA pages are an integration requirement, not part of this backend batch.

TOTP uses RFC 6238, SHA-1, six digits and 30-second steps with ±1-step clock tolerance. The last accepted step is locked and persisted to reject replay. Current/pending secrets are encrypted at rest. Ten 128-bit recovery codes are disclosed once, stored as SHA-256 hashes in relational rows and consumed atomically. Password-only pending staff cannot replace an existing factor. The five-minute recent-password timestamp is preserved across a delayed MFA challenge.

The minimal Identity `MailTransport` contract and SMTP adapter can later be integrated with B7. PostgreSQL durable mail intent holds encrypted bounded recipient/token content; Redis transports only the B1 operation ID. The operation's PostgreSQL references contain the mail UUID. Tokens and encrypted payloads never appear in operation references, failure codes or audit. Delivery verifies the current token and operation fence. Successful, discarded or uncertain delivery clears sensitive payload. SMTP cannot guarantee exactly-once delivery after a lost acknowledgement; ambiguous delivery becomes terminal `mail_delivery_uncertain` instead of a blind retry. Resend creates a fresh token.

Local Compose explicitly selects Mailpit 1.31.1, pinned by digest, with private SMTP and a loopback-only UI proxy through Nginx. Mailpit has no relay credentials or external network; only Nginx publishes host ports. Sandbox mode accepts only `mailpit:1025` over `smtp` and `noreply@holoul.test`. External delivery requires an explicitly configured `smtps` host, username, password and from address; missing/insecure settings fail before sending. No external mail provider or real recipient was configured or contacted.

## Abuse controls and operational limits

Redis atomically checks and charges all throttle buckets. Redis failure returns HTTP 503 before sensitive work; no local fail-open fallback exists. Login limits are 5/minute per normalized account and 30/minute per IP. Resend and forgot-password limits are 3/hour/account plus 30/hour/IP. Registration is 10/hour/IP. Verification/reset limits are 5/minute/token plus 30/minute/IP, avoiding a global shared account bucket. MFA/password/security operations are limited to 5/minute/identity and 30/minute/IP. Keys use application-key HMACs rather than raw email, tokens or IP addresses.

Login, registration, forgot-password, resend and MFA responses have a 450 ms minimum plus up to 30 ms jitter after admission by the limiter. Login attempts add 100 ms per account attempt, up to five attempts. Generic response floors reduce, but do not claim to eliminate, timing differences under load. Security events use the append-only B1 audit with closed safe metadata; normal logs contain no credentials, request bodies, provider responses or exception text.

No external deployment, real staff onboarding, hosted CI or remote branch protection has been performed. Local HTTPS uses a generated self-signed SAN certificate trusted explicitly by smoke clients; [the README](../README.md#development) documents public-certificate export and local port settings. Deployed TLS must use a managed certificate and reviewed trusted proxies. The Compose PostgreSQL fixture still uses private-network plaintext as documented in B1; production configuration defaults to `verify-full`. SMTP delivery ambiguity, key rotation/recovery procedures, time synchronization, external delivery monitoring and production load-based tuning remain operational launch considerations.
