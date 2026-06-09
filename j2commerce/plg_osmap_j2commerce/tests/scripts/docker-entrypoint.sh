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

# Manually extract OSMap into the component directories.
# JOOMLA_EXTENSIONS_PATHS installs osmap.zip but script.php fails (AbstractScript
# type error), causing the installer to roll back and remove the files.
# Extract manually so the component is available for HTTP requests.
# ZIP layout: admin/ → administrator/components/com_osmap/
#             site/ → components/com_osmap/
echo "Extracting OSMap files manually..."
OSMAP_TMP=$(mktemp -d)
unzip -q /tmp/osmap.zip -d "$OSMAP_TMP" 2>/dev/null || true
mkdir -p /var/www/html/administrator/components/com_osmap
mkdir -p /var/www/html/components/com_osmap
[ -d "$OSMAP_TMP/admin" ]     && cp -r "$OSMAP_TMP/admin/."     /var/www/html/administrator/components/com_osmap/
[ -d "$OSMAP_TMP/site" ]      && cp -r "$OSMAP_TMP/site/."      /var/www/html/components/com_osmap/
[ -f "$OSMAP_TMP/osmap.xml" ] && cp    "$OSMAP_TMP/osmap.xml"   /var/www/html/administrator/components/com_osmap/
[ -d "$OSMAP_TMP/media" ]     && cp -r "$OSMAP_TMP/media/."     /var/www/html/media/ 2>/dev/null || true
# OSMap include.php expects the bundled framework at this Joomla library path.
[ -d "$OSMAP_TMP/extensions/ShackFramework" ] && {
    rm -rf /var/www/html/libraries/allediaframework
    mkdir -p /var/www/html/libraries/allediaframework
    cp -r "$OSMAP_TMP/extensions/ShackFramework/." /var/www/html/libraries/allediaframework/
}
rm -rf "$OSMAP_TMP"
echo "OSMap files extracted"

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
    -e "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_content' AND type='component' LIMIT 1;" 2>/dev/null || echo "0")
COM_J2STORE_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_j2store' AND type='component' LIMIT 1;" 2>/dev/null || echo "0")
echo "com_content=${COM_CONTENT_ID}, com_j2store=${COM_J2STORE_ID}"

# Warn if com_j2store is not found — do not abort.
# On a J6-only installation com_j2store is absent; the plugin still installs
# and the J2CommerceNew handler covers com_j2commerce menu items.
if [ "$COM_J2STORE_ID" = "0" ]; then
    echo "WARNING: com_j2store not found in extensions table — J4 fixtures will be skipped"
fi

# Get the parent_id for top-level mainmenu items (= the global root item id).
# In Joomla the global root has parent_id=0; all top-level items have parent_id=global_root_id.
MAINMENU_ROOT_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT parent_id FROM ${DB_PREFIX}menu WHERE menutype='mainmenu' AND level=1 LIMIT 1;" \
    2>/dev/null)
# Fallback: global root id
if [ -z "$MAINMENU_ROOT_ID" ]; then
    MAINMENU_ROOT_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
        -e "SELECT id FROM ${DB_PREFIX}menu WHERE parent_id=0 LIMIT 1;" \
        2>/dev/null || echo "1")
fi
echo "mainmenu root item id: ${MAINMENU_ROOT_ID}"

mysql -h mysql -u joomla -pjoomla_pass joomla_db << EOSQL
-- Rebuild nested set values for our menu items.
-- OSMap queries: m.lft > p.lft AND m.lft < p.rgt where p.lft=0.
-- We need lft/rgt > 0. Use high values to avoid conflicts.
SET @max_rgt = (SELECT COALESCE(MAX(rgt), 0) FROM ${DB_PREFIX}menu);

-- Shop page menu item (top-level, published=1) — ID 9001
INSERT IGNORE INTO ${DB_PREFIX}menu
    (id, menutype, title, alias, path, link, type, published, parent_id, level, component_id, language, access, params, lft, rgt)
VALUES (
    9001, 'mainmenu', 'Shop', 'shop', 'shop',
    'index.php?option=com_j2store&view=products',
    'component', 1, ${MAINMENU_ROOT_ID}, 1, ${COM_J2STORE_ID}, '*', 1, '{}',
    @max_rgt + 1, @max_rgt + 6
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
-- published=-2 = hidden from navigation but routable; OSMap includes these in sitemaps
-- Only Alpha (9002) and Beta (9003) — disabled and nomenu have no SEF item
INSERT IGNORE INTO ${DB_PREFIX}menu
    (id, menutype, title, alias, path, link, type, published, parent_id, level, component_id, language, access, params, lft, rgt)
VALUES
    (9002, 'mainmenu', 'Test Product Alpha', 'test-product-alpha', 'shop/test-product-alpha',
     'index.php?option=com_content&view=article&id=9001&Itemid=9002',
     'component', -2, 9001, 2, ${COM_CONTENT_ID}, '*', 1, '{}',
     @max_rgt + 2, @max_rgt + 3),
    (9003, 'mainmenu', 'Test Product Beta', 'test-product-beta', 'shop/test-product-beta',
     'index.php?option=com_content&view=article&id=9002&Itemid=9003',
     'component', -2, 9001, 2, ${COM_CONTENT_ID}, '*', 1, '{}',
     @max_rgt + 4, @max_rgt + 5);
EOSQL

echo "Fixtures inserted"

# Fix nested set values so OSMap can traverse our new menu items.
# OSMap queries: m.lft > p.lft AND m.lft < p.rgt where p.lft=0 (global root).
# The global root has lft=0 and rgt=N. Our items were inserted with lft/rgt
# beyond N, so they are invisible to OSMap. We expand the global root's rgt
# and shift our items to fit inside it.
mysql -h mysql -u joomla -pjoomla_pass joomla_db <<EOSQL
-- Expand the global root (lft=0) to include our new items.
-- Our items have lft values up to @max_rgt + 5 (set during INSERT above).
-- We set the global root's rgt to MAX(rgt)+1 across all items.
UPDATE ${DB_PREFIX}menu
SET rgt = (SELECT max_rgt FROM (SELECT MAX(rgt) + 1 AS max_rgt FROM ${DB_PREFIX}menu) AS t)
WHERE lft = 0;
EOSQL
echo "Menu nested set expanded"

# Create OSMap sitemap and link it to mainmenu so the HTTP test can call
# index.php?option=com_osmap&view=xml&id=1
echo "Creating OSMap sitemap..."
MAINMENU_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT id FROM ${DB_PREFIX}menu_types WHERE menutype='mainmenu' LIMIT 1;" 2>/dev/null || echo "0")
echo "mainmenu id: ${MAINMENU_ID}"

# Read com_osmap extension_id — JOOMLA_EXTENSIONS_PATHS installs it, but
# OSMap's script.php may fail silently leaving com_osmap unregistered.
# If missing, register it manually (files are already in the filesystem).
COM_OSMAP_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_osmap' AND type='component' LIMIT 1;" 2>/dev/null || echo "0")
COM_OSMAP_ID=${COM_OSMAP_ID:-0}
if [ "$COM_OSMAP_ID" = "0" ]; then
    echo "com_osmap not in extensions — registering manually..."
    mysql -h mysql -u joomla -pjoomla_pass joomla_db \
        -e "INSERT IGNORE INTO ${DB_PREFIX}extensions
            (package_id, name, type, element, folder, client_id, enabled, access,
             protected, locked, manifest_cache, params, custom_data,
             checked_out, checked_out_time, ordering, state)
            VALUES (0, 'OSMap', 'component', 'com_osmap', '', 1, 1, 1,
                    0, 0, '{}', '{}', '',
                    NULL, NULL, 0, 0);" 2>&1 || true
    COM_OSMAP_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
        -e "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_osmap' AND type='component' LIMIT 1;" 2>/dev/null || echo "0")
    COM_OSMAP_ID=${COM_OSMAP_ID:-0}
fi
echo "com_osmap extension_id: ${COM_OSMAP_ID}"

mysql -h mysql -u joomla -pjoomla_pass joomla_db <<EOSQL
-- Ensure OSMap tables exist (CLI installer may skip SQL when osmylicensesmanager throws)
CREATE TABLE IF NOT EXISTS \`${DB_PREFIX}osmap_sitemaps\` (
  \`id\` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  \`name\` VARCHAR(100) NULL DEFAULT NULL,
  \`params\` TEXT NULL DEFAULT NULL,
  \`is_default\` TINYINT(1) NOT NULL DEFAULT '0',
  \`published\` TINYINT(1) NOT NULL DEFAULT '1',
  \`created_on\` DATETIME NULL DEFAULT NULL,
  \`links_count\` INT(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`${DB_PREFIX}osmap_sitemap_menus\` (
  \`sitemap_id\` INT(11) UNSIGNED NOT NULL,
  \`menutype_id\` INT(11) NOT NULL,
  \`changefreq\` ENUM('always','hourly','daily','weekly','monthly','yearly','never') NOT NULL DEFAULT 'weekly',
  \`priority\` FLOAT NOT NULL DEFAULT '0.5',
  \`ordering\` INT(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (\`sitemap_id\`, \`menutype_id\`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`${DB_PREFIX}osmap_items_settings\` (
  \`sitemap_id\` INT(11) UNSIGNED NOT NULL,
  \`uid\` VARCHAR(100) NOT NULL DEFAULT '',
  \`settings_hash\` CHAR(32) NOT NULL DEFAULT '',
  \`published\` TINYINT(1) UNSIGNED NOT NULL DEFAULT '1',
  \`changefreq\` ENUM('always','hourly','daily','weekly','monthly','yearly','never') NOT NULL DEFAULT 'weekly',
  \`priority\` FLOAT NOT NULL DEFAULT '0.5',
  \`format\` TINYINT(1) UNSIGNED NULL DEFAULT '2',
  PRIMARY KEY (\`sitemap_id\`, \`uid\`, \`settings_hash\`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4;

INSERT IGNORE INTO ${DB_PREFIX}osmap_sitemaps (id, name, params, is_default, published, created_on, links_count)
VALUES (1, 'Test Sitemap', '{}', 1, 1, NOW(), 0);

INSERT IGNORE INTO ${DB_PREFIX}osmap_sitemap_menus (sitemap_id, menutype_id, changefreq, priority, ordering)
VALUES (1, ${MAINMENU_ID}, 'weekly', 0.5, 1);

-- OSMap needs a menu item of type com_osmap so Joomla's router activates the component
SET @max_rgt2 = (SELECT COALESCE(MAX(rgt), 0) FROM ${DB_PREFIX}menu);
INSERT IGNORE INTO ${DB_PREFIX}menu
    (id, menutype, title, alias, path, link, type, published, parent_id, level, component_id, language, access, params, lft, rgt)
VALUES
    (9010, 'mainmenu', 'Sitemap', 'sitemap', 'sitemap',
     'index.php?option=com_osmap&view=xml&id=1',
     'component', 1, ${MAINMENU_ROOT_ID}, 1, ${COM_OSMAP_ID}, '*', 1, '{}',
     @max_rgt2 + 1, @max_rgt2 + 2);
-- Update component_id in case COM_OSMAP_ID was 0 at INSERT time
UPDATE ${DB_PREFIX}menu
SET component_id = (SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_osmap' AND type='component' LIMIT 1)
WHERE id = 9010 AND component_id = 0;
EOSQL
echo "OSMap sitemap created"

# Verify fixtures are correct
echo "Verifying fixtures..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db -e "
    SELECT id, title, published, component_id FROM ${DB_PREFIX}menu WHERE id IN (9001,9002,9003);
    SELECT j2store_product_id, product_source_id, enabled FROM ${DB_PREFIX}j2store_products WHERE j2store_product_id IN (9001,9002,9003);
    SELECT sitemap_id, menutype_id FROM ${DB_PREFIX}osmap_sitemap_menus;
" 2>/dev/null || echo "WARNING: fixture verification failed"

# Debug: verify menu items and run the exact getTree() query
echo "=== Debug: menu items with lft/rgt ==="
mysql -h mysql -u joomla -pjoomla_pass joomla_db -e "
    SELECT id, title, published, parent_id, level, lft, rgt, link
    FROM ${DB_PREFIX}menu WHERE id IN (9001,9002,9003);" 2>/dev/null || true

echo "=== Debug: global root lft/rgt ==="
mysql -h mysql -u joomla -pjoomla_pass joomla_db -e "
    SELECT id, lft, rgt FROM ${DB_PREFIX}menu WHERE lft=0;" 2>/dev/null || true

echo "=== Debug: OSMap getMenuItems query result ==="
mysql -h mysql -u joomla -pjoomla_pass joomla_db -e "
    SELECT m.id, m.title, m.published, m.lft, m.link
    FROM ${DB_PREFIX}menu m
    INNER JOIN ${DB_PREFIX}menu p ON (p.lft = 0)
    WHERE m.menutype='mainmenu' AND m.published=1
      AND m.lft > p.lft AND m.lft < p.rgt
    ORDER BY m.lft;" 2>/dev/null || true

echo "=== Debug: getTree() query (parent_id=9001) ==="
mysql -h mysql -u joomla -pjoomla_pass joomla_db -e "
    SELECT m.id, m.alias, a.id AS article_id, a.title
    FROM ${DB_PREFIX}menu m
    INNER JOIN ${DB_PREFIX}content a
        ON ((m.link LIKE CONCAT('%&id=', a.id, '&%') OR m.link LIKE CONCAT('%&id=', a.id))
        AND m.link LIKE '%com_content%view=article%')
    INNER JOIN ${DB_PREFIX}j2store_products p
        ON p.product_source_id = a.id
        AND p.product_source = 'com_content'
        AND p.enabled = 1
    WHERE m.published = -2
      AND m.parent_id = 9001
      AND m.client_id = 0
    ORDER BY a.title;" 2>/dev/null || true



echo "OK" > /var/www/html/health.txt
echo "=== Container ready ==="

wait $JOOMLA_PID
