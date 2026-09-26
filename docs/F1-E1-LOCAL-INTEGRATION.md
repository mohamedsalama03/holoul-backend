# HOLOUL F1-E1 — local same-origin integration

Scope: local integration enablement only. No B9, F2, business API change, production deployment, certificate creation/trust installation, or production performance benchmark. See [verification and known frontend blocker](F1-E1-VERIFICATION.md).

## Local routing

Open **https://localhost:8443/admin/login**. The existing Next.js development server is in `D:\customers\holoul frontend\dashboard`, port **3001**, with `basePath: "/admin"`.

| Browser path | Destination |
|---|---|
| `/api/v1/*` | Existing Laravel FastCGI application |
| `/sanctum/*` | Existing Laravel/Sanctum application |
| `/admin` and `/admin/*` | `http://host.docker.internal:3001`, original path and query retained |

The local Nginx configuration uses a bounded `/admin(?:/|$)` location, not a prefix that matches `/administrator`. HTTPS and exact `Host: localhost:8443` are required for dashboard ingress. Supplied Origin and Referer must match the local origin; cross-site Fetch Metadata is rejected. Origin is never rewritten. Existing Laravel ExactOrigin/CSRF/trusted-host/session middleware is unchanged.

The dashboard upstream receives canonical `Host`/`X-Forwarded-Host: localhost:8443`, `X-Forwarded-Proto: https`, port 8443, and the immediate ingress peer as `X-Forwarded-For`/`X-Real-IP`. Arbitrary client `Forwarded`, `X-Forwarded-Prefix`, and `Proxy` are removed. No client forwarding chain is trusted. WebSocket Upgrade/Connection are forwarded, streaming is unbuffered, and development WebSocket reads may remain open for an hour.

One location-local `add_header X-Request-ID` disables inheritance of the API header set. Next.js supplies the dashboard's CSP and security headers. The API still returns exactly `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`. Dashboard edge errors are safe JSON without the inherited API CSP. No CORS allowance or cookie rewriting was added.

Only dashboard requests have `client_max_body_size 41m`; the existing 12m edge/API bound and all backend application/document limits are unchanged. Nginx still publishes only the existing loopback ports 8080/8443/8025. No database, Redis, PHP-FPM, storage, scanner or inspector port was published. The host Next.js listener already existed; this work did not create another listener or change host firewall rules.

Laravel disables its own trusted-host middleware in `APP_ENV=local`. The local FastCGI location therefore also checks an explicit scheme/Host list at the edge: HTTP `localhost:8080` or `127.0.0.1:8080`, HTTPS `localhost:8443` or `127.0.0.1:8443`. All other authorities are rejected before Laravel; forwarded headers cannot change this decision. Authenticated surfaces still require the exact configured `https://localhost:8443` origin even when a public health/version route accepts loopback IP access.

## Start or refresh the initialized local environment

Use Ubuntu/WSL from `/home/mohamed/projects/customers/holoul`. The existing local certificate and initialized volumes must already be present. These commands intentionally do not run `initialize`, generate/trust a certificate, or delete volumes.

```bash
docker build --target development -t holoul-app:f1-e1-development .
export HOLOUL_APP_IMAGE=holoul-app:f1-e1-development
export HOLOUL_APP_ENV=local
export HOLOUL_DEPLOYMENT_PROFILE=local-verification
export HOLOUL_AI_ENABLED=false
export HOLOUL_HTTPS_PORT=8443
docker compose config --quiet
docker compose run --rm --no-deps -T migrate
docker compose up -d --no-deps app scheduler queue document-queue ai-queue notification-queue
docker compose exec -T nginx nginx -t
docker compose exec -T nginx nginx -s reload
```

The migration service retains `holoul_migrator`; the application/fixture retain `holoul_app`. No migration privileges are granted to the runtime. This switches the local application processes to the current candidate source; it does not certify or deploy production. All previous candidate image tags/evidence remain intact. Do not use the development image as a release image.

The frontend owner starts the existing dashboard using `npm run dev -- -p 3001`. Docker Desktop must resolve `host.docker.internal` to that existing host service. Linux hosts without Docker Desktop need an explicitly reviewed equivalent local host connection; this profile does not open new public ports as a fallback.

## Synthetic identities and reset

Run before **every** real-backend E2E suite, with no other suite using these identities:

```bash
python3 scripts/local-e2e.py --reset \
  --frontend-env '/mnt/d/customers/holoul frontend/dashboard/.env.e2e.local'
```

The wrapper creates one random local run marker and distinct random passwords once in `artifacts/local-e2e-private/manifest.json` (ignored, directory 0700/file 0600). It invokes the pinned Docker development PHP runtime; no host PHP is required. A repeat reset uses the same three synthetic `@example.test` identities/UUIDs and passwords, invalidates their old sessions, and generates a new enrolled MFA secret. It neither truncates the database nor modifies unrelated identities, roles or business records. A conflicting identity name/persona is refused transactionally. Rate limits are **not** cleared or relaxed; wait for the normal window between repeated failed test runs.

The three personas are:

1. `staff`: enabled, verified staff with the existing Project Manager role. The tool performs real password login → `MfaActions::beginEnrollment` → real TOTP confirmation → encrypted credential and hashed recovery-code generation. It revokes the setup session; the browser must log in normally. Confirmation uses the existing supported previous TOTP step so the current step is fresh for E2E. No replay check or clock tolerance was changed.
2. `enroll_staff`: same staff role, no confirmed or pending MFA credential or recovery codes. Each reset removes only that fixture's prior enrollment state, so the frontend can exercise the real enrollment flow again.
3. `customer`: real customer identity/profile/Customer role; the real API accepts customer login but rejects admin customer access. The dashboard must render its staff-only denial state.

The tool writes `artifacts/local-e2e-private/frontend.env` privately, then exports to the frontend's Git-ignored `.env.e2e.local` only after verifying that Git ignores it. It refuses an existing frontend environment that was not generated by this tool. On Windows, also restrict this file's ACL to the current user and SYSTEM; that restriction has been applied to the delivered file. Do not paste these values into tickets, chat, screenshots, normal logs, frontend source or tracked files. Recovery codes are never exported.

The exported keys are:

```dotenv
HOLOUL_E2E_ADMIN_URL=https://localhost:8443/admin
HOLOUL_E2E_STAFF_EMAIL=<generated synthetic email>
HOLOUL_E2E_STAFF_PASSWORD=<generated per-account password>
HOLOUL_E2E_STAFF_TOTP_SECRET=<real enrolled synthetic base32 secret>
HOLOUL_E2E_ENROLL_STAFF_EMAIL=<generated synthetic email>
HOLOUL_E2E_ENROLL_STAFF_PASSWORD=<generated per-account password>
HOLOUL_E2E_CUSTOMER_EMAIL=<generated synthetic email>
HOLOUL_E2E_CUSTOMER_PASSWORD=<generated per-account password>
HOLOUL_E2E_ACCEPT_SELF_SIGNED_TLS=1
```

The final switch is explicit and **only for loopback E2E**. The existing frontend test helper rejects remote hosts with this switch. It does not change the browser/OS trust store or application/production TLS. The backend ingress checker fixes its URL to this loopback origin. No global TLS-verification environment variable is used.

The fixture CLI requires `HOLOUL_LOCAL_E2E=1`, raw and resolved `APP_ENV=local`, debug disabled, the `local-verification` profile, exact local URL/origin, Mailpit sandbox, PostgreSQL host/database/runtime identity, development test dependencies, and a private manifest. It checks the actual PostgreSQL database/session user before writes. It refuses production/staging/testing profiles, other origins/hosts and migration credentials. `tools/local-e2e` is physically removed in the production build; there is no fixture HTTP route or production Artisan command. Application session durations, MFA requirements, auth generations, recent-password and replay protections are unchanged.

## Real frontend command and pending-session rule

From PowerShell in `D:\customers\holoul frontend\dashboard`:

```powershell
$env:HOLOUL_UI_URL = 'http://localhost:3001/admin'
npm.cmd run test:e2e
Remove-Item Env:\HOLOUL_UI_URL
```

`HOLOUL_UI_URL` tells Playwright to reuse the existing dev process. The real-backend project still uses the browser-visible `https://localhost:8443/admin` from `.env.e2e.local`. The project uses one worker, no retries, installed Chrome, and disables traces/screenshots/video. Treat any failure-context output as private because an enrollment screen can contain synthetic security material.

**Known F1 integration blocker discovered by this enablement:** `useRedirectIfSignedIn()` calls `checkSession()` from the MFA challenge/enrollment screens. That issues authenticated `/identity/me` while the session is only pending. The backend correctly returns 401 and invalidates that pending session; the following MFA command cannot complete. The frontend must avoid authenticated current-user reads until MFA confirmation/challenge succeeds. Do not bypass this with a fake capability, frontend-only login, changed backend response, suppressed CSRF, or a weakened session guard. See the verification report for the executed command and independent successful backend flows. No frontend source fix is included in F1-E1.

Real session revocation remains available: complete MFA in two browser sessions, confirm the password in the second, POST `/api/v1/identity/sessions/revoke-others` with its current CSRF proof, then verify the first gets 401 and the second retains access. True wall-clock session expiry was not shortened or specially configured.

## Recheck

```bash
HOLOUL_E2E_ACCEPT_SELF_SIGNED_TLS=1 python3 scripts/verify-local-ingress.py
bash scripts/verify-f1-e1.sh
# For a later run, preserve the earlier evidence by supplying a unique name:
bash scripts/verify-f1-e1.sh f1-e1-repeat-20260927
```

The quality runner refuses to overwrite its existing log; the optional unique name selects a separate evidence directory. It validates Docker/Nginx, production fixture exclusion, Composer/Pint/PHPStan, all Unit/Architecture tests, affected identity/authorization/customer-isolation/HTTP/contract Feature tests against `holoul_test`. It does not run `scripts/verify.sh` or the blocked performance benchmark. B8 remains unaccepted pending the target VPS.
