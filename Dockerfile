FROM php:8.4-fpm-alpine

# -----------------------------------------------------------------------
# System packages and PHP extensions
#
# This is a Laravel 13 JSON API. It needs: pdo_mysql (the app database
# driver against MySQL), pdo_sqlite (the automated PHPUnit suite runs
# against SQLite in-memory - see phpunit.xml - so this container must
# support both drivers), mbstring (Laravel core), intl (Laravel's
# Intl-backed helpers), zip (Composer/Laravel), bcmath (Laravel numeric
# helpers), and opcache (performance). "-dev" header packages are only
# needed to compile these extensions; they are installed as a virtual
# group and removed again afterwards so the final image stays small,
# while the matching runtime shared libraries are kept.
# -----------------------------------------------------------------------
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        sqlite-dev \
    && apk add --no-cache \
        icu-libs \
        libzip \
        oniguruma \
        sqlite-libs \
        unzip \
        git \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        pdo_sqlite \
        mbstring \
        intl \
        zip \
        bcmath \
        opcache \
    && apk del .build-deps

# Composer 2, taken directly from the official Composer image rather than
# installed via a download script.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies from composer.json/composer.lock alone first, so
# this (slow) layer is Docker-cached and only re-runs when dependencies
# actually change. --no-scripts/--no-autoloader here because the rest of
# the application (artisan, config/, etc.) has not been copied in yet, and
# several Composer scripts (e.g. `artisan package:discover`) require it.
COPY composer.json composer.lock ./
RUN composer install \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader

# Now copy the application itself. Composer dependencies are installed
# without --no-dev, so PHPUnit and Pint remain available inside the
# container for running the test suite and code-style checks (this is a
# development / code-assessment image, not a stripped production image).
# The optimized/classmap autoloader is generated once the full
# application is present.
COPY . .

RUN composer install \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
    && mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# No further ownership changes are needed: PHP-FPM's stock www.conf
# already runs worker processes as the unprivileged `www-data` user/group
# (only the short-lived master process stays root, purely to bind the
# listening port), and www-data only needs write access to storage/ and
# bootstrap/cache/, which is granted above. Application files elsewhere
# remain root-owned but world-readable, which is all PHP-FPM needs.

# .env is intentionally never copied into the image (see .dockerignore) -
# configuration is supplied at container start via docker-compose.yml.

EXPOSE 9000

# No custom entrypoint script: the base image's own CMD ("php-fpm") is
# used as-is. Migrations, seeding, and key:generate are explicit,
# documented commands (see the "Docker setup" section of README.md)
# rather than being run automatically whenever a container starts.
