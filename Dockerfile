# syntax=docker/dockerfile:1

# PHP 8.3 CLI on Debian. The minor tag is pinned here for readability; the README documents
# pinning by immutable digest (php:8.3-cli@sha256:...) as the stronger reproducibility guarantee.
FROM php:8.3-cli

# System packages Composer needs: git to fetch sources, unzip/zip to extract archives fast.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Composer from its official image, pinned to the v2 line.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Run as a non-root user whose UID/GID match the host developer, so files written through the
# bind mount (vendor/, composer.lock, caches) stay owned by the developer rather than root.
ARG UID=1000
ARG GID=1000
RUN groupadd --gid ${GID} app \
    && useradd --uid ${UID} --gid ${GID} --create-home app

USER app
WORKDIR /app

# Composer cache lives on a named volume (see docker-compose.yml) to speed up repeat installs.
ENV COMPOSER_CACHE_DIR=/tmp/composer-cache
