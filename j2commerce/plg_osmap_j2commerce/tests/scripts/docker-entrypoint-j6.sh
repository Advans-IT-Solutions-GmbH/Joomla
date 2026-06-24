#!/bin/bash
# J6 test environment for plg_osmap_j2commerce.
# Installs official J2Commerce 6 and OSMap packages, then seeds fixture data
# through their real tables.

set -e

echo "=== OSMap J2Commerce Test Environment (J6 Stack) ==="

/entrypoint.sh apache2-foreground &
JOOMLA_PID=$!

echo "Waiting for Joomla..."
until [ -f /var/www/html/configuration.php ] && [ ! -d /var/www/html/installation ]; do
    sleep 3
done

echo "Getting DB prefix..."
DB_PREFIX=$(php -r "require '/var/www/html/configuration.php'; \$c=new JConfig; echo \$c->dbprefix;" 2>/dev/null || echo "joom_")
echo "Prefix: ${DB_PREFIX}"

install_with_web_installer() {
    local package_path="$1"
    local label="$2"

    echo "Installing ${label} via Joomla Web Installer..."
    if PACKAGE_PATH="${package_path}" EXTENSION_NAME="${label}" php /usr/local/bin/install-extension-http.php; then
        echo "${label} installed via Joomla Web Installer"
    else
        echo "ERROR: ${label} installation FAILED via Joomla Web Installer"
        exit 1
    fi
}

echo "Installing J2Commerce 6 via Joomla CLI..."
if [ -f /tmp/j2commerce6.zip ]; then
    cp /tmp/j2commerce6.zip /var/www/html/tmp/j2commerce6.zip
    if HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/j2commerce6.zip; then
        echo "J2Commerce 6 installed via Joomla CLI"
    else
        echo "ERROR: J2Commerce 6 installation FAILED"
        exit 1
    fi
else
    echo "ERROR: J2Commerce 6 ZIP not found at /tmp/j2commerce6.zip"
    exit 1
fi

install_with_web_installer /tmp/osmap.zip "OSMap"
install_with_web_installer /tmp/extension.zip "OSMap J2Commerce plugin"

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

# SEF URL handling.
#
# Default (J2COMMERCE_SEF unset/0): disable SEF so OSMap generates plain
# index.php?... URLs (simpler for the standard J6 assertions).
#
# SEF-on mode (J2COMMERCE_SEF=1, used by docker-compose.joomla6-sef.yml):
# enable SEF + URL rewriting and Apache mod_rewrite/.htaccess so the J6 SEF
# HTTP test (08-sitemap-http-sef.php) can verify correctly-formed SEF product
# URLs on the J6 + J2Commerce 6 stack.
if [ "${J2COMMERCE_SEF}" = "1" ]; then
    echo "Enabling SEF URLs (J2COMMERCE_SEF=1)..."
    mysql -h mysql -u joomla -pjoomla_pass joomla_db \
        -e "UPDATE ${DB_PREFIX}extensions SET params=JSON_SET(COALESCE(params,'{}'), '\$.sef', 1) WHERE element='com_config' AND type='component' LIMIT 1;" 2>/dev/null || true
    php -r "
\$f = '/var/www/html/configuration.php';
\$c = file_get_contents(\$f);
\$c = preg_replace('/public \\\$sef = [^;]+;/', 'public \$sef = true;', \$c);
\$c = preg_replace('/public \\\$sef_rewrite = [^;]+;/', 'public \$sef_rewrite = true;', \$c);
file_put_contents(\$f, \$c);
" 2>/dev/null || true
    # Enable Apache rewrite + Joomla .htaccess so SEF rewrite URLs resolve.
    a2enmod rewrite >/dev/null 2>&1 || true
    if [ -f /var/www/html/htaccess.txt ] && [ ! -f /var/www/html/.htaccess ]; then
        cp /var/www/html/htaccess.txt /var/www/html/.htaccess
    fi
else
    echo "Disabling SEF URLs (default J6 mode)..."
    mysql -h mysql -u joomla -pjoomla_pass joomla_db \
        -e "UPDATE ${DB_PREFIX}extensions SET params=JSON_SET(COALESCE(params,'{}'), '\$.sef', 0) WHERE element='com_config' AND type='component' LIMIT 1;" 2>/dev/null || true
    php -r "
\$f = '/var/www/html/configuration.php';
\$c = file_get_contents(\$f);
\$c = preg_replace('/public \\\$sef = [^;]+;/', 'public \$sef = false;', \$c);
\$c = preg_replace('/public \\\$sef_rewrite = [^;]+;/', 'public \$sef_rewrite = false;', \$c);
file_put_contents(\$f, \$c);
" 2>/dev/null || true
fi

COM_CONTENT_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_content' AND type='component' LIMIT 1;" 2>/dev/null || echo "0")
echo "com_content=${COM_CONTENT_ID}"

COM_J2COMMERCE_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_j2commerce' AND type='component' LIMIT 1;" 2>/dev/null)
if [ -z "${COM_J2COMMERCE_ID}" ]; then
    echo "ERROR: com_j2commerce is not registered after official J2Commerce 6 installation"
    exit 1
fi
echo "com_j2commerce=${COM_J2COMMERCE_ID}"

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
    (j2commerce_product_id, product_source_id, product_source, product_type, visibility, enabled, taxprofile_id, addtocart_text, up_sells, cross_sells, params)
VALUES
    (9001, 9001, 'com_content', 'simple', 1, 1, 0, '', '', '', '{}'),
    (9002, 9002, 'com_content', 'simple', 1, 1, 0, '', '', '', '{}');

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

# OSMap sitemap — tables must come from the official OSMap installation.
MAINMENU_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT id FROM ${DB_PREFIX}menu_types WHERE menutype='mainmenu' LIMIT 1;" 2>/dev/null || echo "0")

COM_OSMAP_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_osmap' AND type='component' LIMIT 1;" 2>/dev/null || echo "0")
COM_OSMAP_ID=${COM_OSMAP_ID:-0}
if [ "$COM_OSMAP_ID" = "0" ]; then
    echo "ERROR: com_osmap is not registered after official OSMap installation"
    exit 1
fi
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
