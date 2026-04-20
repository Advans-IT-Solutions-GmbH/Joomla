#!/bin/bash
# Waits for Joomla + all JOOMLA_EXTENSIONS_PATHS to finish installing,
# then inserts test fixtures and writes health.txt.

echo "=== OSMap J2Commerce Test Environment ==="

/entrypoint.sh apache2-foreground &
JOOMLA_PID=$!

echo "Waiting for Joomla + extensions..."
until [ -f /var/www/html/configuration.php ] && [ ! -d /var/www/html/installation ]; do
    sleep 3
done

echo "Waiting for plugin in DB..."
DB_PREFIX=$(php -r "require '/var/www/html/configuration.php'; \$c=new JConfig; echo \$c->dbprefix;" 2>/dev/null || echo "joom_")
until mysql -h mysql -u joomla -pjoomla_pass joomla_db \
    -e "SELECT 1 FROM ${DB_PREFIX}extensions WHERE element='j2commerce' AND type='plugin' AND folder='osmap' LIMIT 1;" \
    2>/dev/null | grep -q 1; do
    sleep 3
done
echo "Plugin in DB. Prefix: ${DB_PREFIX}"

# Patch OSMap Factory::getTable() for Joomla 5 compatibility.
# Two issues:
# 1. Table::getInstance() returns false (not null) in Joomla 5 — violates ?Table return type.
# 2. Legacy table classes (OSMapTableSitemap etc.) are not auto-loaded in Joomla 5.
OSMAP_FACTORY="/var/www/html/administrator/components/com_osmap/library/Alledia/OSMap/Factory.php"
if [ -f "$OSMAP_FACTORY" ]; then
    php -r "
\$file = '/var/www/html/administrator/components/com_osmap/library/Alledia/OSMap/Factory.php';
\$content = file_get_contents(\$file);
\$old = 'return Table::getInstance(\$tableName, \$prefix);';
\$new = '// Joomla 5: load legacy table class file if not already loaded' . PHP_EOL
     . '        \$tableFile = JPATH_ADMINISTRATOR . \'/components/com_osmap/tables/\' . strtolower(\$tableName) . \'.php\';' . PHP_EOL
     . '        if (is_file(\$tableFile) && !class_exists(\'OSMapTable\' . \$tableName)) {' . PHP_EOL
     . '            require_once \$tableFile;' . PHP_EOL
     . '        }' . PHP_EOL
     . '        return Table::getInstance(\$tableName, \$prefix) ?: null;';
\$patched = str_replace(\$old, \$new, \$content);
if (\$patched === \$content) { echo \"WARNING: patch not applied (string not found)\n\"; } else { file_put_contents(\$file, \$patched); echo \"OSMap Factory.php patched for Joomla 5\n\"; }
"
fi

echo "Enabling plugins..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db \
    -e "UPDATE ${DB_PREFIX}extensions SET enabled=1 WHERE type='plugin' AND enabled=0;" 2>/dev/null

# Disable SEF URLs so OSMap generates plain index.php?... URLs (simpler to assert)
mysql -h mysql -u joomla -pjoomla_pass joomla_db \
    -e "UPDATE ${DB_PREFIX}extensions SET params=JSON_SET(COALESCE(params,'{}'), '$.sef', 0) WHERE element='com_config' AND type='component' LIMIT 1;" 2>/dev/null || true
# Also set via configuration.php
php -r "
\$f = '/var/www/html/configuration.php';
\$c = file_get_contents(\$f);
\$c = preg_replace('/public \\\$sef = [^;]+;/', 'public \$sef = false;', \$c);
\$c = preg_replace('/public \\\$sef_rewrite = [^;]+;/', 'public \$sef_rewrite = false;', \$c);
file_put_contents(\$f, \$c);
" 2>/dev/null || true

echo "Inserting fixtures..."
COM_CONTENT_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_content' LIMIT 1;" 2>/dev/null || echo "0")
COM_J2STORE_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_j2store' LIMIT 1;" 2>/dev/null || echo "0")
echo "com_content=${COM_CONTENT_ID}, com_j2store=${COM_J2STORE_ID}"

mysql -h mysql -u joomla -pjoomla_pass joomla_db << EOSQL
-- Shop page menu item (parent, published=1) — ID 9001
INSERT IGNORE INTO ${DB_PREFIX}menu
    (id, menutype, title, alias, path, link, type, published, parent_id, level, component_id, language, access, params)
VALUES (
    9001, 'mainmenu', 'Shop', 'shop', 'shop',
    'index.php?option=com_j2store&view=products',
    'component', 1, 1, 1, ${COM_J2STORE_ID}, '*', 1, '{}'
);

-- Product articles
INSERT IGNORE INTO ${DB_PREFIX}content
    (id, title, alias, introtext, \`fulltext\`, state, catid, created, modified, publish_up, language, access)
VALUES
    (9001, 'Test Product Alpha',    'test-product-alpha',    'Alpha',    '', 1, 2, NOW(), NOW(), NOW(), '*', 1),
    (9002, 'Test Product Beta',     'test-product-beta',     'Beta',     '', 1, 2, NOW(), NOW(), NOW(), '*', 1),
    (9003, 'Test Product Disabled', 'test-product-disabled', 'Disabled', '', 1, 2, NOW(), NOW(), NOW(), '*', 1),
    (9004, 'Test Product NoMenu',   'test-product-nomenu',   'NoMenu',   '', 1, 2, NOW(), NOW(), NOW(), '*', 1);

-- J2Commerce products (real schema: visibility, no product_sku/product_price)
-- 9003 = disabled (enabled=0), 9004 = enabled but no SEF menu item
INSERT IGNORE INTO ${DB_PREFIX}j2store_products
    (j2store_product_id, product_source_id, product_source, visibility, enabled)
VALUES
    (9001, 9001, 'com_content', 1, 1),
    (9002, 9002, 'com_content', 1, 1),
    (9003, 9003, 'com_content', 1, 0),
    (9004, 9004, 'com_content', 1, 1);

-- J2Commerce variants (real schema: product_id, sku — not j2store_product_id, variant_sku)
INSERT IGNORE INTO ${DB_PREFIX}j2store_variants
    (j2store_variant_id, product_id, sku, price, is_master)
VALUES
    (9001, 9001, 'ALPHA-001', 49.00, 1),
    (9002, 9002, 'BETA-001',  79.00, 1),
    (9003, 9003, 'DISABLED',  19.00, 1),
    (9004, 9004, 'NOMENU',    29.00, 1);

-- SEF menu items (published=-2, children of shop 9001)
-- Only Alpha (9002) and Beta (9003) — disabled and nomenu have no SEF item
INSERT IGNORE INTO ${DB_PREFIX}menu
    (id, menutype, title, alias, path, link, type, published, parent_id, level, component_id, language, access, params)
VALUES
    (9002, 'mainmenu', 'Test Product Alpha', 'test-product-alpha', 'shop/test-product-alpha',
     'index.php?option=com_content&view=article&id=9001',
     'component', -2, 9001, 2, ${COM_CONTENT_ID}, '*', 1, '{}'),
    (9003, 'mainmenu', 'Test Product Beta', 'test-product-beta', 'shop/test-product-beta',
     'index.php?option=com_content&view=article&id=9002',
     'component', -2, 9001, 2, ${COM_CONTENT_ID}, '*', 1, '{}');
EOSQL

echo "Fixtures inserted"

# Create OSMap sitemap and link it to mainmenu so the HTTP test can call
# index.php?option=com_osmap&view=xml&id=1
echo "Creating OSMap sitemap..."
MAINMENU_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT id FROM ${DB_PREFIX}menu_types WHERE menutype='mainmenu' LIMIT 1;" 2>/dev/null || echo "0")
echo "mainmenu id: ${MAINMENU_ID}"

mysql -h mysql -u joomla -pjoomla_pass joomla_db << EOSQL
INSERT IGNORE INTO ${DB_PREFIX}osmap_sitemaps (id, name, params, is_default, published, created_on, links_count)
VALUES (1, 'Test Sitemap', '{}', 1, 1, NOW(), 0);

INSERT IGNORE INTO ${DB_PREFIX}osmap_sitemap_menus (sitemap_id, menutype_id, changefreq, priority, ordering)
VALUES (1, ${MAINMENU_ID}, 'weekly', 0.5, 1);
EOSQL
echo "OSMap sitemap created"

echo "OK" > /var/www/html/health.txt
echo "=== Container ready ==="

wait $JOOMLA_PID
