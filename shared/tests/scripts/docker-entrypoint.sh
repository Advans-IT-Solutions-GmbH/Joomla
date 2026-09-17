#!/bin/bash
set -e

echo "=== J2Commerce Test Environment Setup ==="
echo "Extension: ${EXTENSION_NAME:-unknown}"
echo "=========================================="

/entrypoint.sh apache2-foreground &
JOOMLA_PID=$!

echo "Waiting for Joomla to initialize..."
TIMEOUT=120
ELAPSED=0
while [ ! -f /var/www/html/configuration.php ] && [ $ELAPSED -lt $TIMEOUT ]; do
    sleep 5
    ELAPSED=$((ELAPSED + 5))
    echo "  Waiting... ($ELAPSED/$TIMEOUT seconds)"
done

# configuration.php appears before the official entrypoint has finished its
# setup. Installing extensions in that window could lose registrations, so wait
# until the installation folder is gone and the site answers.
if [ -f /var/www/html/configuration.php ]; then
    READY_ELAPSED=0
    until [ ! -d /var/www/html/installation ] && php -r 'exit(@file_get_contents("http://localhost/") === false ? 1 : 0);'; do
        if [ $READY_ELAPSED -ge 120 ]; then
            echo "ERROR: Joomla setup did not finish within 120 seconds"
            exit 1
        fi
        sleep 2
        READY_ELAPSED=$((READY_ELAPSED + 2))
    done
    echo "Joomla setup finished"
fi


if [ -f /var/www/html/configuration.php ]; then
    echo "Joomla is initialized, installing extension..."
    sleep 5
    
    echo "Installing extension via Joomla CLI..."
    cp /tmp/extension.zip /var/www/html/tmp/extension.zip
    if HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/extension.zip; then
        echo "✅ Extension installed via Joomla CLI"
    else
        echo "❌ Extension installation FAILED via Joomla CLI"
        exit 1
    fi
    
    # Enable all newly installed plugins (disabled by default in Joomla)
    echo "Enabling installed extensions..."
    DB_PREFIX=$(php -r "require '/var/www/html/configuration.php'; echo (new JConfig)->dbprefix;" 2>/dev/null || echo "j_")
    echo "DB prefix: ${DB_PREFIX}"
    mysql -h "${JOOMLA_DB_HOST:-mysql}" -u "${JOOMLA_DB_USER:-joomla}" -p"${JOOMLA_DB_PASSWORD:-joomla_pass}" "${JOOMLA_DB_NAME:-joomla_db}" \
        -e "UPDATE ${DB_PREFIX}extensions SET enabled = 1 WHERE enabled = 0 AND type = 'plugin';" 2>&1 \
        && echo "✅ Extensions enabled" \
        || echo "⚠️ Could not enable extensions via DB"
    
    echo "OK" > /var/www/html/health.txt
    chown www-data:www-data /var/www/html/health.txt 2>/dev/null || true
    echo "✅ Health file created"
fi

wait $JOOMLA_PID
