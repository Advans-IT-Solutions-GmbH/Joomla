#!/bin/bash
# Waits for Joomla + all JOOMLA_EXTENSIONS_PATHS to finish installing,
# then inserts test fixtures and writes health.txt.

echo "=== OSMap J2Commerce Test Environment ==="

# Start Joomla's own entrypoint (installs Joomla + extensions, then runs Apache)
/entrypoint.sh apache2-foreground &
JOOMLA_PID=$!

# Wait for Joomla installation + all extensions to complete.
# The Joomla Docker entrypoint removes installation/ only after all
# JOOMLA_EXTENSIONS_PATHS have been processed.
echo "Waiting for Joomla + extensions..."
until [ -f /var/www/html/configuration.php ] && [ ! -d /var/www/html/installation ]; do
    sleep 3
done

# Extra wait: the last extension install runs after installation/ is removed.
# Poll the DB until our plugin appears in #__extensions.
echo "Waiting for plugin to appear in DB..."
DB_PREFIX=$(php -r "require '/var/www/html/configuration.php'; \$c=new JConfig; echo \$c->dbprefix;" 2>/dev/null || echo "joom_")
until mysql -h mysql -u joomla -pjoomla_pass joomla_db \
    -e "SELECT 1 FROM ${DB_PREFIX}extensions WHERE element='j2commerce' AND type='plugin' AND folder='osmap' LIMIT 1;" \
    2>/dev/null | grep -q 1; do
    sleep 3
done
echo "Plugin registered in DB"

echo "DB prefix: ${DB_PREFIX}"

# Enable all plugins (installed disabled by default)
echo "Enabling plugins..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db \
    -e "UPDATE ${DB_PREFIX}extensions SET enabled=1 WHERE type='plugin' AND enabled=0;" 2>/dev/null \
    && echo "Plugins enabled" || echo "WARNING: could not enable plugins"

# Insert test fixtures for sitemap tests
echo "Inserting test fixtures..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db 2>/dev/null << EOSQL
INSERT IGNORE INTO ${DB_PREFIX}content
    (id, title, alias, introtext, \`fulltext\`, state, catid, created, modified, publish_up, language, access)
VALUES
    (1, 'Test Product', 'test-product', 'A test product', '', 1, 2, NOW(), NOW(), NOW(), '*', 1);

INSERT IGNORE INTO ${DB_PREFIX}j2store_products
    (j2store_product_id, product_source_id, product_source, product_sku, product_price, product_visibility, enabled)
VALUES
    (1, 1, 'com_content', 'TEST-001', 99.00, 1, 1);

INSERT IGNORE INTO ${DB_PREFIX}j2store_variants
    (j2store_variant_id, j2store_product_id, variant_sku, price, is_master)
VALUES
    (1, 1, 'TEST-001', 99.00, 1);

INSERT IGNORE INTO ${DB_PREFIX}menu
    (id, menutype, title, alias, path, link, type, published, parent_id, level, component_id, language, access, params)
VALUES (
    100, 'mainmenu', 'Test Product', 'test-product', 'test-product',
    'index.php?option=com_content&view=article&id=1',
    'component', -2, 1, 1,
    (SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_content' LIMIT 1),
    '*', 1, '{}'
);
EOSQL
echo "Fixtures inserted"

echo "OK" > /var/www/html/health.txt
echo "=== Container ready ==="

wait $JOOMLA_PID
