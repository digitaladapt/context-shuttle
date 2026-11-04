#!/bin/sh
# context-shuttle container entrypoint.
#
# Secrets come from the environment (never baked into the image), per
# Guiding Light §8.12. In prod, APP_SECRET must be provided at runtime.

set -e

# taskwarrior state (taskrc + database) lives under /data/taskwarrior,
# owned by `app`. The image seeds both; a bind mount may start empty or
# root-owned, so recreate and chown before the app starts. A taskrc that
# already exists is left untouched — the user's settings win.
TASKRC_PATH="${TASKRC:-/data/taskwarrior/taskrc}"
TASKDATA_DIR="${TASKDATA:-/data/taskwarrior}"
if [ ! -f "$TASKRC_PATH" ]; then
    mkdir -p "$(dirname "$TASKRC_PATH")"
    {
        printf 'data.location=%s\n' "$TASKDATA_DIR"
        printf 'confirmation=off\ncolor=off\nverbose=nothing\n'
        # Without this, `task` prints its version news banner on stdout,
        # which the JSON-parsing tools would choke on.
        [ -n "${TASKWARRIOR_VERSION:-}" ] && printf 'news.version=%s\n' "$TASKWARRIOR_VERSION"
    } > "$TASKRC_PATH"
fi
mkdir -p "$TASKDATA_DIR"
if [ "$(id -u)" = "0" ]; then
    chown -R app:app /data/taskwarrior
fi

# Warm the Symfony cache on container start (compile container + routes
# + tool registry) so the first request is not the first compile.
if [ "$APP_ENV" = "prod" ]; then
    php bin/console cache:warmup --env=prod
fi

exec "$@"