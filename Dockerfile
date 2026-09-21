# context-shuttle — single-stage FrankenPHP image.
#
# Runtime: FrankenPHP serves public/ on :80 inside the container, non-root,
# state on the /data volume. TLS is terminated upstream of the container.
#
# Secrets are injected at runtime as env vars, never baked in (§8.12).

FROM dunglas/frankenphp:1-php8.5-trixie

# Runtime set: ca-certificates (TLS for outbound tool calls), curl
# (HEALTHCHECK), git (composer needs it to resolve the php-mcp/server
# VCS repository — though vendor/ ships pre-built in CI-built images,
# keep the image self-sufficient).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git \
    && rm -rf /var/lib/apt/lists/*

# PHP configuration and FrankenPHP/Caddy app config
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Application source (docs/ and tests/ are excluded by .dockerignore;
# docker/ stays in the context for the COPYs above)
COPY --link . /app

WORKDIR /app

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
ENV APP_RUNTIME=FrankenPhpSymfonyRuntime
ENV SERVER_NAME=:80

EXPOSE 80

# Liveness (/health — no deps). Readiness (/ready) is the orchestrator's
# job, not the container's (§8.4).
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]