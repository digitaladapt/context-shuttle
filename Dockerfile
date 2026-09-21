# syntax=docker/dockerfile:1.7
#
# context-shuttle — multi-stage FrankenPHP image.
#
# Runtime: FrankenPHP serves public/ on :80 inside the container, non-root,
# state on the /data volume. TLS is terminated upstream of the container.
#
# Secrets are injected at runtime as env vars, never baked in (§8.12).

# ── Stage: deps — composer dependencies (layer-cached) ─────────────────────
FROM dunglas/frankenphp:1-php8.5-trixie AS deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Build-time set: git (composer needs it to resolve the php-mcp/server VCS
# repository) and unzip (dist extraction). Neither reaches the runtime image.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# Manifests first so dependency layers only rebuild when they change.
# .dockerignore excludes host vendor/, so the image builds its own —
# a locally built image is as valid as a CI-built one.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist \
    --optimize-autoloader --no-scripts

# ── Stage: build — full app + prod autoloader ──────────────────────────────
FROM deps AS build

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev \
    && rm -rf var/cache/* var/log/*

# Build-time smoke of the autoloader + config compile. APP_SECRET is a
# placeholder — secrets are never baked in (§8.12); the real warm-up runs
# at container start with injected secrets (entrypoint).
RUN APP_ENV=prod APP_SECRET=build-secret bin/console cache:warmup || true \
    && rm -rf var/cache/*

# ── Stage: app — the runtime image ─────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.5-trixie AS app

# Runtime set: ca-certificates (TLS for outbound tool calls) and curl
# (HEALTHCHECK). No git/composer here — everything is compiled already.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*

# PHP configuration and FrankenPHP/Caddy app config
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /app
COPY --from=build /app /app

# Non-root runtime user (Guiding Light §6.4). uid/gid 1000, same
# convention as task-loom/task-weaver.
RUN groupadd --system --gid 1000 app \
 && useradd  --system --uid 1000 --gid app \
             --home-dir /app --shell /usr/sbin/nologin app \
 && mkdir -p /app/var /data \
 && chown -R app:app /app/var /data

USER app

# Persistent data (sessions, logs) under /data
VOLUME /data

ENV APP_ENV=prod
ENV SERVER_NAME=:80

EXPOSE 80

# Liveness (/health — no deps). Readiness (/ready) is the orchestrator's
# job, not the container's (§8.4).
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]