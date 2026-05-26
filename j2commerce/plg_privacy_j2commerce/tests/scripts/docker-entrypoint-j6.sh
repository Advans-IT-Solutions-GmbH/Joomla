#!/bin/bash
set -e

echo "=== J2Commerce Test Environment Setup (J6 Stack) ==="
echo "Extension: ${EXTENSION_NAME:-unknown}"
echo "====================================================="

# Wait for MySQL to be ready (max 120s).
# Use root credentials for the ping — the joomla user may not yet be
# provisioned when mysqladmin first succeeds on localhost.
echo "Waiting for MySQL..."
MYSQL_WAIT=0
until mysqladmin ping -h mysql -u root -proot_pass --silent 2>/dev/null \
    || mysql -h mysql -u root -proot_pass -e "SELECT 1" &>/dev/null 2>&1; do
    sleep 2
    MYSQL_WAIT=$((MYSQL_WAIT + 2))
    if [ $MYSQL_WAIT -ge 120 ]; then
        echo "ERROR: MySQL did not become ready within 120s"
        exit 1
    fi
done
echo "MySQL is ready"

# Run original Joomla entrypoint in background
/entrypoint.sh apache2-foreground &
JOOMLA_PID=$!

echo "Waiting for Joomla files..."
sleep 10

if [ ! -f /var/www/html/configuration.php ]; then
    echo "Installing Joomla via CLI..."

    until [ -f /var/www/html/installation/joomla.php ] || [ -f /var/www/html/cli/joomla.php ]; do
        sleep 2
    done

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

        echo "Importing Joomla database schema..."
        for f in base extensions supports; do
            [ -f /var/www/html/installation/sql/mysql/${f}.sql ] && \
                mysql -h mysql -u joomla -pjoomla_pass joomla_db < /var/www/html/installation/sql/mysql/${f}.sql 2>/dev/null || true
        done

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

echo "Waiting for Joomla configuration..."
until [ -f /var/www/html/configuration.php ]; do
    sleep 2
done

DB_PREFIX=$(php -r "require '/var/www/html/configuration.php'; echo (new JConfig)->dbprefix;" 2>/dev/null || echo "j_")
echo "DB prefix: ${DB_PREFIX}"

# No public J2Commerce 6.x release exists yet. Import the minimal schema
# required for privacy plugin tests directly.
echo "Creating J2Commerce 6 schema..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db 2>/dev/null <<EOMINIMAL
CREATE TABLE IF NOT EXISTS ${DB_PREFIX}j2commerce_orders (
    j2commerce_order_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id            VARCHAR(50)  NOT NULL,
    user_id             INT UNSIGNED NOT NULL DEFAULT 0,
    user_email          VARCHAR(255) NOT NULL DEFAULT '',
    order_total         DECIMAL(15,5) NOT NULL DEFAULT 0.00000,
    order_subtotal      DECIMAL(15,5) NOT NULL DEFAULT 0.00000,
    order_tax           DECIMAL(15,5) NOT NULL DEFAULT 0.00000,
    order_shipping      DECIMAL(15,5) NOT NULL DEFAULT 0.00000,
    order_discount      DECIMAL(15,5) NOT NULL DEFAULT 0.00000,
    order_state         VARCHAR(50)  NOT NULL DEFAULT '',
    currency_code       VARCHAR(10)  NOT NULL DEFAULT 'CHF',
    currency_value      DECIMAL(15,8) NOT NULL DEFAULT 1.00000000,
    customer_note       TEXT,
    ip_address          VARCHAR(45)  NOT NULL DEFAULT '',
    created_on          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modified_on         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (j2commerce_order_id),
    UNIQUE KEY uq_order_id (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ${DB_PREFIX}j2commerce_orderitems (
    j2commerce_orderitem_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id                VARCHAR(50)  NOT NULL,
    product_id              INT UNSIGNED NOT NULL DEFAULT 0,
    orderitem_name          VARCHAR(255) NOT NULL DEFAULT '',
    orderitem_sku           VARCHAR(100) NOT NULL DEFAULT '',
    orderitem_quantity      DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
    orderitem_price         DECIMAL(15,5) NOT NULL DEFAULT 0.00000,
    orderitem_finalprice    DECIMAL(15,5) NOT NULL DEFAULT 0.00000,
    PRIMARY KEY (j2commerce_orderitem_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ${DB_PREFIX}j2commerce_orderinfos (
    j2commerce_orderinfo_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id                VARCHAR(50)  NOT NULL,
    billing_first_name      VARCHAR(100) NOT NULL DEFAULT '',
    billing_last_name       VARCHAR(100) NOT NULL DEFAULT '',
    billing_middle_name     VARCHAR(100) NOT NULL DEFAULT '',
    billing_address_1       VARCHAR(255) NOT NULL DEFAULT '',
    billing_address_2       VARCHAR(255) NOT NULL DEFAULT '',
    billing_city            VARCHAR(100) NOT NULL DEFAULT '',
    billing_zip             VARCHAR(20)  NOT NULL DEFAULT '',
    billing_company         VARCHAR(255) NOT NULL DEFAULT '',
    billing_tax_number      VARCHAR(100) NOT NULL DEFAULT '',
    billing_phone_1         VARCHAR(50)  NOT NULL DEFAULT '',
    billing_phone_2         VARCHAR(50)  NOT NULL DEFAULT '',
    billing_fax             VARCHAR(50)  NOT NULL DEFAULT '',
    shipping_first_name     VARCHAR(100) NOT NULL DEFAULT '',
    shipping_last_name      VARCHAR(100) NOT NULL DEFAULT '',
    shipping_middle_name    VARCHAR(100) NOT NULL DEFAULT '',
    shipping_address_1      VARCHAR(255) NOT NULL DEFAULT '',
    shipping_address_2      VARCHAR(255) NOT NULL DEFAULT '',
    shipping_city           VARCHAR(100) NOT NULL DEFAULT '',
    shipping_zip            VARCHAR(20)  NOT NULL DEFAULT '',
    shipping_company        VARCHAR(255) NOT NULL DEFAULT '',
    shipping_tax_number     VARCHAR(100) NOT NULL DEFAULT '',
    shipping_phone_1        VARCHAR(50)  NOT NULL DEFAULT '',
    shipping_phone_2        VARCHAR(50)  NOT NULL DEFAULT '',
    shipping_fax            VARCHAR(50)  NOT NULL DEFAULT '',
    PRIMARY KEY (j2commerce_orderinfo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ${DB_PREFIX}j2commerce_addresses (
    j2commerce_address_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id               INT UNSIGNED NOT NULL DEFAULT 0,
    first_name            VARCHAR(100) NOT NULL DEFAULT '',
    last_name             VARCHAR(100) NOT NULL DEFAULT '',
    email                 VARCHAR(255) NOT NULL DEFAULT '',
    address_1             VARCHAR(255) NOT NULL DEFAULT '',
    address_2             VARCHAR(255) NOT NULL DEFAULT '',
    city                  VARCHAR(100) NOT NULL DEFAULT '',
    zip                   VARCHAR(20)  NOT NULL DEFAULT '',
    company               VARCHAR(255) NOT NULL DEFAULT '',
    type                  VARCHAR(20)  NOT NULL DEFAULT 'billing',
    PRIMARY KEY (j2commerce_address_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ${DB_PREFIX}j2commerce_carts (
    j2commerce_cart_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            INT UNSIGNED NOT NULL DEFAULT 0,
    session_id         VARCHAR(200) NOT NULL DEFAULT '',
    cart_type          VARCHAR(20)  NOT NULL DEFAULT 'cart',
    created_on         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (j2commerce_cart_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ${DB_PREFIX}j2commerce_cartitems (
    j2commerce_cartitem_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_id                INT UNSIGNED NOT NULL DEFAULT 0,
    product_id             INT UNSIGNED NOT NULL DEFAULT 0,
    product_qty            DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
    PRIMARY KEY (j2commerce_cartitem_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
EOMINIMAL
echo "J2Commerce 6 schema created"

# Install privacy plugin extension
echo "Installing privacy plugin extension..."
cp /tmp/extension.zip /var/www/html/tmp/extension.zip
if php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/extension.zip; then
    echo "Extension installed via Joomla CLI"
else
    echo "ERROR: Extension installation FAILED"
    exit 1
fi

echo "Enabling installed plugins..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db \
    -e "UPDATE ${DB_PREFIX}extensions SET enabled = 1 WHERE enabled = 0 AND type = 'plugin';" 2>&1 \
    && echo "Plugins enabled" \
    || echo "WARNING: Could not enable plugins"

# Insert J2Commerce 6 test data (#__j2commerce_* tables)
echo "Inserting J2Commerce 6 test data..."
mysql -h mysql -u joomla -pjoomla_pass joomla_db 2>/dev/null << EOSQL
-- Test user (ID 100)
INSERT IGNORE INTO ${DB_PREFIX}users (id, name, username, email, password, block, sendEmail, registerDate, params)
VALUES (100, 'Test User', 'testuser', 'test@example.com', '', 0, 0, NOW(), '{}');
INSERT IGNORE INTO ${DB_PREFIX}user_usergroup_map (user_id, group_id) VALUES (100, 2);

-- Addresses
INSERT INTO ${DB_PREFIX}j2commerce_addresses (user_id, first_name, last_name, email, address_1, city, zip, type)
VALUES
(100, 'Test', 'User', 'test@example.com', 'Teststrasse 1', 'Zürich', '8000', 'billing'),
(100, 'Test', 'User', 'test@example.com', 'Teststrasse 1', 'Zürich', '8000', 'shipping');

-- Order within retention period (1 year old)
INSERT INTO ${DB_PREFIX}j2commerce_orders (order_id, user_id, user_email, order_total, order_subtotal, order_tax, order_shipping, order_discount, order_state, currency_code, currency_value, created_on)
VALUES ('ORD-J6-2024-001', 100, 'test@example.com', 199.00000, 180.00000, 19.00000, 0.00000, 0.00000, 'confirmed', 'CHF', 1.00000000, DATE_SUB(NOW(), INTERVAL 1 YEAR));

-- Order outside retention period (11 years old)
INSERT INTO ${DB_PREFIX}j2commerce_orders (order_id, user_id, user_email, order_total, order_subtotal, order_tax, order_shipping, order_discount, order_state, currency_code, currency_value, created_on)
VALUES ('ORD-J6-2013-001', 100, 'test@example.com', 99.00000, 90.00000, 9.00000, 0.00000, 0.00000, 'confirmed', 'CHF', 1.00000000, DATE_SUB(NOW(), INTERVAL 11 YEAR));

-- Order infos with all PII fields (including billing_fax, billing_middle_name, etc.)
INSERT INTO ${DB_PREFIX}j2commerce_orderinfos (order_id, billing_first_name, billing_last_name, billing_middle_name, billing_address_1, billing_city, billing_zip, billing_phone_1, billing_phone_2, billing_fax, billing_company, billing_tax_number, shipping_first_name, shipping_last_name, shipping_middle_name, shipping_address_1, shipping_city, shipping_zip, shipping_phone_1, shipping_phone_2, shipping_fax, shipping_tax_number)
VALUES
('ORD-J6-2024-001', 'Test', 'User', 'M.', 'Teststrasse 1', 'Zürich', '8000', '+41 44 123 45 67', '+41 79 123 45 67', '+41 44 123 45 68', 'Test AG', 'CHE-123.456.789', 'Test', 'User', 'M.', 'Teststrasse 1', 'Zürich', '8000', '+41 44 123 45 67', '+41 79 123 45 67', '+41 44 123 45 68', 'CHE-123.456.789'),
('ORD-J6-2013-001', 'Test', 'User', 'M.', 'Alte Strasse 5', 'Bern', '3000', '+41 31 987 65 43', '', '', 'Test AG', '', 'Test', 'User', '', 'Alte Strasse 5', 'Bern', '3000', '+41 31 987 65 43', '', '', '');

-- Order items
INSERT INTO ${DB_PREFIX}j2commerce_orderitems (order_id, product_id, orderitem_sku, orderitem_name, orderitem_quantity, orderitem_price, orderitem_finalprice)
VALUES
('ORD-J6-2024-001', 1, 'PROD-J6-001', 'Test Product J6', 1.0000, 180.00000, 199.00000),
('ORD-J6-2013-001', 2, 'PROD-J6-002', 'Old Product J6', 1.0000, 90.00000, 99.00000);

-- Cart
INSERT INTO ${DB_PREFIX}j2commerce_carts (user_id, session_id, cart_type, created_on)
VALUES (100, 'test-session-j6-123', 'cart', NOW());

-- Cart item
INSERT INTO ${DB_PREFIX}j2commerce_cartitems (cart_id, product_id, product_qty)
VALUES (LAST_INSERT_ID(), 1, 2.0000);
EOSQL
echo "J2Commerce 6 test data inserted"

# AcyMailing schema (same as J5 stack)
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

INSERT IGNORE INTO ${DB_PREFIX}acym_list (id, name, display_name, visible) VALUES (1, 'newsletter', 'Newsletter', 1);
INSERT IGNORE INTO ${DB_PREFIX}acym_user (email, name, confirmed, creation_date) VALUES ('acym-test@example.com', 'AcyMailing Test User', 1, NOW());
INSERT IGNORE INTO ${DB_PREFIX}acym_user_has_list (user_id, list_id, status, subscription_date)
SELECT id, 1, 1, NOW() FROM ${DB_PREFIX}acym_user WHERE email = 'acym-test@example.com';
EOACYM
echo "AcyMailing test schema installed"

echo "OK" > /var/www/html/health.txt
chown www-data:www-data /var/www/html/health.txt

echo "Container ready (J6 stack)"

wait $JOOMLA_PID
