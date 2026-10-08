# HOLOUL Certbot deployment hook

Prepared for controlled installation only. No VPS access, installation, ACME
request, deployment, image build, application change or OpenAPI change is performed
by this task. The immutable application baseline remains
`8b1ffea5c75dc4adb68f795a73d1c6c513cbbd8c`; the release branch is unchanged.

Target supplied by the owner: Ubuntu 24.04, Certbot 2.9.0, Docker Nginx 1.30.5,
project `holoul-production`, container `holoul-production-ingress-1`, UID/GID
`101:101`. The timer is already enabled and active. The reported certificate
expires **2027-01-06**; that date has not been independently inspected on the VPS.

## Why a small configuration change is required

The current stable directory bind mount is suitable; **do not replace the host
`edge-tls` directory itself**, and do not bind-mount individual certificate files.
Replacing two PEM files independently is not atomic. Even two symlinks through a
single moving directory pointer can be resolved on opposite sides of a switch
when Nginx opens the certificate and key separately.

The proposed `nginx.versioned.conf` differs from the frozen production config only
by replacing its four per-server certificate/key directives with **one include
at HTTP scope**. Both TLS servers inherit that include. It resolves:

```text
/run/holoul-tls/current/nginx-tls.conf
```

The included file contains two literal paths to the same immutable version:

```nginx
ssl_certificate /run/holoul-tls/versions/<public-chain-sha256>/fullchain.pem;
ssl_certificate_key /run/holoul-tls/versions/<public-chain-sha256>/privkey.pem;
```

Nginx opens that include once, and subsequently reads immutable paths. An atomic
`rename` of `current` selects one complete version, even if an external reload
overlaps the switch. No certificate bytes or private-key bytes enter Nginx
configuration, variables or command arguments. The old top-level PEM files remain
untouched as initial-adoption evidence; the hook never updates them. They are **not
the renewed files** after adopting the versioned configuration.

The frozen `deploy/production/nginx.conf`, Bootstrap config and Compose file remain
unchanged. `compose.tls-versioned.yaml` proposes only a different source for the
existing `/etc/nginx/nginx.conf` bind mount; the TLS directory mount, image, UID,
ports, HTTP-01 handling and routing stay the same. Installing this override and
switching ingress require a separate controlled operational approval. Using the
old final config is explicitly blocked instead of receiving a misleading success.

## Behavior and safety boundaries

- Normal Certbot invocations act only for the exact lineage
  `/etc/letsencrypt/live/holoul.com.ly` and exactly the two approved renewed domains.
  Other lineages return a harmless skip without Docker or filesystem changes.
- A nonblocking `flock(2)` exclusive lock, via Python `fcntl.flock`, covers the
  complete transaction, including post-reload verification. It interoperates with
  the system `flock` command. Concurrent invocation exits 75. The lock file is not
  removed or replaced.
- Source links must resolve inside the intended Certbot archive directory. Files
  and parents must be root-owned and not writable by other identities. Reads are
  bounded; FIFOs, devices, hard links and unexpected symlinks are rejected.
- OpenSSL checks PEM structure, the private key, matching public keys, exact SANs,
  non-CA leaf, server purpose, security level 2 and chain trust against the host CA
  bundle. Every supplied CA certificate is checked, including validity. A new
  candidate must have at least 24 hours remaining; rollback versions must still be
  valid. Untrusted staging certificates cannot become active.
- The candidate is written inside a private staging directory, files and
  directories are flushed, and the complete version is published before changing
  `current`. Existing version contents and generated config are revalidated.
- Container name, Compose labels, read-only root, user, entrypoint, read-only
  directory bind and loopback-reachable port bindings are checked. All subsequent
  commands target the captured **container ID**, with identity/start-time checks.
  No command creates, stops, restarts or recreates a production container.
- Exact reviewed config/include hashes and actual HTTP responses distinguish
  Bootstrap from production. Merely having a final config file on disk is not
  evidence that the running master has left Bootstrap.
- **Bootstrap:** validate, stage and update the version pointer/state; no Nginx
  test against final configuration, no reload, and no activation of product routes.
- **Versioned production:** verify the currently served certificate first; switch
  the pointer; run `nginx -t` against the container's actual mounted configuration;
  signal only that ingress; require new worker generation and successful trusted
  TLS handshakes with the expected leaf fingerprint for **both** names. A zero
  exit code from `nginx -s reload` alone is insufficient.
- Each transaction records the verified previous version durably before switching.
  Failure restores that pointer, retests/reloads production and verifies the old
  served certificate. If recovery cannot be proved, exit nonzero and retain the
  journal. Bootstrap recovery never reloads Nginx.
- SIGTERM/SIGINT are handled; SIGKILL/power interruption leaves a recoverable
  journal. A future invocation or explicit `--recover` revalidates and restores the
  previous version. A replaced/restarted ingress or unknown configuration blocks
  automatic recovery; it never signals a different container.
- Repeated renewal with identical material is a no-op. Repeated explicit rollback
  does not oscillate between versions. Older directories are not automatically
  deleted; retention cleanup is a separately reviewed root operation.
- Only fixed event codes are logged. OpenSSL and Docker output is captured rather
  than printed. Private scratch files stay under the root-only state directory.
  Core dumps are disabled for the hook and inherited subprocesses. No private PEM
  is included in Git, reports or installation artifacts.

## Ownership and permissions

| Host object | Owner | Mode |
|---|---|---|
| `/usr/local/libexec/holoul-tls-renewal.py` | root:root | 0750 |
| Certbot deploy-hook wrapper | root:root | 0750 |
| `/etc/holoul/nginx` | root:root | 0755 |
| Reviewed Nginx config and operational override | root:root | 0644 |
| `/etc/holoul/secrets/edge-tls` and `versions` | root:101 | 0750 |
| Published version directory | root:101 | 0750 |
| Version `fullchain.pem`, `privkey.pem`, `nginx-tls.conf` | root:101 | 0440 |
| `current` relative symlink | root:root | symlink mode 0777; parent is not group-writable |
| `/var/lib/holoul/tls-renewal` | root:root | 0700 |
| State, journal and private validation scratch files | root:root | 0600 |
| Validation/unpublished staging directory | root:root | 0700 until publication |
| `/run/holoul-tls-renewal.lock` | root:root | 0600 |

All ancestors must be root-owned and not group/world writable. The ingress reads
only its bind-mounted directory, not Certbot accounts or the root-only journal.
Private files left by an uncatchable interruption remain protected; do not collect
the state directory or TLS directory into diagnostic bundles.

## Controlled installation procedure — NOT executed here

Use the reviewed operational commit/files separately from the frozen application
checkout. Verify `SHA256SUMS` in the handoff first. Required host tools are Python
3.12, OpenSSL 3, Docker CLI and the existing Certbot 2.9 timer. Run locally on the
VPS only after installation approval; do not paste keys into commands or chat.

1. Privately review the lineage renewal configuration: authenticator must be
   `webroot`, no Nginx/Apache installer, and both names must map to the existing
   `/srv/holoul/acme-webroot`. Check all configured and directory hooks. A second
   HOLOUL hook that copies flat PEM files or restarts services must be retired in
   the reviewed plan. Do not modify unrelated hooks or stop the Certbot timer.
2. Confirm the ingress really is running Bootstrap and the mounted TLS directory
   is exactly `/etc/holoul/secrets/edge-tls`. Preserve any existing flat PEM pair;
   do not overwrite it with synthetic certificates. Coordinate all manual TLS,
   config and ingress lifecycle operations using the same lock.
3. From the verified operational files directory, install files and directories:

```sh
sudo install -D -o root -g root -m 0750 renew.py /usr/local/libexec/holoul-tls-renewal.py
sudo install -d -o root -g root -m 0700 /var/lib/holoul/tls-renewal
sudo install -d -o root -g 101 -m 0750 /etc/holoul/secrets/edge-tls
sudo install -d -o root -g 101 -m 0750 /etc/holoul/secrets/edge-tls/versions
sudo install -d -o root -g root -m 0755 /etc/holoul/nginx
sudo install -o root -g root -m 0644 nginx.versioned.conf /etc/holoul/nginx/nginx.versioned.conf
sudo install -o root -g root -m 0644 compose.tls-versioned.yaml /etc/holoul/nginx/compose.tls-versioned.yaml
```

If the existing flat pair uses the previously permitted UID 101 ownership, change
**only those two files** to `root:101`, mode `0440`, before adoption. Inspect them
without printing their contents. An incomplete, invalid or expired old pair blocks
automatic adoption and requires a separately reviewed recovery plan.

```sh
# Only when BOTH existing regular PEM files have been reviewed:
sudo chown root:101 /etc/holoul/secrets/edge-tls/fullchain.pem /etc/holoul/secrets/edge-tls/privkey.pem
sudo chmod 0440 /etc/holoul/secrets/edge-tls/fullchain.pem /etc/holoul/secrets/edge-tls/privkey.pem

# Bootstrap-only initial adoption. If no flat pair exists, use the verified lineage.
sudo /usr/bin/python3 -I /usr/local/libexec/holoul-tls-renewal.py --initialize

# Stage the actual current lineage. Bootstrap remains active; no reload occurs.
sudo env RENEWED_LINEAGE=/etc/letsencrypt/live/holoul.com.ly \
  RENEWED_DOMAINS='holoul.com.ly www.holoul.com.ly' \
  /usr/bin/python3 -I /usr/local/libexec/holoul-tls-renewal.py

# Register only after initialization and staging pass.
sudo install -o root -g root -m 0750 50-holoul-tls /etc/letsencrypt/renewal-hooks/deploy/50-holoul-tls
```

Do not wrap the Python command in another `flock`: it acquires the lock itself.
For other manual ingress/config operations use
`sudo flock -x /run/holoul-tls-renewal.lock ...` so they cannot race a transaction.

Installing files does **not** switch Bootstrap to production. The later approved
ingress transition must use the base Compose plus `compose.tls-versioned.yaml`,
without `compose.acme-bootstrap.yaml`, preserving project `holoul-production` and
the same reviewed image. Test the proposed final Nginx config in a controlled
validation container before the ingress-only transition. Review the merged Compose
to ensure that only the Nginx-config mount source differs. Never replace the TLS
directory inode and never mount `current` itself as a host bind source.

## Renewal dry-run procedure

First complete the private renewal-config/hook review above. The following tests
HTTP-01 renewal through Certbot's staging CA with directory hooks disabled and
saved pre/post/deploy hooks overridden by harmless commands:

```sh
sudo certbot renew --cert-name holoul.com.ly --dry-run --no-directory-hooks \
  --pre-hook /usr/bin/true --post-hook /usr/bin/true --deploy-hook /usr/bin/true
```

Do not use standalone, `--nginx`, `--force-renewal` or `--break-my-certs`. Both
names must remain reachable on port 80 and the existing webroot must stay mounted.
No service stop is needed. The hook does not touch challenge files or routing.

Only after controlled hook installation, this additionally exercises **this hook
only**, retaining the harmless pre/post overrides:

```sh
sudo certbot renew --cert-name holoul.com.ly --dry-run --run-deploy-hooks \
  --no-directory-hooks --pre-hook /usr/bin/true --post-hook /usr/bin/true \
  --deploy-hook /etc/letsencrypt/renewal-hooks/deploy/50-holoul-tls
```

`--run-deploy-hooks` is appropriate for this controlled integration check, not as
an assumed harmless simulation: after a successful dry run it runs the real hook
against the current active lineage, not the temporary staging certificate. An
unchanged version causes no reload; Bootstrap always causes no reload. In final
production, an actual difference can cause a real verified reload.

Certbot 2.9 logs deploy-hook failures; a successful Certbot service/renewal exit by
itself does not prove certificate deployment succeeded. Monitor `HOLOUL_TLS`
blocking/error events, the recovery journal, served expiry and fingerprint. A
failed deploy hook needs immediate operator attention and manual replay after the
cause is fixed; do not assume the next timer run will redeploy an already-renewed
certificate. Do not expose full Certbot configuration, accounts or private keys in
monitoring artifacts. No real Certbot dry run is performed in this repository test.

## Recovery and rollback

```sh
# Restore the recorded previous version after interruption; no lineage env needed.
sudo /usr/bin/python3 -I /usr/local/libexec/holoul-tls-renewal.py --recover

# Deliberately return to the verified previous version after an accepted renewal.
sudo /usr/bin/python3 -I /usr/local/libexec/holoul-tls-renewal.py --rollback
```

Both commands acquire the same lock. Production recovery tests configuration,
reloads only the captured ingress and verifies TLS for both names. Bootstrap
recovery only restores files/state. Repeating rollback is a no-op once its target
is active. First adoption has no earlier version; it cannot invent one.

If recovery reports blocked, preserve `transaction.json`, `verified.json` and all
version directories privately. Do not delete the journal, manually copy two PEM
files, restart the stack or claim success based on reload exit status. A changed
container identity/configuration, expired previous certificate, corrupt state or
failed old-config test needs a controlled root recovery review. An interrupted
initialization can be resumed with `--initialize` while Bootstrap is still active.
No automatic key/history cleanup occurs.

## Local verification

Tests require root **only on the trusted non-production Ubuntu build host** and
the already verified local Nginx image; they never build/pull an image or contact
the VPS/ACME. They generate temporary synthetic root/intermediate/leaf keys under
`/root/holoul-tls-tests-*`, use randomly named `holoul-tls-test-*` containers and
loopback-only ephemeral ports, then remove those fixtures and containers.

```sh
sudo python3 -B -m unittest discover -s ops/tls-renewal-hook -p 'test_*.py' -v
```

See `VERIFICATION.md` for measured results, failed preliminary runs, review limits
and the final operational identity. Application PHP/PostgreSQL suites are not a
substitute for these TLS/Docker tests and are outside this deployment-only delta.

## Primary references

- [Certbot 2.9.0 deploy-hook implementation](https://github.com/certbot/certbot/blob/v2.9.0/certbot/certbot/_internal/hooks.py)
- [Certbot renewal and dry-run behavior](https://eff-certbot.readthedocs.io/en/stable/using.html#renewing-certificates)
- [Nginx graceful reload behavior](https://nginx.org/en/docs/control.html)
- [Nginx certificate directives and HTTP/server contexts](https://nginx.org/en/docs/http/ngx_http_ssl_module.html#ssl_certificate)
