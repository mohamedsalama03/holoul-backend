# syntax=docker/dockerfile:1@sha256:ecfaec9ed6d810b56388c508f4121597bfbba70d41a6dfeee4d8cad5f295fc32
FROM composer:2.10.3@sha256:aaeab4b6b031e0a88efb907f0f26b563532a644fc2f4ea0d000ecf8658f7a2b8 AS composer-bin
FROM php:8.4.25-fpm-alpine3.24@sha256:b7d9b8c58641dec579abcc5882766742c05cc92acf7b43b9f7f4b575241b1900 AS php-source

FROM php-source AS runtime-os
RUN apk upgrade --no-cache \
    && apk add --no-cache libpq icu-libs icu-data-full oniguruma libzip ca-certificates \
    && rm -rf /usr/local/include/php /usr/local/lib/php/build /usr/src/php \
    && rm -f /usr/src/php.tar.xz /usr/src/php.tar.xz.asc \
    && rm -f /usr/local/bin/pecl /usr/local/bin/pear /usr/local/bin/peardev \
       /usr/local/bin/phpize /usr/local/bin/php-config /usr/local/bin/docker-php-ext-configure \
       /usr/local/bin/docker-php-ext-install /usr/local/bin/docker-php-source

# Share patched runtime libraries; restore PHP build files only in the builder.
FROM runtime-os AS base
COPY --from=php-source /usr/local/ /usr/local/
COPY --from=php-source /usr/src/ /usr/src/
RUN --mount=type=cache,id=holoul-apk-v3.24-v1,target=/var/cache/apk,sharing=locked \
    apk --cache-dir /var/cache/apk add git unzip \
    && apk --cache-dir /var/cache/apk add --virtual .holoul-build-deps \
       $PHPIZE_DEPS linux-headers libpq-dev icu-dev oniguruma-dev libzip-dev \
    && docker-php-ext-install -j2 pdo_pgsql intl mbstring zip pcntl bcmath opcache \
    && pecl install redis-6.3.0 && docker-php-ext-enable redis \
    && apk --cache-dir /var/cache/apk del --no-network .holoul-build-deps \
    && rm -rf /tmp/pear
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
RUN addgroup -g 1000 holoul && adduser -D -u 1000 -G holoul -h /home/holoul holoul \
    && mkdir -p /var/www/html /run/holoul-secrets /run/holoul-app /run/holoul-migrator /run/holoul-bootstrap /run/holoul-redis /run/holoul-tls /run/holoul-storage-server /run/holoul-storage-admin /run/holoul-storage /run/holoul-clamav /run/holoul-inspector /run/holoul-portfolio \
    && chown -R holoul:holoul /var/www/html /run/holoul-secrets /run/holoul-app /run/holoul-migrator /run/holoul-bootstrap /run/holoul-redis /run/holoul-tls /run/holoul-storage-server /run/holoul-storage-admin /run/holoul-storage /run/holoul-clamav /run/holoul-inspector /run/holoul-portfolio
WORKDIR /var/www/html
COPY docker/php/holoul.ini /usr/local/etc/php/conf.d/zz-holoul.ini
COPY docker/php/pool.conf /usr/local/etc/php-fpm.d/zz-holoul.conf
COPY --chmod=755 docker/app/entrypoint.sh /usr/local/bin/holoul-entrypoint
USER holoul
ENTRYPOINT ["holoul-entrypoint"]
CMD ["php-fpm", "-F"]

FROM base AS dependencies
COPY --chown=holoul:holoul composer.json composer.lock ./
RUN --mount=type=cache,id=holoul-composer-v2,target=/home/holoul/.cache/composer,uid=1000,gid=1000,sharing=locked \
    COMPOSER_CACHE_DIR=/home/holoul/.cache/composer \
    composer install --no-dev --prefer-dist --no-scripts --no-interaction --no-progress --no-autoloader

FROM dependencies AS production-build
COPY --chown=holoul:holoul . .
RUN rm -rf tools/local-e2e \
    && composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && php artisan package:discover --no-ansi

# Production inherits only the shared runtime layer and compiled extensions.
FROM runtime-os AS runtime
COPY --from=base /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=base /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=base /usr/local/etc/php-fpm.d/zz-holoul.conf /usr/local/etc/php-fpm.d/zz-holoul.conf
COPY --from=base /usr/local/bin/holoul-entrypoint /usr/local/bin/holoul-entrypoint
RUN addgroup -g 1000 holoul && adduser -D -u 1000 -G holoul -h /home/holoul holoul \
    && mkdir -p /var/www/html /run/holoul-secrets /run/holoul-app /run/holoul-migrator /run/holoul-bootstrap /run/holoul-redis /run/holoul-tls /run/holoul-storage-server /run/holoul-storage-admin /run/holoul-storage /run/holoul-clamav /run/holoul-inspector /run/holoul-portfolio \
    && chown -R holoul:holoul /var/www/html /run/holoul-secrets /run/holoul-app /run/holoul-migrator /run/holoul-bootstrap /run/holoul-redis /run/holoul-tls /run/holoul-storage-server /run/holoul-storage-admin /run/holoul-storage /run/holoul-clamav /run/holoul-inspector /run/holoul-portfolio
WORKDIR /var/www/html
COPY --from=production-build --chown=holoul:holoul /var/www/html/ /var/www/html/
RUN php -r 'foreach (["pdo_pgsql", "redis", "intl", "mbstring", "zip", "pcntl", "bcmath", "Zend OPcache"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Required runtime extension unavailable.\n"); exit(1); } }' \
    && test ! -e /usr/local/bin/composer \
    && test ! -e /usr/bin/git \
    && test ! -e /usr/bin/gcc \
    && test ! -e /usr/bin/make \
    && test ! -e /usr/local/include/php \
    && test ! -e vendor/bin/phpunit
USER holoul
ENTRYPOINT ["holoul-entrypoint"]
CMD ["php-fpm", "-F"]

FROM base AS development
COPY --chown=holoul:holoul composer.json composer.lock ./
RUN --mount=type=cache,id=holoul-composer-v2,target=/home/holoul/.cache/composer,uid=1000,gid=1000,sharing=locked \
    COMPOSER_CACHE_DIR=/home/holoul/.cache/composer \
    composer install --prefer-dist --no-scripts --no-interaction --no-progress --no-autoloader
COPY --chown=holoul:holoul . .
RUN composer dump-autoload --no-scripts && php artisan package:discover --no-ansi
