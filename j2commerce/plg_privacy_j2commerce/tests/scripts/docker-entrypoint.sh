#!/bin/bash
set -e

echo "=== J2Commerce Test Environment Setup ==="
echo "Extension: ${EXTENSION_NAME:-unknown}"
echo "=========================================="

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

# Install J2Commerce (real installation from GitHub release)
echo "Installing J2Commerce..."
if [ -f /tmp/j2commerce.zip ]; then
    cp /tmp/j2commerce.zip /var/www/html/tmp/j2commerce.zip
    if php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/j2commerce.zip 2>&1; then
        echo "J2Commerce installed via Joomla CLI"
    else
        echo "J2Commerce CLI install failed, trying direct SQL schema import..."
        # Fallback: download and import SQL schema directly from GitHub
        curl -sL "https://raw.githubusercontent.com/j2commerce/j2cart/main/administrator/components/com_j2store/sql/install/mysql/install.j2store.sql" \
            | sed "s/#__/${DB_PREFIX}/g" \
            | mysql -h mysql -u joomla -pjoomla_pass joomla_db 2>/dev/null \
            && echo "J2Commerce schema imported from GitHub" \
            || echo "WARNING: J2Commerce schema import failed"
    fi
else
    echo "ERROR: J2Commerce ZIP not found at /tmp/j2commerce.zip"
    exit 1
fi

# Install privacy plugin extension
echo "Installing privacy plugin extension..."
cp /tmp/extension.zip /var/www/html/tmp/extension.zip
if php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/extension.zip; then
    echo "Extension installed via Joomla CLI"
else
    echo "ERROR: Extension installation FAILED"
    exit 1
fi

# Enable all newly installed plugins (disabled by default)
echo "Enabling installed plugins..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db \
    -e "UPDATE ${DB_PREFIX}extensions SET enabled = 1 WHERE enabled = 0 AND type = 'plugin';" 2>&1 \
    && echo "Plugins enabled" \
    || echo "WARNING: Could not enable plugins via DB"

# Insert test data into real J2Commerce tables
echo "Inserting test data..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db 2>/dev/null << EOSQL
-- Create test user (ID 100)
INSERT IGNORE INTO ${DB_PREFIX}users (id, name, username, email, password, block, sendEmail, registerDate, params)
VALUES (100, 'Test User', 'testuser', 'test@example.com', '', 0, 0, NOW(), '{}');
INSERT IGNORE INTO ${DB_PREFIX}user_usergroup_map (user_id, group_id) VALUES (100, 2);

-- Test addresses
INSERT INTO ${DB_PREFIX}j2store_addresses (user_id, first_name, last_name, email, address_1, city, zip, country_id, type)
VALUES
(100, 'Test', 'User', 'test@example.com', 'Teststrasse 1', 'Zürich', '8000', 204, 'billing'),
(100, 'Test', 'User', 'test@example.com', 'Teststrasse 1', 'Zürich', '8000', 204, 'shipping');

-- Test order within retention period (1 year old)
INSERT INTO ${DB_PREFIX}j2store_orders (order_id, user_id, user_email, order_total, order_subtotal, order_tax, order_shipping, order_discount, order_state_id, order_state, currency_code, currency_value, created_on)
VALUES ('ORD-2024-001', 100, 'test@example.com', 199.00000, 180.00000, 19.00000, 0.00000, 0.00000, 1, 'Confirmed', 'CHF', 1.00000000, DATE_SUB(NOW(), INTERVAL 1 YEAR));

-- Test order outside retention period (11 years old)
INSERT INTO ${DB_PREFIX}j2store_orders (order_id, user_id, user_email, order_total, order_subtotal, order_tax, order_shipping, order_discount, order_state_id, order_state, currency_code, currency_value, created_on)
VALUES ('ORD-2013-001', 100, 'test@example.com', 99.00000, 90.00000, 9.00000, 0.00000, 0.00000, 1, 'Confirmed', 'CHF', 1.00000000, DATE_SUB(NOW(), INTERVAL 11 YEAR));

-- Order info (billing/shipping) for both orders
INSERT INTO ${DB_PREFIX}j2store_orderinfos (order_id, billing_first_name, billing_last_name, billing_address_1, billing_city, billing_zip, billing_country_id, billing_phone_1, shipping_first_name, shipping_last_name, shipping_address_1, shipping_city, shipping_zip, shipping_country_id)
VALUES
('ORD-2024-001', 'Test', 'User', 'Teststrasse 1', 'Zürich', '8000', 204, '+41 44 123 45 67', 'Test', 'User', 'Teststrasse 1', 'Zürich', '8000', 204),
('ORD-2013-001', 'Test', 'User', 'Alte Strasse 5', 'Bern', '3000', 204, '+41 31 987 65 43', 'Test', 'User', 'Alte Strasse 5', 'Bern', '3000', 204);

-- Order items
INSERT INTO ${DB_PREFIX}j2store_orderitems (order_id, product_id, variant_id, orderitem_sku, orderitem_name, orderitem_quantity, orderitem_price, orderitem_finalprice)
VALUES
('ORD-2024-001', 1, 1, 'PROD-001', 'Test Product', '1', 180.00000, 199.00000),
('ORD-2013-001', 2, 2, 'PROD-002', 'Old Product', '1', 90.00000, 99.00000);

-- Test cart
INSERT INTO ${DB_PREFIX}j2store_carts (user_id, session_id, cart_type, created_on)
VALUES (100, 'test-session-123', 'cart', NOW());

-- Test cart item
INSERT INTO ${DB_PREFIX}j2store_cartitems (cart_id, product_id, variant_id, product_qty)
VALUES (LAST_INSERT_ID(), 1, 1, 2.0000);
EOSQL
echo "Test data inserted"

# Install minimal AcyMailing schema for integration tests.
# Only the tables the plugin accesses are created — no full AcyMailing install required.
echo "Installing AcyMailing test schema..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db 2>/dev/null << EOACYM
CREATE TABLE IF NOT EXISTS ${DB_PREFIX}acym_configuration (
    id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name  VARCHAR(255) NOT NULL,
    value TEXT,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ${DB_PREFIX}acym_list (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) DEFAULT NULL,
    visible      TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ${DB_PREFIX}acym_user (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255) NOT NULL,
    name          VARCHAR(255) DEFAULT NULL,
    confirmed     TINYINT(1)   NOT NULL DEFAULT 0,
    creation_date DATETIME     DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_acym_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ${DB_PREFIX}acym_user_has_list (
    user_id           INT UNSIGNED NOT NULL,
    list_id           INT UNSIGNED NOT NULL,
    status            TINYINT(1)   NOT NULL DEFAULT 1,
    subscription_date DATETIME     DEFAULT NULL,
    unsubscribe_date  DATETIME     DEFAULT NULL,
    PRIMARY KEY (user_id, list_id),
    CONSTRAINT fk_acym_uhl_user FOREIGN KEY (user_id) REFERENCES ${DB_PREFIX}acym_user (id),
    CONSTRAINT fk_acym_uhl_list FOREIGN KEY (list_id) REFERENCES ${DB_PREFIX}acym_list (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: one visible list
INSERT IGNORE INTO ${DB_PREFIX}acym_list (id, name, display_name, visible)
VALUES (1, 'newsletter', 'Newsletter', 1);

-- Seed: test subscriber used by export tests
INSERT IGNORE INTO ${DB_PREFIX}acym_user (email, name, confirmed, creation_date)
VALUES ('acym-test@example.com', 'AcyMailing Test User', 1, NOW());

-- Seed: list subscription for the test subscriber
INSERT IGNORE INTO ${DB_PREFIX}acym_user_has_list (user_id, list_id, status, subscription_date)
SELECT id, 1, 1, NOW() FROM ${DB_PREFIX}acym_user WHERE email = 'acym-test@example.com';
EOACYM
echo "AcyMailing test schema installed"

# Create a simple health check file
echo "OK" > /var/www/html/health.txt
chown www-data:www-data /var/www/html/health.txt

echo "Container ready"

# Keep container running
wait $JOOMLA_PID
