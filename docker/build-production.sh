#!/bin/sh
set -u
cd /opt/mito || exit 1
COMPOSE_PARALLEL_LIMIT=1 docker compose -f compose.yaml -f compose.production.yaml build --no-cache app web
result=$?
printf '%s\n' "$result" > /tmp/mito-build.exit
exit "$result"
