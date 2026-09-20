FROM dunglas/frankenphp:1-php8.5-trixie

# Persistent data (sessions, logs) under /data
VOLUME /data

# PHP configuration
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

# Application source (docker/ and docs/ are excluded by .dockerignore)
COPY --link . /app

WORKDIR /app

# Never run as root (Guiding Light §6.4)
RUN chown -R www-data:www-data /data /app/var || true

ENV APP_ENV=prod
ENV APP_RUNTIME=FrankenPhpSymfonyRuntime

# Secrets are injected at runtime as env vars, never baked in (§8.12)
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]