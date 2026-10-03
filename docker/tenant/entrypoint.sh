#!/bin/sh
# Start one tenant's php-fpm and nginx.
#
# Runs as the tenant's uid with no shell for it in /etc/passwd, so nothing here
# may assume a home directory or a username.

set -eu

DOCROOT="${AIPANEL_DOCROOT:-public}"
LISTEN="${AIPANEL_LISTEN:-0.0.0.0:8080}"

# The release directory is a symlink the host swaps on deploy; resolve it at
# startup only, so a deploy mid-request cannot change what this process serves.
if [ ! -d /app/current ]; then
    echo "aipanel: /app/current is missing — this site has no release yet" >&2
    # Serve a holding page rather than crash-looping, so the edge gets a clean
    # 503 instead of a connection refused.
    mkdir -p /tmp/holding
    printf 'This site has no deployment yet.\n' > /tmp/holding/index.html
    export AIPANEL_ROOT=/tmp/holding
else
    export AIPANEL_ROOT="/app/current/${DOCROOT}"
fi

export AIPANEL_LISTEN="$LISTEN"

# nginx.conf reads both via envsubst, because nginx cannot use environment
# variables in most directives. The names are single-quoted deliberately:
# envsubst must receive them literally, and substitute only these two, so a
# tenant's own $variables in the config are left alone.
# shellcheck disable=SC2016
envsubst '${AIPANEL_ROOT} ${AIPANEL_LISTEN}' \
    < /etc/nginx/nginx.conf > /tmp/nginx.conf

php-fpm --daemonize --fpm-config /usr/local/etc/php-fpm.conf
exec nginx -c /tmp/nginx.conf -g 'daemon off;'
