#!/bin/sh
# context-shuttle container entrypoint.
#
# Secrets come from the environment (never baked into the image), per
# Guiding Light §8.12. In prod, APP_SECRET must be provided at runtime.

set -e

# Warm the Symfony cache on container start (compile container + routes
# + tool registry) so the first request is not the first compile.
if [ "$APP_ENV" = "prod" ]; then
    php bin/console cache:warmup --env=prod
fi

exec "$@"