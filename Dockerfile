# Pinned to the patch release: PHP 8.4.27 fixes CVE-2026-109618/-109619 (remote code execution via PHP-FPM)
FROM serversideup/php:8.5.0-fpm-nginx-alpine

USER root

# Never build on a PHP older than the security fix, whatever the tag above resolves to
RUN php -r 'exit(version_compare(PHP_VERSION, "8.4.27", "<") ? 1 : 0);' || (echo "PHP $(php -r 'echo PHP_VERSION;') is too old, 8.4.27 or newer is required" && exit 1)

# Defaults for production; can be overridden in Coolify.
# LOG_CHANNEL=stderr makes Laravel errors visible in the container runtime logs.
ENV AUTORUN_ENABLED=true \
    LOG_CHANNEL=stderr \
    PHP_OPCACHE_ENABLE=1

# Security fixes of the Alpine packages (e.g. libX11 for gd, nghttp2) that the base image doesn't have yet,
# then Node.js (LTS) and PHP extensions (intl; gd + exif for avatar resizing; gmp for the web push encryption)
RUN apk upgrade --no-cache && \
    apk add --no-cache nodejs npm && \
    install-php-extensions intl gd exif gmp

WORKDIR /var/www/html

# Copy application files
COPY --chown=www-data:www-data . .

# Install PHP dependencies
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --prefer-dist

# Install Node.js dependencies and build frontend assets
# Node is only needed for the build; it does not stay in the running image
RUN npm ci --silent && npm run build && rm -rf node_modules && apk del nodejs npm

# Custom startup scripts (run after serversideup's migrations, see 50-laravel-automations)
COPY --chmod=755 docker/entrypoint.d/ /etc/entrypoint.d/
RUN docker-php-serversideup-s6-init

# Laravel scheduler as an extra s6 service, so no separate scheduler container is needed.
# s6-overlay 3.x: register service via user-bundles.d (user/contents.d in s6-rc.d is read-only at runtime).
COPY --chmod=755 docker/s6-rc.d/ /etc/s6-overlay/s6-rc.d/
RUN mkdir -p /etc/s6-overlay/user-bundles.d && \
    touch /etc/s6-overlay/user-bundles.d/laravel-scheduler

# Ensure writable directories are owned by web user
RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data
