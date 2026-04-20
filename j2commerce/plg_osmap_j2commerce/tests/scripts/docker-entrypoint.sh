#!/bin/bash
set -e

echo "=== OSMap J2Commerce Test Environment Setup ==="
echo "Extension: ${EXTENSION_NAME:-unknown}"
echo "==============================================="

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
until mysql -h mysql -u joomla -pjoomla_pass -e "SELECT 1" &>/dev/null; do
    sleep 2
done
echo "MySQL is ready"

# Run original Joomla entrypoint in background
/entrypoint.sh apache2-foreground &
JOOMLA_PID=$!

# Wait for Joomla files to be extracted
echo "Waiting for Joomla files..."
sleep 10

# Check if Joomla needs installation
if [ ! -f /var/www/html/configuration.php ]; then
    echo "Installing Joomla via CLI..."

    # Wait for files to be fully extracted
    until [ -f /var/www/html/installation/joomla.php ] || [ -f /var/www/html/cli/joomla.php ]; do
        sleep 2
    done

    # Use Joomla CLI installer (Joomla 4+)
    if [ -f /var/www/html/cli/joomla.php ]; then
        php /var/www/html/cli/joomla.php site:install \
            --db-host=mysql \
            --db-user=joomla \
            --db-pass=joomla_pass \
            --db-name=joomla_db \
            --db-prefix=j_ \
            --db-type=mysql \
            --site-name="Test Site" \
            --admin-user="admin" \
            --admin-username="admin" \
            --admin-password="Admin123!" \
            --admin-email="admin@test.local" \
            --no-interaction 2>&1 || echo "CLI install attempted"
    fi

    # Fallback: Create configuration.php manually if CLI failed
    if [ ! -f /var/www/html/configuration.php ]; then
        echo "Creating configuration.php manually..."
        cat > /var/www/html/configuration.php << 'EOFCONFIG'
<?php
class JConfig {
    public $offline = false;
    public $offline_message = 'This site is down for maintenance.';
    public $display_offline_message = 1;
    public $offline_image = '';
    public $sitename = 'Test Site';
    public $editor = 'tinymce';
    public $captcha = '0';
    public $list_limit = 20;
    public $access = 1;
    public $debug = false;
    public $debug_lang = false;
    public $debug_lang_const = true;
    public $dbtype = 'mysqli';
    public $host = 'mysql';
    public $user = 'joomla';
    public $password = 'joomla_pass';
    public $db = 'joomla_db';
    public $dbprefix = 'j_';
    public $dbencryption = 0;
    public $dbsslverifyservercert = false;
    public $dbsslkey = '';
    public $dbsslcert = '';
    public $dbsslca = '';
    public $dbsslcipher = '';
    public $force_ssl = 0;
    public $live_site = '';
    public $secret = 'testsecret123456';
    public $gzip = false;
    public $error_reporting = 'default';
    public $helpurl = 'https://help.joomla.org/proxy';
    public $tmp_path = '/var/www/html/tmp';
    public $log_path = '/var/www/html/administrator/logs';
    public $lifetime = 15;
    public $session_handler = 'database';
    public $shared_session = false;
    public $session_metadata = true;
}
EOFCONFIG
        chown www-data:www-data /var/www/html/configuration.php

        # Import Joomla schema
        echo "Importing Joomla database schema..."
        if [ -f /var/www/html/installation/sql/mysql/base.sql ]; then
            mysql -h mysql -u joomla -pjoomla_pass joomla_db < /var/www/html/installation/sql/mysql/base.sql 2>/dev/null || true
        fi
        if [ -f /var/www/html/installation/sql/mysql/extensions.sql ]; then
            mysql -h mysql -u joomla -pjoomla_pass joomla_db < /var/www/html/installation/sql/mysql/extensions.sql 2>/dev/null || true
        fi
        if [ -f /var/www/html/installation/sql/mysql/supports.sql ]; then
            mysql -h mysql -u joomla -pjoomla_pass joomla_db < /var/www/html/installation/sql/mysql/supports.sql 2>/dev/null || true
        fi

        # Create admin user
        ADMIN_PASS_HASH=$(php -r "echo password_hash('Admin123!', PASSWORD_BCRYPT);")
        mysql -h mysql -u joomla -pjoomla_pass joomla_db -e "
            INSERT INTO j_users (id, name, username, email, password, block, sendEmail, registerDate, params)
            VALUES (42, 'Super User', 'admin', 'admin@test.local', '$ADMIN_PASS_HASH', 0, 1, NOW(), '{}')
            ON DUPLICATE KEY UPDATE password='$ADMIN_PASS_HASH';
            INSERT INTO j_user_usergroup_map (user_id, group_id) VALUES (42, 8) ON DUPLICATE KEY UPDATE group_id=8;
        " 2>/dev/null || true
    fi

    echo "Joomla installation complete"
fi

# Wait for configuration.php
echo "Waiting for Joomla configuration..."
until [ -f /var/www/html/configuration.php ]; do
    sleep 2
done

# Get DB prefix
DB_PREFIX=$(php -r "require '/var/www/html/configuration.php'; echo (new JConfig)->dbprefix;" 2>/dev/null || echo "j_")
echo "DB prefix: ${DB_PREFIX}"

# Install OSMap
echo "Installing OSMap..."
if [ -f /tmp/osmap.zip ]; then
    cp /tmp/osmap.zip /var/www/html/tmp/osmap.zip
    if php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/osmap.zip 2>&1; then
        echo "OSMap installed via Joomla CLI"
    else
        echo "WARNING: OSMap CLI install failed"
    fi
else
    echo "WARNING: OSMap ZIP not found at /tmp/osmap.zip"
fi

# Install J2Commerce
echo "Installing J2Commerce..."
if [ -f /tmp/j2commerce.zip ]; then
    cp /tmp/j2commerce.zip /var/www/html/tmp/j2commerce.zip
    if php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/j2commerce.zip 2>&1; then
        echo "J2Commerce installed via Joomla CLI"
    else
        echo "WARNING: J2Commerce CLI install failed"
    fi
else
    echo "ERROR: J2Commerce ZIP not found at /tmp/j2commerce.zip"
    exit 1
fi

# Install OSMap J2Commerce plugin
echo "Installing OSMap J2Commerce plugin..."
cp /tmp/extension.zip /var/www/html/tmp/extension.zip
if php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/extension.zip; then
    echo "Plugin installed via Joomla CLI"
else
    echo "ERROR: Plugin installation FAILED"
    exit 1
fi

# Enable all newly installed plugins
echo "Enabling installed plugins..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db \
    -e "UPDATE ${DB_PREFIX}extensions SET enabled = 1 WHERE enabled = 0 AND type = 'plugin';" 2>&1 \
    && echo "Plugins enabled" \
    || echo "WARNING: Could not enable plugins via DB"

# Insert test fixtures: menu items + J2Commerce products for sitemap tests
echo "Inserting test fixtures..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db 2>/dev/null << EOSQL
-- Test menu (required for OSMap)
INSERT IGNORE INTO ${DB_PREFIX}menu_types (id, menutype, title, description)
VALUES (10, 'mainmenu', 'Main Menu', 'Test main menu');

-- J2Commerce product article (content item)
INSERT IGNORE INTO ${DB_PREFIX}content (id, title, alias, introtext, fulltext, state, catid, created, modified, publish_up, language, access)
VALUES (1, 'Test Product', 'test-product', 'A test product', '', 1, 2, NOW(), NOW(), NOW(), '*', 1);

-- J2Commerce product record
INSERT IGNORE INTO ${DB_PREFIX}j2store_products (j2store_product_id, product_source_id, product_source, product_sku, product_price, product_visibility, enabled)
VALUES (1, 1, 'com_content', 'TEST-001', 99.00, 1, 1);

-- J2Commerce product variant
INSERT IGNORE INTO ${DB_PREFIX}j2store_variants (j2store_variant_id, j2store_product_id, variant_sku, price, is_master)
VALUES (1, 1, 'TEST-001', 99.00, 1);

-- Menu item linking to J2Commerce product (published=-2 = J2Commerce SEF item)
INSERT IGNORE INTO ${DB_PREFIX}menu (id, menutype, title, alias, path, link, type, published, parent_id, level, component_id, language, access, params)
VALUES (
    100, 'mainmenu', 'Test Product', 'test-product', 'test-product',
    'index.php?option=com_content&view=article&id=1',
    'component', -2, 1, 1,
    (SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_content' LIMIT 1),
    '*', 1, '{}'
);
EOSQL
echo "Test fixtures inserted"

# Write health file — signals container is ready for tests
echo "OK" > /var/www/html/health.txt
chown www-data:www-data /var/www/html/health.txt

echo "=== Container ready ==="

# Keep container running
wait $JOOMLA_PID
