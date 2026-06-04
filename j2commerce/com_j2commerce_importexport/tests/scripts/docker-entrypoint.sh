#!/bin/bash
set -e

echo "=== J2Commerce Import/Export Test Environment Setup ==="
echo "Extension: ${EXTENSION_NAME:-J2Commerce Import/Export}"
echo "======================================================="

# Run original Joomla entrypoint
/entrypoint.sh apache2-foreground &
JOOMLA_PID=$!

# Wait for Joomla to be fully initialized (configuration.php created)
echo "Waiting for Joomla to initialize..."
TIMEOUT=120
ELAPSED=0
while [ ! -f /var/www/html/configuration.php ] && [ $ELAPSED -lt $TIMEOUT ]; do
    sleep 5
    ELAPSED=$((ELAPSED + 5))
    echo "  Waiting... ($ELAPSED/$TIMEOUT seconds)"
done

if [ ! -f /var/www/html/configuration.php ]; then
    echo "❌ Joomla did not initialize within $TIMEOUT seconds"
    exit 1
fi

echo "✅ Joomla is initialized"
sleep 5

echo "Installing extension via Joomla CLI..."
cp /tmp/extension.zip /var/www/html/tmp/extension.zip
if HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/extension.zip; then
    echo "✅ Extension installed via Joomla CLI"
else
    echo "❌ Extension installation FAILED via Joomla CLI"
    exit 1
fi

# Enable all newly installed extensions
echo "Enabling installed extensions..."
DB_PREFIX=$(php -r "require '/var/www/html/configuration.php'; echo (new JConfig)->dbprefix;" 2>/dev/null || echo "j_")
echo "DB prefix: ${DB_PREFIX}"
mysql -h "${JOOMLA_DB_HOST:-mysql}" -u "${JOOMLA_DB_USER:-joomla}" -p"${JOOMLA_DB_PASSWORD:-joomla_pass}" "${JOOMLA_DB_NAME:-joomla_db}" \
    -e "UPDATE ${DB_PREFIX}extensions SET enabled = 1 WHERE enabled = 0 AND type = 'plugin';" 2>&1 \
    && echo "✅ Extensions enabled" \
    || echo "⚠️ Could not enable extensions via DB"

# Install J2Commerce stub tables so round-trip tests run instead of being skipped
echo "Installing J2Commerce stub tables..."
sed "s/#__/${DB_PREFIX}/g" /tmp/j2commerce-stubs.sql | \
    mysql -h "${JOOMLA_DB_HOST:-mysql}" -u "${JOOMLA_DB_USER:-joomla}" -p"${JOOMLA_DB_PASSWORD:-joomla_pass}" "${JOOMLA_DB_NAME:-joomla_db}" 2>&1 \
    && echo "✅ J2Commerce stub tables installed" \
    || echo "⚠️ Could not install J2Commerce stub tables"

echo "OK" > /var/www/html/health.txt
chown www-data:www-data /var/www/html/health.txt 2>/dev/null || true
echo "✅ Container ready"

wait $JOOMLA_PID
