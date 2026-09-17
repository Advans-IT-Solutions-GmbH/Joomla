#!/bin/bash
# Waits for Joomla, installs official upstream packages, inserts fixtures,
# and writes health.txt.

echo "=== OSMap J2Commerce Test Environment ==="

/entrypoint.sh apache2-foreground &
JOOMLA_PID=$!

echo "Waiting for Joomla..."
until [ -f /var/www/html/configuration.php ] && [ ! -d /var/www/html/installation ]; do
    sleep 3
done

# configuration.php appears before the official entrypoint has finished its
# setup. Installing extensions in that window could lose registrations, so wait
# until the installation folder is gone and the site answers.
if [ -f /var/www/html/configuration.php ]; then
    READY_ELAPSED=0
    until [ ! -d /var/www/html/installation ] && php -r 'exit(@file_get_contents("http://localhost/") === false ? 1 : 0);'; do
        if [ $READY_ELAPSED -ge 120 ]; then
            echo "ERROR: Joomla setup did not finish within 120 seconds"
            exit 1
        fi
        sleep 2
        READY_ELAPSED=$((READY_ELAPSED + 2))
    done
    echo "Joomla setup finished"
fi

DB_PREFIX=$(php -r "require '/var/www/html/configuration.php'; \$c=new JConfig; echo \$c->dbprefix;" 2>/dev/null || echo "joom_")
echo "DB prefix: ${DB_PREFIX}"

install_with_web_installer() {
    local package_path="$1"
    local label="$2"
    local strict="${3:-0}"

    echo "Installing ${label} via Joomla Web Installer..."
    if PACKAGE_PATH="${package_path}" EXTENSION_NAME="${label}" STRICT_MESSAGES="${strict}" php /usr/local/bin/install-extension-http.php; then
        echo "${label} installed via Joomla Web Installer"
    else
        echo "ERROR: ${label} installation FAILED via Joomla Web Installer"
        exit 1
    fi
}

echo "Installing J2Store/J2Commerce 4 via Joomla CLI..."
if [ -f /tmp/j2commerce4.zip ]; then
    cp /tmp/j2commerce4.zip /var/www/html/tmp/j2commerce4.zip
    if HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/j2commerce4.zip; then
        echo "J2Store/J2Commerce 4 installed via Joomla CLI"
    else
        echo "ERROR: J2Store/J2Commerce 4 installation FAILED"
        exit 1
    fi
else
    echo "ERROR: J2Store/J2Commerce 4 ZIP not found at /tmp/j2commerce4.zip"
    exit 1
fi

# Extensions installed through the CLI run as root and leave a root-owned
# namespace map (administrator/cache/autoload_psr4.php). The web installer runs as
# www-data and could then not rebuild the map, so newly installed namespaces would
# stay unknown to later CLI runs. On real sites the cache belongs to the web server.
chown -R www-data:www-data /var/www/html/administrator/cache 2>/dev/null || true
install_with_web_installer /tmp/osmap.zip "OSMap"
install_with_web_installer /tmp/extension.zip "OSMap J2Commerce plugin" "${STRICT_INSTALL_MESSAGES:-1}"

echo "Waiting for plugin in DB..."
until mysql -h mysql -u joomla -pjoomla_pass joomla_db \
    -e "SELECT 1 FROM ${DB_PREFIX}extensions WHERE element='j2commerce' AND type='plugin' AND folder='osmap' LIMIT 1;" \
    2>/dev/null | grep -q 1; do
    sleep 3
done
echo "Plugin in DB. Prefix: ${DB_PREFIX}"

# Record plugin states before the test setup enables everything, so tests can
# verify what the installer itself enabled or disabled.
mkdir -p /tmp/test-state
mysql -h mysql -u joomla -pjoomla_pass joomla_db -N \
    -e "SELECT folder, element, enabled FROM ${DB_PREFIX}extensions WHERE type = 'plugin';" \
    > /tmp/test-state/plugins-before-activation.tsv 2>/dev/null

echo "Enabling plugins..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db \
    -e "UPDATE ${DB_PREFIX}extensions SET enabled=1 WHERE type='plugin' AND enabled=0;" 2>/dev/null

# Default fixture: keep SEF off for the standard J5 suite. The dedicated
# J5 SEF stack (docker-compose.sef.yml, J2COMMERCE_SEF=1) enables rewrite URLs
# so 08-sitemap-http-sef.php can assert that every emitted product URL carries
# the correct language SEF prefix (e.g. /de/shop/...) in the live sitemap. The
# end-to-end HTTP-200 resolution check needs a full multilingual stack and is
# tracked as follow-up #185.
if [ "${J2COMMERCE_SEF}" = "1" ]; then
    echo "Enabling SEF URLs (J2COMMERCE_SEF=1)..."
    mysql -h mysql -u joomla -pjoomla_pass joomla_db \
        -e "UPDATE ${DB_PREFIX}extensions SET params=JSON_SET(COALESCE(params,'{}'), '$.sef', 1) WHERE element='com_config' AND type='component' LIMIT 1;" 2>/dev/null || true
    php -r "
\$f = '/var/www/html/configuration.php';
\$c = file_get_contents(\$f);
\$c = preg_replace('/public \\\$sef = [^;]+;/', 'public \$sef = true;', \$c);
\$c = preg_replace('/public \\\$sef_rewrite = [^;]+;/', 'public \$sef_rewrite = true;', \$c);
file_put_contents(\$f, \$c);
" 2>/dev/null || true
    a2enmod rewrite >/dev/null 2>&1 || true
    if [ -f /var/www/html/htaccess.txt ] && [ ! -f /var/www/html/.htaccess ]; then
        cp /var/www/html/htaccess.txt /var/www/html/.htaccess
    fi
else
    echo "Disabling SEF URLs (default J5 mode)..."
    mysql -h mysql -u joomla -pjoomla_pass joomla_db \
        -e "UPDATE ${DB_PREFIX}extensions SET params=JSON_SET(COALESCE(params,'{}'), '$.sef', 0) WHERE element='com_config' AND type='component' LIMIT 1;" 2>/dev/null || true
    php -r "
\$f = '/var/www/html/configuration.php';
\$c = file_get_contents(\$f);
\$c = preg_replace('/public \\\$sef = [^;]+;/', 'public \$sef = false;', \$c);
\$c = preg_replace('/public \\\$sef_rewrite = [^;]+;/', 'public \$sef_rewrite = false;', \$c);
file_put_contents(\$f, \$c);
" 2>/dev/null || true
fi

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
    (j2store_product_id, product_source_id, product_source, product_type, visibility, enabled, addtocart_text, up_sells, cross_sells, params)
VALUES
    (9001, 9001, 'com_content', 'simple', 1, 1, '', '', '', '{}'),
    (9002, 9002, 'com_content', 'simple', 1, 1, '', '', '', '{}'),
    (9003, 9003, 'com_content', 'simple', 1, 0, '', '', '', '{}'),
    (9004, 9004, 'com_content', 'simple', 1, 1, '', '', '', '{}');

-- J2Commerce variants (real schema: product_id, sku — not j2store_product_id, variant_sku)
INSERT IGNORE INTO ${DB_PREFIX}j2store_variants
    (j2store_variant_id, product_id, sku, price, is_master)
VALUES
    (9001, 9001, 'ALPHA-001', 49.00, 1),
    (9002, 9002, 'BETA-001',  79.00, 1),
    (9003, 9003, 'DISABLED',  19.00, 1),
    (9004, 9004, 'NOMENU',    29.00, 1);

-- Hidden product menu items exist on both J5 fixtures. The dedicated J5 SEF
-- lane still needs them so the live sitemap request traverses the real
-- multilingual hidden-child paths that previously 301-redirected.
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

if [ "${J2COMMERCE_SEF}" = "1" ]; then
    echo "Applying multilingual SEF fixture (de-DE / sef=de)..."
    mysql -h mysql -u joomla -pjoomla_pass joomla_db <<EOSQL
INSERT IGNORE INTO ${DB_PREFIX}languages
    (lang_code, title, title_native, sef, image, description, metakey, metadesc, sitename, published, access, ordering)
VALUES
    ('de-DE', 'German (DE)', 'Deutsch (DE)', 'de', '', '', '', '', '', 1, 1, 1);

UPDATE ${DB_PREFIX}menu SET language='de-DE' WHERE id IN (9002, 9003);
-- Only the menu-less product's article (9004) gets de-DE so the direct-query
-- lane (mechanism 2) carries its /de/ prefix from the article language. The
-- articles behind the hidden children (9001, 9002) stay '*' on purpose: their
-- /de/ prefix must come from the hidden child menu item's own language
-- (mechanism 1, printMenuPathNode). Making them de-DE too would let the
-- direct-query fallback re-emit them with a /de/ prefix even if the hidden-child
-- path broke, masking exactly the regression this lane must catch.
UPDATE ${DB_PREFIX}content SET language='de-DE' WHERE id = 9004;
EOSQL
    echo "Multilingual SEF fixture applied"
fi

# Create OSMap sitemap and link it to mainmenu so the HTTP test can call
# index.php?option=com_osmap&view=xml&id=1
echo "Creating OSMap sitemap..."
MAINMENU_ID=$(mysql -h mysql -u joomla -pjoomla_pass joomla_db -sN \
    -e "SELECT id FROM ${DB_PREFIX}menu_types WHERE menutype='mainmenu' LIMIT 1;" 2>/dev/null || echo "0")
echo "mainmenu id: ${MAINMENU_ID}"

# Read com_osmap extension_id from the official Joomla installation.
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
