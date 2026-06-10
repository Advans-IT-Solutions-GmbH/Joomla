#!/bin/bash
set -e

echo "=== Joomla AJAX Forms Test Environment Setup ==="
echo "Extension: ${EXTENSION_NAME:-unknown}"
echo "================================================="

/entrypoint.sh apache2-foreground &
JOOMLA_PID=$!

echo "Waiting for Joomla to initialize..."
TIMEOUT=240
ELAPSED=0
while [ ! -f /var/www/html/configuration.php ] && [ $ELAPSED -lt $TIMEOUT ]; do
    sleep 5
    ELAPSED=$((ELAPSED + 5))
    echo "  Waiting... ($ELAPSED/$TIMEOUT seconds)"
done

if [ -f /var/www/html/configuration.php ]; then
    echo "Joomla is initialized, installing extension..."
    sleep 5

    echo "Installing extension via Joomla CLI..."
    cp /tmp/extension.zip /var/www/html/tmp/extension.zip
    if php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/extension.zip; then
        echo "✅ Extension installed via Joomla CLI"
    else
        echo "❌ Extension installation FAILED via Joomla CLI"
        echo "FAILED" > /var/www/html/health.txt
        chown www-data:www-data /var/www/html/health.txt 2>/dev/null || true
        wait $JOOMLA_PID
        exit 1
    fi

    echo "Enabling installed extensions..."
    DB_PREFIX=$(php -r "require '/var/www/html/configuration.php'; echo (new JConfig)->dbprefix;" 2>/dev/null || echo "j_")
    echo "DB prefix: ${DB_PREFIX}"
    mysql -h "${JOOMLA_DB_HOST:-mysql}" -u "${JOOMLA_DB_USER:-joomla}" -p"${JOOMLA_DB_PASSWORD:-joomla_pass}" "${JOOMLA_DB_NAME:-joomla_db}" \
        -e "UPDATE ${DB_PREFIX}extensions SET enabled = 1 WHERE enabled = 0 AND type = 'plugin';" 2>&1 \
        && echo "✅ Extensions enabled" \
        || echo "⚠️ Could not enable extensions via DB"
else
    echo "❌ Joomla did not initialize within ${TIMEOUT}s — configuration.php not found"
    echo "FAILED" > /var/www/html/health.txt
    chown www-data:www-data /var/www/html/health.txt 2>/dev/null || true
    wait $JOOMLA_PID
    exit 1
fi

echo "OK" > /var/www/html/health.txt
chown www-data:www-data /var/www/html/health.txt 2>/dev/null || true
echo "✅ Health file created"

wait $JOOMLA_PID
