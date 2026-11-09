#!/bin/bash
set -euo pipefail

/entrypoint.sh apache2-foreground &
joomla_pid=$!

ready=0
for attempt in $(seq 1 120); do
    if [ -f /var/www/html/configuration.php ] && [ ! -d /var/www/html/installation ] \
        && curl --fail --silent --output /dev/null http://localhost/; then
        ready=1
        break
    fi
    if ! kill -0 "$joomla_pid" 2>/dev/null; then
        wait "$joomla_pid"
        exit 1
    fi
    sleep 2
done
if [ "$ready" -ne 1 ]; then
    echo "ERROR: Native Joomla installation did not become ready" >&2
    exit 1
fi

HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install \
    --path=/tmp/extension.zip --no-interaction

# Do not enable plugins, Scheduler tasks or mail as part of package installation.
printf 'OK\n' > /var/www/html/health.txt
wait "$joomla_pid"
