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

# Poll the DB until our plugin appears in #__extensions (installed after installation/ removal)
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

# Insert test fixtures matching the IDs expected by 04-sitemap-output.php:
#   SHOP_MENU_ID  = 9001  (published=1, com_j2store shop page)
#   PRODUCT_ALPHA = article 9001, menu 9002
#   PRODUCT_BETA  = article 9002, menu 9003
echo "Inserting test fixtures..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db 2>/dev/null << EOSQL
-- Shop page menu item (parent for product SEF items)
INSERT IGNORE INTO ${DB_PREFIX}menu
    (id, menutype, title, alias, path, link, type, published, parent_id, level, component_id, language, access, params)
VALUES (
    9001, 'mainmenu', 'Shop', 'shop', 'shop',
    'index.php?option=com_j2store&view=products',
    'component', 1, 1, 1,
    (SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_j2store' LIMIT 1),
    '*', 1, '{}'
);

-- Product articles
INSERT IGNORE INTO ${DB_PREFIX}content
    (id, title, alias, introtext, \`fulltext\`, state, catid, created, modified, publish_up, language, access)
VALUES
    (9001, 'Test Product Alpha', 'test-product-alpha', 'Alpha product', '', 1, 2, NOW(), NOW(), NOW(), '*', 1),
    (9002, 'Test Product Beta',  'test-product-beta',  'Beta product',  '', 1, 2, NOW(), NOW(), NOW(), '*', 1);

-- J2Commerce product records
INSERT IGNORE INTO ${DB_PREFIX}j2store_products
    (j2store_product_id, product_source_id, product_source, product_sku, product_price, product_visibility, enabled)
VALUES
    (9001, 9001, 'com_content', 'ALPHA-001', 49.00, 1, 1),
    (9002, 9002, 'com_content', 'BETA-001',  79.00, 1, 1);

-- J2Commerce product variants
INSERT IGNORE INTO ${DB_PREFIX}j2store_variants
    (j2store_variant_id, j2store_product_id, variant_sku, price, is_master)
VALUES
    (9001, 9001, 'ALPHA-001', 49.00, 1),
    (9002, 9002, 'BETA-001',  79.00, 1);

-- Product SEF menu items (published=-2, children of shop menu 9001)
INSERT IGNORE INTO ${DB_PREFIX}menu
    (id, menutype, title, alias, path, link, type, published, parent_id, level, component_id, language, access, params)
VALUES
    (9002, 'mainmenu', 'Test Product Alpha', 'test-product-alpha', 'shop/test-product-alpha',
     'index.php?option=com_content&view=article&id=9001',
     'component', -2, 9001, 2,
     (SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_content' LIMIT 1),
     '*', 1, '{}'),
    (9003, 'mainmenu', 'Test Product Beta', 'test-product-beta', 'shop/test-product-beta',
     'index.php?option=com_content&view=article&id=9002',
     'component', -2, 9001, 2,
     (SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_content' LIMIT 1),
     '*', 1, '{}');
EOSQL
echo "Fixtures inserted"

echo "OK" > /var/www/html/health.txt
echo "=== Container ready ==="

wait $JOOMLA_PID
