# syntax=docker/dockerfile:1

# Base: FrankenPHP (Caddy + PHP 8.4) with the extensions the app needs.
FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

# intl: server-side number formatting (NumberFormatter). pdo_sqlite ships with the image.
RUN install-php-extensions intl zip \
    && apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=:80

# Development: the source tree is bind-mounted by compose.dev.yaml.
FROM base AS dev

ENV APP_ENV=dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php/dev.ini $PHP_INI_DIR/conf.d/zz-app.dev.ini

# Production: the image the demo runs (compose.yaml). Everything is baked in:
# vendor, warmed-up cache and, later, the embedded data snapshots.
FROM base AS prod

ENV APP_ENV=prod
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/prod.ini $PHP_INI_DIR/conf.d/zz-app.prod.ini

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . .
# No secret in the image: APP_SECRET is a placeholder that docker/entrypoint.sh replaces with a
# random value when the container first starts.
RUN printf 'APP_SECRET=generated-on-first-start\n' > .env.prod.local \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod \
    && rm .env.prod.local \
    && composer run-script --no-dev post-install-cmd \
    && chmod +x bin/console \
    && bin/console cache:warmup

COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/go-figure-entrypoint

# An unprivileged user, by number: no account to create. The web server listens on 8080 inside the
# container, so it needs no capability. Writable: var/ (logs, MCP sessions), Caddy's own
# directories, and the file whose secret the entrypoint generates on first start.
RUN chown -R 10001:10001 /app/var /app/.env.local.php /data/caddy /config/caddy \
    && setcap -r /usr/local/bin/frankenphp
# No Caddy admin API inside the container: nothing needs it.
ENV SERVER_NAME=:8080 \
    CADDY_GLOBAL_OPTIONS="admin off"
USER 10001:10001

ENTRYPOINT ["go-figure-entrypoint"]
# Setting ENTRYPOINT resets the base image's CMD: restore it.
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
