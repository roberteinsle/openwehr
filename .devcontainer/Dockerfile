# Development image for GitHub Codespaces / VS Code Dev Containers.
# Production uses the root Dockerfile (serversideup/php); this one adds the
# same PHP extensions to the Microsoft devcontainer base with its "vscode" user.
FROM mcr.microsoft.com/devcontainers/php:8.5

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev libicu-dev libzip-dev \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
        postgresql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl gd exif zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
