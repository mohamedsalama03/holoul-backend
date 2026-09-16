# B1 runtime and compatibility record

Initial compatibility verified on **16 September 2026**, before implementation; container security decisions below were refined during B1 verification. The approved B0 baseline remains Laravel 13, PHP 8.4, PostgreSQL 18 and a maintained Redis release. Upstream constraints support this combination; no architecture conflict was found.

## Selected versions

| Component | Pin | Compatibility and reason |
|---|---|---|
| PHP | 8.4.25 | Current stable patch on B0's 8.4 line. Laravel 13 supports PHP 8.3–8.5; 8.4 remains actively supported through December 2026 and receives security support through December 2028. |
| Laravel framework | 13.32.0 | Current stable release in live Packagist metadata; requires PHP `^8.3`. Laravel 13 security support ends 17 March 2028. |
| Laravel application skeleton | 13.10.1 | Scaffold source; requires Laravel `^13.17`. Its frontend scaffolding and business/authentication examples are removed for B1. This scaffold is not a runtime package. |
| PostgreSQL | 18.6 | Current patch on B0's PostgreSQL 18 line. Laravel's supported PostgreSQL range includes 18. Use the same major and patch for development and CI. |
| Redis | 8.2.9 | Current patch of the Extended 8.2 release, maintained through 1 September 2030. Redis 8.10.1 is the latest Standard release; B1 needs established queue/cache primitives and benefits from the extended maintenance window. |
| Nginx | 1.30.5 (Alpine image) | Current stable 1.30 patch, released 15 September 2026 with security fixes. The ingress forwards only to PHP-FPM and keeps the database and Redis private. Image digest: `sha256:73c75df4075c918f91017fdda46ad81e55e5af77ba3a64ca3d5014bd9244fe7f`. |
| PhpRedis extension | 6.3.0 | Current stable PECL release; Laravel's default Redis client. Compile and verify against the selected PHP image. |
| Composer | 2.10.3 | Current stable release; provides the Composer runtime API `^2.2` required by Laravel. |
| Laravel Sanctum | 4.3.3 | Requires PHP `^8.2`, explicitly permits Illuminate 13 and Symfony Console 8. Dependency compatibility is established here; authentication, CSRF routes and session workflows belong to B2. |
| Larastan | 3.12.1 | Explicitly supports Illuminate 13; requires PHPStan `^2.2.14`. |
| PHPStan | 2.2.14 | Compatible with PHP 8.4 and the selected Larastan. Level **10** catches unsafe use of explicit and implicit mixed values; no baseline or broad ignore list is introduced. |
| PHPUnit | 12.5.35 | Latest maintained 12.x patch; requires PHP `>=8.3`. Matches the application skeleton's `^12.5.12` and framework's `^12.5.8` constraints. |
| Laravel Pint | 1.32.1 | Current stable; PHP `^8.3`. Laravel preset plus explicit strict types for first-party PHP. |
| Mockery | 1.6.15 | Current stable; compatible with PHP 8.4 and Laravel's testing integrations. |
| Collision | 8.9.5 | Explicitly permits Laravel 13 and PHPUnit 12.5.35; development dependency only. |
| Trivy | 0.74.0 | Pinned scanner for source secret detection and HIGH/CRITICAL runtime image vulnerabilities. Image digest: `sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969`. Scanner failures stop verification. |

PHPUnit 13.3.4 is also compatible with PHP 8.4.25 and Laravel 13.32.0. Selecting PHPUnit 12.5.35 follows the current Laravel skeleton and is not a workaround for a runtime incompatibility. Redis is transport/cache/throttling infrastructure; PostgreSQL remains authoritative for durable operation intent and business state.

The table records the selected exact versions. `composer.json` constrains runtime dependencies to their selected patch lines (`~13.32.0`, `~4.3.3`, `~6.3.0`), while the committed `composer.lock` fixes the exact package versions and complete transitive graph. This keeps `composer validate --strict` green without unbounded runtime version requirements; installations use the lock and do not update dependencies. The PHP/PhpRedis build and container references are also pinned, including immutable image digests. Do not replace them with floating `latest` tags. Image digest, package and extension updates require review, Composer audit, a rebuilt image, and the complete verification suite. A successful dependency solver is necessary but does not replace real PostgreSQL/Redis tests.

## Container bases and security maintenance

| Purpose | Exact upstream reference |
|---|---|
| PHP build, development and production runtime base | `php:8.4.25-fpm-alpine3.24@sha256:b7d9b8c58641dec579abcc5882766742c05cc92acf7b43b9f7f4b575241b1900` |
| Composer binary source for build/development stages | `composer:2.10.3@sha256:aaeab4b6b031e0a88efb907f0f26b563532a644fc2f4ea0d000ecf8658f7a2b8` |
| PostgreSQL derived-image base | `postgres:18.6-alpine3.24@sha256:d3e1620b530c944afa6e887d22eb899824da68e19c52024bf98f5220c88a65b2` |
| Redis derived-image base | `redis:8.2.9-alpine@sha256:30abb90e62f14b737010746def3ba99cc79fe19dcdb3d37b41f21fc62e7da19d` (Alpine 3.22) |
| Nginx runtime | `nginx:1.30.5-alpine@sha256:73c75df4075c918f91017fdda46ad81e55e5af77ba3a64ca3d5014bd9244fe7f` |
| Dockerfile frontend | `docker/dockerfile:1@sha256:ecfaec9ed6d810b56388c508f4121597bfbba70d41a6dfeee4d8cad5f295fc32` |
| Security scanner | `aquasec/trivy:0.74.0@sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969` |

Initial image scans identified vulnerable inherited Debian packages and outdated security packages in some upstream images. The application and PostgreSQL therefore use maintained Alpine 3.24 bases while retaining PHP 8.4.25 and PostgreSQL 18.6. This changes container operating-system packaging, not B0's Laravel modular monolith, service boundaries, database engine or queue architecture. PHP extensions are compiled and loaded against the **same pinned PHP/Alpine base and ABI** in the build and final runtime stages; Debian/glibc extension binaries are not copied into an Alpine/musl runtime. The final application image excludes Composer, development dependencies and compiler/build tooling.

The application Dockerfile refreshes packages within the maintained Alpine 3.24 branch. These OS package resolutions can change on a rebuild, so the release artifact is the exact built image that passes the full suite and vulnerability gate; a later rebuild requires those gates again. Core product versions, PhpRedis, Composer dependencies and upstream image references remain pinned. This decision does not assert that an application-image scan or compatibility check has passed; those results belong in the verification evidence.

PostgreSQL and Redis use small derived images to apply available distribution fixes while preserving their selected product versions and upstream entrypoints:

| Derived image | Pinned Alpine package revisions | Reason |
|---|---|---|
| PostgreSQL 18.6, `docker/postgres/Dockerfile` | `libcrypto3=3.5.8-r0`, `libssl3=3.5.8-r0`, `libuuid=2.42.3-r1`, `gosu=1.19-r5` | Patch the inherited OpenSSL/util-linux libraries and replace an upstream gosu binary carrying an older embedded Go runtime. |
| Redis 8.2.9, `docker/redis/Dockerfile` | `libcrypto3=3.5.8-r0`, `libssl3=3.5.8-r0`, `setpriv=2.41.6-r1` | Patch the inherited OpenSSL and privilege-switching utility without upgrading Redis to a different release line or removing entrypoint functionality. |

For PostgreSQL, Alpine's packaged `gosu` stays at upstream version 1.19; revision `1.19-r5` supplies a maintained rebuild with Go 1.26.8. The derived image removes the older `/usr/local/bin/gosu`, leaving the original PostgreSQL entrypoint to resolve `/usr/bin/gosu`. It retains the upstream privilege-drop behavior. Package revisions are explicit: unavailable revisions fail the build instead of silently changing the selected dependency set. Alpine maintains these changes in its [OpenSSL package](https://raw.githubusercontent.com/alpinelinux/aports/3.22-stable/main/openssl/APKBUILD), [util-linux package](https://raw.githubusercontent.com/alpinelinux/aports/3.22-stable/main/util-linux/APKBUILD), and [gosu package](https://raw.githubusercontent.com/alpinelinux/aports/3.24-stable/community/gosu/APKBUILD). The [official PostgreSQL image manifest](https://raw.githubusercontent.com/docker-library/official-images/master/library/postgres) and [official Redis image manifest](https://raw.githubusercontent.com/docker-library/official-images/master/library/redis) identify the maintained variants.

The PostgreSQL OS change requires a fresh B1 development cluster rather than reuse of a Debian cluster with implicit libc collation behavior. Initialization explicitly selects PostgreSQL's builtin locale provider, `C.UTF-8`, and UTF-8 encoding. Future product search/localization decisions remain in later batches. Existing non-disposable databases require a reviewed migration and collation plan; changing an image does not authorize deleting their volumes. See the [PostgreSQL 18 initdb options](https://www.postgresql.org/docs/18/app-initdb.html) and [locale documentation](https://www.postgresql.org/docs/18/locale.html).

Trivy's current metadata can emit a missing-details warning for `CVE-2026-80256` when examining the PostgreSQL base. The [upstream curl advisory](https://curl.se/docs/CVE-2026-80256.html) identifies a **Windows-only wcurl** path-traversal issue and rates it Medium; the Linux container is outside that affected platform. This explanation is not a scanner suppression or a substitute for the image gate. Keep the warning in the scan evidence; do not add ignore rules or omit unfixed findings. Trivy also warns that Alpine 3.24 is absent from its EOL metadata while selecting the Alpine 3.24 vulnerability repository; record that metadata limitation with executed results.

## Verification sources

Live package metadata was fetched directly because search-index results lagged the Laravel 13.32.0 release on 15 September. All constraints above are upstream package requirements, not assumptions based on major version names.

- [Laravel support policy](https://laravel.com/framework/docs/13.x/releases#support-policy), [PostgreSQL compatibility](https://laravel.com/framework/docs/13.x/database), and [Redis client configuration](https://laravel.com/framework/docs/13.x/redis).
- [PHP releases](https://www.php.net/releases/) and [supported PHP branches](https://www.php.net/supported-versions.php).
- [PostgreSQL 18.6 release notes](https://www.postgresql.org/docs/18/release-18-6.html).
- [Nginx stable 1.30 release history](https://nginx.org/en/CHANGES-1.30).
- [Redis maintenance policy](https://redis.io/docs/latest/operate/oss_and_stack/install/version-mgmt/) and [official release archive](https://download.redis.io/releases/).
- [PhpRedis stable release](https://pecl.php.net/package/redis) and [Composer release history](https://getcomposer.org/download/).
- Live Packagist records: [Laravel framework](https://repo.packagist.org/p2/laravel/framework.json), [application skeleton](https://repo.packagist.org/p2/laravel/laravel.json), [Sanctum](https://repo.packagist.org/p2/laravel/sanctum.json), [Larastan](https://repo.packagist.org/p2/larastan/larastan.json), [PHPStan](https://repo.packagist.org/p2/phpstan/phpstan.json), [Pint](https://repo.packagist.org/p2/laravel/pint.json), [Mockery](https://repo.packagist.org/p2/mockery/mockery.json), [Collision](https://repo.packagist.org/p2/nunomaduro/collision.json).
- [Versioned PHPUnit 12.5.35 manifest](https://raw.githubusercontent.com/sebastianbergmann/phpunit/12.5.35/composer.json) and [PHPStan level definitions](https://phpstan.org/user-guide/rule-levels).
- [Trivy 0.74.0 official release](https://github.com/aquasecurity/trivy/releases/tag/v0.74.0).

## Quality gates and their limits

The `Foundation quality and integration` GitHub Actions job invokes the same `scripts/verify.sh` entry point used locally. It runs on pull requests and pushes with read-only repository permission, disabled persisted checkout credentials, and an isolated Compose project. The checkout action is pinned to [v7.0.1, commit `3d3c42e5aac5ba805825da76410c181273ba90b1`](https://github.com/actions/checkout/releases/tag/v7.0.1), verified against GitHub's tag API on the inspection date. No publishing credentials or production secrets are required.

Architecture tests parse PHP into an AST and resolve names before inspecting module dependencies, external provider clients, controller database access and loops, and declared migration table names. Runtime configuration checks enforce PostgreSQL-only connections, production debug suppression even when `APP_DEBUG=true`, and the three B1 routes. A temporary B1 scope guard prevents PHP implementations in reserved business modules; update that guard only as each later batch is approved. These practical guards catch obvious violations; they do not replace review of authorization, cross-module writes, dynamic dispatch, SQL and domain behavior.

Static analysis covers `app`, the application bootstrap, configuration and routes at level 10. Generated framework caches and third-party source are excluded by the positive path list. Tests have dedicated PHPUnit execution and syntax/style checks; they are not misrepresented as part of the level 10 application gate.

This file records compatibility decisions, not check results. Exact executed commands, exit results and any unavailable checks belong in B1's verification evidence. Committing a workflow does not enable repository branch protection: the repository owner must require `Foundation quality and integration` after the repository is connected to its remote host. No remote repository, branch-protection setting or deployment is created by B1.
