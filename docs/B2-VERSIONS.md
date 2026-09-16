# B2 dependency verification

Verified on 16 September 2026 against the pinned B1 PHP 8.4.25 / Laravel 13.32.0 / Sanctum 4.3.3 runtime. Existing B1 product versions and container security patches are unchanged; see [B1 versions](B1-VERSIONS.md).

| New dependency | Locked release | Use |
|---|---|---|
| `giggsey/libphonenumber-for-php` | 9.0.39 | International phone validation and E.164 normalization |
| `giggsey/locale` | 2.9.0 | Phone-library locale dependency |
| `pragmarx/google2fa` | 9.1.0 | Standards-based TOTP generation/verification |
| `paragonie/constant_time_encoding` | 3.1.3 | TOTP Base32/constant-time encoding dependency |

Composer constraints for the two new direct dependencies permit only patch updates within the selected minor releases (`~9.0.39` and `~9.1.0`); `composer.lock` pins the exact reviewed versions. The resulting lock contains 78 production packages and 33 development packages. No existing locked release was upgraded for B2. Composer strict validation, platform checks and advisory audit are required by the shared gate.

Mailpit 1.31.1 is the only additional runtime container, used solely as a local SMTP sandbox:

```text
axllent/mailpit:v1.31.1@sha256:98b916bd3c8d61f7633a52d3ea2f58d00620cb01ca57ab59edde68c347a95365
```

Its amd64 image manifest is `sha256:3856f9327f3f228afe8c4ce2dcca3cb2aa00f6ec60b569f36483f7ebb1ff44f7`. The pinned Trivy 0.74.0 scanner found zero HIGH/CRITICAL OS or Go findings in the initial image check; the complete gate rescans it with every release image. Mailpit remains on the private internal network; Nginx exposes its UI through a loopback-only proxy.

Primary sources checked:

- [Phone library maintained repository](https://github.com/giggsey/libphonenumber-for-php) and [release metadata](https://repo.packagist.org/p2/giggsey/libphonenumber-for-php.json).
- [Google2FA 9.1.0 Composer requirements](https://raw.githubusercontent.com/antonioribeiro/google2fa/v9.1.0/composer.json) and [release metadata](https://repo.packagist.org/p2/pragmarx/google2fa.json).
- [RFC 6238](https://datatracker.ietf.org/doc/html/rfc6238).
- [Official Laravel 13 Sanctum documentation](https://laravel.com/framework/docs/13.x/sanctum); implementation behavior was also verified directly in the installed 4.3.3 source and Laravel 13 request-forgery middleware.
- [Mailpit 1.31.1 release](https://github.com/axllent/mailpit/releases/tag/v1.31.1), [official installation](https://mailpit.axllent.org/docs/install/) and [API contract](https://mailpit.axllent.org/docs/api-v1/).

No external provider account, remote deployment or real recipient delivery is implied by these local verification results.
