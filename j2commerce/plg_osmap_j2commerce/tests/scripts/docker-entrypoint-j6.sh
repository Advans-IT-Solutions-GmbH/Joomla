#!/bin/bash
# J6 test environment for plg_osmap_j2commerce.
# Creates minimal #__j2commerce_products fixtures and menu items so
# J2CommerceNew::getTree() can be exercised against real data.

set -e

echo "=== OSMap J2Commerce Test Environment (J6 Stack) ==="

/entrypoint.sh apache2-foreground &
JOOMLA_PID=$!

echo "Waiting for Joomla + extensions..."
until [ -f /var/www/html/configuration.php ] && [ ! -d /var/www/html/installation ]; do
    sleep 3
done

echo "Getting DB prefix..."
DB_PREFIX=$(php -r "require '/var/www/html/configuration.php'; \$c=new JConfig; echo \$c->dbprefix;" 2>/dev/null || echo "joom_")
echo "Prefix: ${DB_PREFIX}"

# Install OSMap before the plugin (plugin depends on OSMap being present)
echo "Installing OSMap..."
cp /tmp/osmap.zip /var/www/html/tmp/osmap.zip
php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/osmap.zip 2>&1 | tail -3 || true

echo "Waiting for plugin in DB..."
until mysql -h mysql -u joomla -pjoomla_pass joomla_db \
    -e "SELECT 1 FROM ${DB_PREFIX}extensions WHERE element='j2commerce' AND type='plugin' AND folder='osmap' LIMIT 1;" \
    2>/dev/null | grep -q 1; do
    sleep 3
done
echo "Plugin in DB."

echo "Enabling plugins..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db \
    -e "UPDATE ${DB_PREFIX}extensions SET enabled=1 WHERE type='plugin' AND enabled=0;" 2>/dev/null

# Disable SEF URLs so OSMap generates plain index.php?... URLs.
# NOTE: This means the J6 sitemap test does not verify correct SEF URL output
# in a production environment with SEF enabled. The test validates plugin
# dispatch and node emission only, not SEF URL formatting.
mysql -h mysql -u joomla -pjoomla_pass joomla_db \
    -e "UPDATE ${DB_PREFIX}extensions SET params=JSON_SET(COALESCE(params,'{}'), '$.sef', 0) WHERE element='com_config' AND type='component' LIMIT 1;" 2>/dev/null || true
php -r "
\$f = '/var/www/html/configuration.php';
\$c = file_get_contents(\$f);
\$c = preg_replace('/public \\\$sef = [^;]+;/', 'public \$sef = false;', \$c);
\$c = preg_replace('/public \\\$sef_rewrite = [^;]+;/', 'public \$sef_rewrite = false;', \$c);
file_put_contents(\$f, \$c);
" 2>/dev/null || true

COM_CONTENT_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_content' AND type='component' LIMIT 1;" 2>/dev/null || echo "0")
echo "com_content=${COM_CONTENT_ID}"

COM_J2COMMERCE_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_j2commerce' AND type='component' LIMIT 1;" 2>/dev/null)
if [ -z "${COM_J2COMMERCE_ID}" ]; then
    # com_j2commerce is not installed — insert a stub extension row so OSMap can
    # resolve component_id → com_j2commerce and dispatch to J2CommerceNew::getTree().
    # Use only columns present in all Joomla versions; || true prevents set -e
    # from aborting on schema mismatches.
    mysql -h mysql -u joomla -pjoomla_pass joomla_db 2>/dev/null \
        -e "INSERT IGNORE INTO ${DB_PREFIX}extensions (name, type, element, folder, client_id, enabled, access, protected, manifest_cache, params, checked_out, checked_out_time, ordering, state) VALUES ('com_j2commerce', 'component', 'com_j2commerce', '', 0, 1, 1, 0, '', '{}', 0, '0000-00-00 00:00:00', 0, 0);" || true
    COM_J2COMMERCE_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
        -e "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_j2commerce' AND type='component' LIMIT 1;" 2>/dev/null || echo "")
    COM_J2COMMERCE_ID=${COM_J2COMMERCE_ID:-${COM_CONTENT_ID}}
fi
echo "com_j2commerce=${COM_J2COMMERCE_ID}"

# Create minimal #__j2commerce_products schema (J2Commerce 6 not installed in test image)
echo "Creating J6 schema..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db <<EOSQL
CREATE TABLE IF NOT EXISTS ${DB_PREFIX}j2commerce_products (
    j2commerce_product_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_source_id     INT UNSIGNED NOT NULL DEFAULT 0,
    product_source        VARCHAR(100) NOT NULL DEFAULT '',
    product_type          VARCHAR(50)  NOT NULL DEFAULT 'simple',
    enabled               TINYINT(1)   NOT NULL DEFAULT 1,
    taxprofile_id         INT UNSIGNED NOT NULL DEFAULT 0,
    params                TEXT         NOT NULL,
    PRIMARY KEY (j2commerce_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ${DB_PREFIX}j2commerce_metafields (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    metakey        VARCHAR(255)  NOT NULL,
    namespace      VARCHAR(255)  NOT NULL DEFAULT '',
    scope          VARCHAR(255)  NOT NULL DEFAULT '',
    metavalue      TEXT          NOT NULL,
    valuetype      VARCHAR(255)  NOT NULL DEFAULT '',
    description    TEXT          NOT NULL,
    owner_id       INT UNSIGNED  NOT NULL,
    owner_resource VARCHAR(255)  NOT NULL,
    created_at     TIMESTAMP     NULL DEFAULT NULL,
    updated_at     TIMESTAMP     NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_metafields_owner_id (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
EOSQL
echo "J6 schema created"

echo "Inserting fixtures..."
MAINMENU_ROOT_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT COALESCE(MAX(id),1) FROM ${DB_PREFIX}menu WHERE menutype='mainmenu' AND parent_id=1 LIMIT 1;" 2>/dev/null)
MAINMENU_ROOT_ID=${MAINMENU_ROOT_ID:-1}

mysql -h mysql -u joomla -pjoomla_pass joomla_db <<EOSQL
-- Content articles
INSERT IGNORE INTO ${DB_PREFIX}content
    (id, title, alias, introtext, \`fulltext\`, state, catid, created, created_by,
     modified, access, language, metadata, attribs, images, urls,
     metadesc, metakey, note, featured, version, ordering, hits)
VALUES
    (9001, 'Test Product Alpha', 'test-product-alpha', 'Alpha description', '',
     1, 2, NOW(), 42, NOW(), 1, '*', '{}', '{}', '{}', '{}', '', '', '', 0, 1, 0, 0),
    (9002, 'Test Product Beta', 'test-product-beta', 'Beta description', '',
     1, 2, NOW(), 42, NOW(), 1, '*', '{}', '{}', '{}', '{}', '', '', '', 0, 1, 0, 0);

-- J2Commerce 6 products
INSERT IGNORE INTO ${DB_PREFIX}j2commerce_products
    (j2commerce_product_id, product_source_id, product_source, product_type, enabled, taxprofile_id, params)
VALUES
    (9001, 9001, 'com_content', 'simple', 1, 0, '{}'),
    (9002, 9002, 'com_content', 'simple', 1, 0, '{}');

-- Menu items: shop parent (published=1) + product children (published=-2)
-- published=-2 = hidden from navigation but routable; OSMap includes these in sitemaps
SET @max_rgt = (SELECT COALESCE(MAX(rgt), 10) FROM ${DB_PREFIX}menu);
INSERT IGNORE INTO ${DB_PREFIX}menu
    (id, menutype, title, alias, path, link, type, published, parent_id, level,
     component_id, language, access, client_id, params, lft, rgt)
VALUES
    (9001, 'mainmenu', 'Shop', 'shop', 'shop',
     'index.php?option=com_j2commerce&view=products',
     'component', 1, ${MAINMENU_ROOT_ID}, 1, ${COM_J2COMMERCE_ID}, '*', 1, 0, '{}',
     @max_rgt + 1, @max_rgt + 6),
    (9002, 'mainmenu', 'Test Product Alpha', 'test-product-alpha', 'shop/test-product-alpha',
     'index.php?option=com_content&view=article&id=9001&Itemid=9002',
     'component', -2, 9001, 2, ${COM_CONTENT_ID}, '*', 1, 0, '{}',
     @max_rgt + 2, @max_rgt + 3),
    (9003, 'mainmenu', 'Test Product Beta', 'test-product-beta', 'shop/test-product-beta',
     'index.php?option=com_content&view=article&id=9002&Itemid=9003',
     'component', -2, 9001, 2, ${COM_CONTENT_ID}, '*', 1, 0, '{}',
     @max_rgt + 4, @max_rgt + 5);

-- Expand global root rgt to include new items
UPDATE ${DB_PREFIX}menu
SET rgt = (SELECT max_rgt FROM (SELECT MAX(rgt) + 1 AS max_rgt FROM ${DB_PREFIX}menu) AS t)
WHERE lft = 0;
EOSQL
echo "Fixtures inserted"

# OSMap sitemap — ensure tables exist (CLI installer may skip SQL on J6 due to osmylicensesmanager)
mysql -h mysql -u joomla -pjoomla_pass joomla_db <<EOSQL
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
EOSQL

MAINMENU_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT id FROM ${DB_PREFIX}menu_types WHERE menutype='mainmenu' LIMIT 1;" 2>/dev/null || echo "0")

COM_OSMAP_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_osmap' AND type='component' LIMIT 1;" 2>/dev/null || echo "0")
COM_OSMAP_ID=${COM_OSMAP_ID:-0}
echo "com_osmap extension_id: ${COM_OSMAP_ID}"

mysql -h mysql -u joomla -pjoomla_pass joomla_db <<EOSQL
INSERT IGNORE INTO ${DB_PREFIX}osmap_sitemaps (id, name, params, is_default, published, created_on, links_count)
VALUES (1, 'Test Sitemap J6', '{}', 1, 1, NOW(), 0);
INSERT IGNORE INTO ${DB_PREFIX}osmap_sitemap_menus (sitemap_id, menutype_id, changefreq, priority, ordering)
VALUES (1, ${MAINMENU_ID}, 'weekly', 0.5, 1);

-- OSMap needs a menu item of type com_osmap so Joomla's router activates the component
SET @max_rgt3 = (SELECT COALESCE(MAX(rgt), 0) FROM ${DB_PREFIX}menu);
INSERT IGNORE INTO ${DB_PREFIX}menu
    (id, menutype, title, alias, path, link, type, published, parent_id, level,
     component_id, language, access, client_id, params, lft, rgt)
VALUES
    (9010, 'mainmenu', 'Sitemap', 'sitemap', 'sitemap',
     'index.php?option=com_osmap&view=xml&id=1',
     'component', 1, ${MAINMENU_ROOT_ID}, 1, ${COM_OSMAP_ID}, '*', 1, 0, '{}',
     @max_rgt3 + 1, @max_rgt3 + 2);
EOSQL
echo "OSMap sitemap created"

echo "Verifying fixtures..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db -e "
    SELECT id, title, published FROM ${DB_PREFIX}menu WHERE id IN (9001,9002,9003);
    SELECT j2commerce_product_id, product_source_id, enabled FROM ${DB_PREFIX}j2commerce_products WHERE j2commerce_product_id IN (9001,9002);
" 2>/dev/null || echo "WARNING: fixture verification failed"

echo "OK" > /var/www/html/health.txt
echo "=== Setup complete ==="

wait $JOOMLA_PID
