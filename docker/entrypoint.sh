#!/bin/sh
# context-shuttle container entrypoint.
#
# Secrets come from the environment (never baked into the image), per
# Guiding Light §8.12. In prod, APP_SECRET must be provided at runtime.

set -e

# taskwarrior state (taskrc + database) lives under /data/taskwarrior,
# owned by `app`. The image seeds both; a bind mount may start empty or
# root-owned, so recreate and chown before the app starts.
#
# The taskrc is REGENERATED on every boot (#42): sync settings are
# injected from the environment here, so a change to the env is picked
# up by the next restart, and taskwarrior — which reads sync.* from the
# taskrc, not the environment — always runs with the current config.
# Env wins over the file for the managed lines (data.location,
# confirmation, color, verbose, news.version, sync.*); any other lines
# a user has written to the taskrc are preserved.
TASKRC_PATH="${TASKRC:-/data/taskwarrior/taskrc}"
TASKDATA_DIR="${TASKDATA:-/data/taskwarrior}"
mkdir -p "$(dirname "$TASKRC_PATH")" "$TASKDATA_DIR"

SYNC_VARS="TASKCHAMPION_SYNC_URL TASKCHAMPION_SYNC_CLIENT_ID TASKCHAMPION_SYNC_ENCRYPTION_SECRET"
SYNC_SET=$(for v in $SYNC_VARS; do
    [ -n "$(eval "printf '%s' \"\${$v:-}\"")" ] && printf '%s ' "$v"
done)

# All three, or none. A partially-configured sync produces exactly the
# silent-failure mode #42 chased for hours: taskwarrior refuses to sync
# with a confusing error while the tools report `configured: true`.
case "$SYNC_SET" in
    '' ) ;;
    'TASKCHAMPION_SYNC_URL TASKCHAMPION_SYNC_CLIENT_ID TASKCHAMPION_SYNC_ENCRYPTION_SECRET' ) ;;
    * )
        echo "ERROR: taskwarrior sync is partially configured (set: $SYNC_SET)." >&2
        echo "       Set all of TASKCHAMPION_SYNC_URL, TASKCHAMPION_SYNC_CLIENT_ID and" >&2
        echo "       TASKCHAMPION_SYNC_ENCRYPTION_SECRET, or none of them." >&2
        exit 1
        ;;
esac

if [ ! -f "$TASKRC_PATH" ]; then
    {
        printf 'data.location=%s\n' "$TASKDATA_DIR"
        printf 'confirmation=off\ncolor=off\nverbose=nothing\n'
        # Without this, `task` prints its version news banner on stdout,
        # which the JSON-parsing tools would choke on.
        [ -n "${TASKWARRIOR_VERSION:-}" ] && printf 'news.version=%s\n' "$TASKWARRIOR_VERSION"
    } > "$TASKRC_PATH"
fi

# Managed lines: rewritten from env on every boot. Anything else in the
# file survives untouched.
remove_config_lines() {
    # grep -v exits 1 when nothing survives the filter — that is a valid
    # (empty) result here, not a failure.
    grep -v -E '^(data\.location|confirmation|color|verbose|news\.version|sync\.server\.url|sync\.server\.client_id|sync\.encryption_secret)=' "$1" 2>/dev/null || true
}

{
    remove_config_lines "$TASKRC_PATH"
    printf 'data.location=%s\n' "$TASKDATA_DIR"
    printf 'confirmation=off\ncolor=off\nverbose=nothing\n'
    [ -n "${TASKWARRIOR_VERSION:-}" ] && printf 'news.version=%s\n' "$TASKWARRIOR_VERSION"
    if [ -n "${TASKCHAMPION_SYNC_URL:-}" ]; then
        printf 'sync.server.url=%s\n' "$TASKCHAMPION_SYNC_URL"
        printf 'sync.server.client_id=%s\n' "$TASKCHAMPION_SYNC_CLIENT_ID"
        printf 'sync.encryption_secret=%s\n' "$TASKCHAMPION_SYNC_ENCRYPTION_SECRET"
    fi
} > "$TASKRC_PATH.tmp" && mv "$TASKRC_PATH.tmp" "$TASKRC_PATH"

if [ "$(id -u)" = "0" ]; then
    chown -R app:app /data/taskwarrior
fi

# Warm the Symfony cache on container start (compile container + routes
# + tool registry) so the first request is not the first compile.
if [ "$APP_ENV" = "prod" ]; then
    php bin/console cache:warmup --env=prod
fi

exec "$@"
