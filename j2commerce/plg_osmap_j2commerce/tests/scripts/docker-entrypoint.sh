#!/bin/bash
set -e

echo "=== J2Commerce Test Environment Setup ==="
echo "Extension: ${EXTENSION_NAME:-unknown}"
echo "=========================================="

# Start Joomla in background
/entrypoint.sh apache2-foreground &
JOOMLA_PID=$!

echo "Waiting for Joomla to initialize..."
TIMEOUT=180
ELAPSED=0
while [ ! -f /var/www/html/configuration.php ] && [ $ELAPSED -lt $TIMEOUT ]; do
    sleep 5
    ELAPSED=$((ELAPSED + 5))
    echo "  Waiting... ($ELAPSED/${TIMEOUT}s)"
done

if [ ! -f /var/www/html/configuration.php ]; then
    echo "ERROR: Joomla did not initialize within ${TIMEOUT}s"
    exit 1
fi

echo "Joomla initialized."
sleep 3

DB_HOST="${JOOMLA_DB_HOST:-mysql}"
DB_USER="${JOOMLA_DB_USER:-joomla}"
DB_PASS="${JOOMLA_DB_PASSWORD:-joomla_pass}"
DB_NAME="${JOOMLA_DB_NAME:-joomla_db}"

install_extension() {
    local zip="$1"
    local label="$2"
    cp "$zip" /var/www/html/tmp/install_pkg.zip
    if php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/install_pkg.zip 2>&1; then
        echo "✅ $label installed"
    else
        echo "❌ $label installation FAILED"
        return 1
    fi
}

# Signal that Joomla is up — tests can start polling for ready.txt
echo "JOOMLA_OK" > /var/www/html/health.txt
chown www-data:www-data /var/www/html/health.txt 2>/dev/null || true

echo ""
echo "--- Installing OSMap Free ---"
install_extension /tmp/com_osmap.zip "OSMap Free"

echo ""
echo "--- Installing J2Commerce ---"
install_extension /tmp/com_j2store.zip "J2Commerce"

echo ""
echo "--- Installing plg_osmap_j2commerce ---"
install_extension /tmp/plg_osmap_j2commerce.zip "plg_osmap_j2commerce"

echo ""
echo "--- Enabling plugins ---"
DB_PREFIX=$(php -r "require '/var/www/html/configuration.php'; echo (new JConfig)->dbprefix;" 2>/dev/null || echo "j_")
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -e "UPDATE ${DB_PREFIX}extensions SET enabled = 1 WHERE type = 'plugin' AND enabled = 0;" 2>/dev/null \
    && echo "✅ Plugins enabled" || echo "⚠️  Could not enable plugins"

echo ""
echo "--- Inserting test fixtures ---"

ROOT_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -se "SELECT id FROM ${DB_PREFIX}menu WHERE menutype='mainmenu' AND parent_id=1 AND client_id=0 LIMIT 1;" 2>/dev/null || echo "")
if [ -z "$ROOT_ID" ]; then
    ROOT_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
        -se "SELECT id FROM ${DB_PREFIX}menu WHERE parent_id=0 AND client_id=0 LIMIT 1;" 2>/dev/null || echo "1")
fi

COM_CONTENT_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -se "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_content' AND type='component' LIMIT 1;" 2>/dev/null || echo "0")
COM_J2STORE_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -se "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_j2store' AND type='component' LIMIT 1;" 2>/dev/null || echo "0")

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null <<SQL
INSERT INTO ${DB_PREFIX}menu
    (id, menutype, title, alias, link, type, published, parent_id, level, component_id, browserNav, access, img, template_style_id, params, lft, rgt, home, language, client_id)
VALUES
    (9001, 'mainmenu', 'Shop', 'shop',
     'index.php?option=com_j2store&view=products',
     'component', 1, $ROOT_ID, 2, $COM_J2STORE_ID,
     0, 1, '', 0, '{}', 100, 105, 0, '*', 0)
ON DUPLICATE KEY UPDATE title='Shop';

INSERT INTO ${DB_PREFIX}content
    (id, asset_id, title, alias, introtext, fulltext, state, catid, created, modified, language, metadesc, metakey, access, hits, featured)
VALUES
    (9001, 0, 'Test Product Alpha', 'test-product-alpha', '<p>Alpha.</p>', '', 1, 2, NOW(), NOW(), '*', 'Alpha desc', 'alpha', 1, 0, 0),
    (9002, 0, 'Test Product Beta',  'test-product-beta',  '<p>Beta.</p>',  '', 1, 2, NOW(), NOW(), '*', 'Beta desc',  'beta',  1, 0, 0)
ON DUPLICATE KEY UPDATE title=VALUES(title);

INSERT INTO ${DB_PREFIX}menu
    (id, menutype, title, alias, link, type, published, parent_id, level, component_id, browserNav, access, img, template_style_id, params, lft, rgt, home, language, client_id)
VALUES
    (9002, 'mainmenu', 'Test Product Alpha', 'test-product-alpha',
     'index.php?option=com_content&view=article&id=9001',
     'component', -2, 9001, 3, $COM_CONTENT_ID, 0, 1, '', 0, '{}', 101, 102, 0, '*', 0),
    (9003, 'mainmenu', 'Test Product Beta', 'test-product-beta',
     'index.php?option=com_content&view=article&id=9002',
     'component', -2, 9001, 3, $COM_CONTENT_ID, 0, 1, '', 0, '{}', 103, 104, 0, '*', 0)
ON DUPLICATE KEY UPDATE title=VALUES(title);

INSERT INTO ${DB_PREFIX}j2store_products
    (j2store_product_id, product_source_id, product_source, product_type, enabled, created_on, modified_on)
VALUES
    (9001, 9001, 'com_content', 'simple', 1, NOW(), NOW()),
    (9002, 9002, 'com_content', 'simple', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE enabled=1;
SQL

if [ $? -eq 0 ]; then
    echo "✅ Test fixtures inserted"
else
    echo "⚠️  Some fixture inserts may have failed (duplicates are OK on re-run)"
fi

# Signal full readiness — all extensions installed, fixtures in place
echo "READY" > /var/www/html/ready.txt
chown www-data:www-data /var/www/html/ready.txt 2>/dev/null || true
echo ""
echo "✅ Test environment fully ready"

wait $JOOMLA_PID
