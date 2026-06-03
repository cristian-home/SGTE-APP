# =============================================================================
# Stage 1: Install PHP dependencies
# =============================================================================
FROM composer:latest AS composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs

# =============================================================================
# Stage 2: Base image with PHP extensions and Node.js
# =============================================================================
FROM dunglas/frankenphp:1-php8.5-trixie AS base

# Install PHP extensions
# NOTE: `msgpack` removed — PECL has no PHP 8.5-compatible release
# ("No releases available for pecl.php.net/msgpack"), which broke the base
# image build once the moving frankenphp:1-php8.5-trixie tag advanced. The
# app does not require ext-msgpack (no composer constraint, no serializer
# config); igbinary covers fast serialization.
RUN install-php-extensions \
    pcntl \
    pgsql \
    pdo_pgsql \
    redis \
    gd \
    intl \
    bcmath \
    zip \
    mbstring \
    xml \
    curl \
    igbinary \
    imagick \
    exif

# Install Node.js runtime and Supervisor
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        supervisor \
        curl \
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Install the PostgreSQL 18 client (pg_dump) used by spatie/laravel-backup.
# Debian Trixie ships client 17 in its repos, and pg_dump 17 cannot dump a
# v18 server, so we pull postgresql-client-18 from the official PGDG repo.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates gnupg \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt trixie-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-18 \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# =============================================================================
# Stage 3: Build frontend assets (requires PHP for Wayfinder route generation)
# =============================================================================
FROM base AS build

WORKDIR /app

# Vite build-time env vars: these are baked into the JS bundle at build time
# and cannot be changed at container start. Set them in Dokploy via "Build
# Args" (or `docker build --build-arg VITE_X=…`). Defaults match .env.example
# so a build with no overrides still produces a working bundle for local dev.
ARG VITE_APP_NAME=SGTE
ARG VITE_APP_DEBUG=false
ARG VITE_REVERB_APP_KEY=
ARG VITE_REVERB_HOST=
ARG VITE_REVERB_PORT=
ARG VITE_REVERB_SCHEME=
ARG VITE_GOOGLE_MAPS_BROWSER_KEY=
ARG VITE_GOOGLE_MAPS_MAP_ID=
# Master switch for Google Maps (dynamic maps + geocoding). Defaults to
# enabled; set "false" in a build (e.g. staging) to ship placeholders and
# make zero Google requests. See docs/google-maps-cost-controls.md.
ARG VITE_GOOGLE_MAPS_ENABLED=true
ENV VITE_APP_NAME=$VITE_APP_NAME \
    VITE_APP_DEBUG=$VITE_APP_DEBUG \
    VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME \
    VITE_GOOGLE_MAPS_BROWSER_KEY=$VITE_GOOGLE_MAPS_BROWSER_KEY \
    VITE_GOOGLE_MAPS_MAP_ID=$VITE_GOOGLE_MAPS_MAP_ID \
    VITE_GOOGLE_MAPS_ENABLED=$VITE_GOOGLE_MAPS_ENABLED

# Copy application source code and PHP dependencies
COPY . .
COPY --from=composer /app/vendor ./vendor

# Install npm dependencies and build frontend assets
# Wayfinder Vite plugin runs `php artisan wayfinder:generate` during build
RUN npm ci && npm run build:ssr

# =============================================================================
# Stage 4: Production image
# =============================================================================
FROM base AS production

WORKDIR /app

# Copy application source code
COPY . .

# Copy vendor from composer stage
COPY --from=composer /app/vendor ./vendor

# Copy built frontend assets from build stage
COPY --from=build /app/public/build ./public/build
COPY --from=build /app/bootstrap/ssr ./bootstrap/ssr

# Copy production config files
COPY docker/production/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/production/start-container /usr/local/bin/start-container
COPY docker/production/php-uploads.ini /usr/local/etc/php/conf.d/php-uploads.ini
RUN chmod +x /usr/local/bin/start-container

# Create required directories
RUN mkdir -p \
    storage/logs \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache

# Cache views and events (these don't depend on runtime env vars)
RUN php artisan view:cache \
    && php artisan event:cache

# Create non-root user
RUN groupadd --force -g 1000 app \
    && useradd -ms /bin/bash --no-user-group -g 1000 -u 1000 app \
    && chown -R app:app /app

EXPOSE 8000
EXPOSE 8080

ENTRYPOINT ["start-container"]
