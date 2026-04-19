#!/bin/bash
# Runs once during CI to install Joomla + OSMap + J2Commerce into the container.
# The result is committed as a snapshot image to ghcr.io.
set -e

DB_HOST="${JOOMLA_DB_HOST:-mysql}"
DB_USER="${JOOMLA_DB_USER:-joomla}"
DB_PASS="${JOOMLA_DB_PASSWORD:-joomla_pass}"
DB_NAME="${JOOMLA_DB_NAME:-joomla_db}"

echo "=== Installing Joomla via CLI ==="
php /var/www/html/cli/joomla.php installation:install \
    --site-name="Test Site" \
    --admin-user="admin" \
    --admin-username="admin" \
    --admin-password="Admin1234!" \
    --admin-email="admin@test.local" \
    --db-type="mysqli" \
    --db-host="$DB_HOST" \
    --db-user="$DB_USER" \
    --db-pass="$DB_PASS" \
    --db-name="$DB_NAME" \
    --db-prefix="j_" \
    2>&1
echo "✅ Joomla installed"

echo "=== Installing OSMap Free ==="
cp /tmp/com_osmap.zip /var/www/html/tmp/com_osmap.zip
php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/com_osmap.zip 2>&1
echo "✅ OSMap installed"

echo "=== Installing J2Commerce ==="
cp /tmp/com_j2store.zip /var/www/html/tmp/com_j2store.zip
php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/com_j2store.zip 2>&1
echo "✅ J2Commerce installed"

echo "=== Enabling plugins ==="
DB_PREFIX=$(php -r "require '/var/www/html/configuration.php'; echo (new JConfig)->dbprefix;" 2>/dev/null || echo "j_")
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -e "UPDATE ${DB_PREFIX}extensions SET enabled=1 WHERE type='plugin' AND enabled=0;" 2>/dev/null
echo "✅ Plugins enabled"

echo "=== Base setup complete ==="
