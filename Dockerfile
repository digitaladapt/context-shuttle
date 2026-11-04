# syntax=docker/dockerfile:1.7
#
# context-shuttle — multi-stage FrankenPHP image.
#
# Runtime: FrankenPHP serves public/ on :80 inside the container, non-root,
# state on the /data volume. TLS is terminated upstream of the container.
#
# Secrets are injected at runtime as env vars, never baked in (§8.12).

# ── Stage: deps — composer dependencies (layer-cached) ────────────────────
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

# ── Stage: taskwarrior — compile taskwarrior v3 from source ────────────────
FROM rust:1-slim-trixie AS taskwarrior-build

# Build dependencies for taskwarrior v3 (CMake + C++17 compiler).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        cmake \
        curl \
        g++ \
        git \
        make \
        ninja-build \
        uuid-dev \
        libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Fetch and compile taskwarrior v3 from the official release tarball.
# Pinning to a specific release makes the image reproducible.
ARG TASKWARRIOR_VERSION=3.5.0
RUN curl -fsSL "https://github.com/GothenburgBitFactory/taskwarrior/releases/download/v${TASKWARRIOR_VERSION}/task-${TASKWARRIOR_VERSION}.tar.gz" -o /tmp/task.tar.gz \
    && tar -xzf /tmp/task.tar.gz -C /tmp \
    && mkdir -p /tmp/task-${TASKWARRIOR_VERSION}/build \
    && cmake -S /tmp/task-${TASKWARRIOR_VERSION} -B /tmp/task-${TASKWARRIOR_VERSION}/build \
           -DCMAKE_BUILD_TYPE=Release \
           -DCMAKE_INSTALL_PREFIX=/usr/local \
           -DBUILD_TESTING=OFF \
    && cmake --build /tmp/task-${TASKWARRIOR_VERSION}/build --parallel \
    && cmake --install /tmp/task-${TASKWARRIOR_VERSION}/build

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

# Needed in this stage to stamp news.version in the generated taskrc (see
# below); must match the version the taskwarrior-build stage compiles.
ARG TASKWARRIOR_VERSION=3.5.0
# Exposed at runtime so the entrypoint can stamp news.version in a
# regenerated taskrc (a missing news.version makes `task` print its
# version news banner to stdout, which the JSON tools would choke on).
ENV TASKWARRIOR_VERSION=${TASKWARRIOR_VERSION}

# Runtime set: ca-certificates (TLS for outbound tool calls), curl
# (HEALTHCHECK), and taskwarrior (task CLI for taskwarrior tools).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
    && rm -rf /var/lib/apt/lists/*

# Copy the compiled taskwarrior binary from the build stage, plus its
# runtime data files. The binary's include search path is compiled in as
# ${CMAKE_INSTALL_PREFIX}/share/doc/task/rc (cmake.h.in), and the built-in
# configuration defaults contain `include default.theme` unconditionally —
# without these 51 files every `task` invocation exits 2 with "Could not
# find file ... 'default.theme'", no matter what the user taskrc says.
COPY --from=taskwarrior-build /usr/local/bin/task /usr/local/bin/task
COPY --from=taskwarrior-build /usr/local/share/doc/task/rc /usr/local/share/doc/task/rc

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
 && mkdir -p /app/var /data/taskwarrior \
 && printf 'data.location=/data/taskwarrior\nconfirmation=off\ncolor=off\nverbose=nothing\nnews.version=%s\n' "$TASKWARRIOR_VERSION" > /data/taskwarrior/taskrc \
 && chown -R app:app /app/var /data

USER app

# taskwarrior state (taskrc + task database) lives under the persistent
# volume, owned by `app` — never root, and never in the read-only app tree.
# A named volume seeded from the image inherits the file and its ownership;
# the entrypoint recreates both if a bind mount starts empty.
ENV TASKRC=/data/taskwarrior/taskrc
ENV TASKDATA=/data/taskwarrior

# Persistent data (sessions, logs, taskwarrior state) under /data
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
