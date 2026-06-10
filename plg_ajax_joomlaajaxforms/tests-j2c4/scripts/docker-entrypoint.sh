#!/bin/bash
set -e

JOOMLA_ROOT="/var/www/html"

(
    # Wait for Joomla auto-install to complete.
    # Check all three conditions: configuration.php written, /installation removed,
    # and jos_extensions populated. Checking only jos_extensions is insufficient
    # because the table may exist before the installer finishes writing configuration.php.
    echo "[j2c4] Waiting for Joomla installation..."
    timeout 300 bash -c '
        until [ -f /var/www/html/configuration.php ] \
           && [ ! -d /var/www/html/installation ] \
           && php -r "
                \$db=new mysqli(\"db\",\"joomla\",\"joomla\",\"joomla_test\");
                if(\$db->connect_error)exit(1);
                \$r=\$db->query(\"SELECT 1 FROM jos_extensions LIMIT 1\");
                exit(\$r&&\$r->num_rows>0?0:1);
              " 2>/dev/null; do
            sleep 5
        done
    '
    echo "[j2c4] Joomla ready."

    # Install J2Commerce 4
    echo "[j2c4] Installing J2Commerce 4..."
    php "$JOOMLA_ROOT/cli/joomla.php" extension:install \
        --path=/tmp/j2commerce4.zip \
        --no-interaction
    echo "[j2c4] J2Commerce 4 installed."

    # Install our plugin
    echo "[j2c4] Installing plg_ajax_joomlaajaxforms..."
    php "$JOOMLA_ROOT/cli/joomla.php" extension:install \
        --path=/tmp/extension.zip \
        --no-interaction
    echo "[j2c4] Plugin installed."

    # Enable the plugin via DB (extension:enable CLI does not exist in Joomla 5/6)
    DB_PREFIX=$(php -r "require '$JOOMLA_ROOT/configuration.php'; echo (new JConfig)->dbprefix;" 2>/dev/null || echo "jos_")
    mysql -h db -u joomla -pjoomla joomla_test \
        -e "UPDATE ${DB_PREFIX}extensions SET enabled = 1 WHERE type = 'plugin' AND folder = 'ajax' AND element = 'joomlaajaxforms';" \
        && echo "[j2c4] Plugin enabled." \
        || echo "[j2c4] WARNING: Could not enable plugin via DB."

    # Seed test cart data: one user (id=999), one cart, two cart items
    echo "[j2c4] Seeding cart data..."
    mysql -h db -u joomla -pjoomla joomla_test <<'SQL'
INSERT IGNORE INTO jos_users
    (id, name, username, email, password, block, sendEmail, registerDate, lastvisitDate, activation, params, lastResetTime, resetCount, otpKey, otep, requireReset, authProvider)
VALUES
    (999, 'Test Buyer', 'testbuyer', 'buyer@test.local',
     '$2y$10$UDYH9RfpaxsMgLiDAnLeCeeJp9diqq9.tdIxunQ8/F114hDpJGpM2', -- Test1234!
     0, 0, NOW(), NOW(), '', '{}', NOW(), 0, '', '', 0, '');

-- Group 2 = Registered; required for Joomla HTTP login to succeed
INSERT IGNORE INTO jos_user_usergroup_map (user_id, group_id) VALUES (999, 2);

-- j2store_carts: created_on, modified_on, customer_ip, cart_params, cart_browser, cart_analytics are NOT NULL
INSERT IGNORE INTO jos_j2store_carts
    (j2store_cart_id, user_id, session_id, cart_type, created_on, modified_on, customer_ip, cart_params, cart_browser, cart_analytics)
VALUES
    (1, 999, 'testsession999', 'cart', NOW(), NOW(), '127.0.0.1', '{}', '', '');

-- j2store_cartitems: no created_on/modified_on; variant_id, vendor_id, cartitem_params are NOT NULL
INSERT IGNORE INTO jos_j2store_cartitems
    (j2store_cartitem_id, cart_id, product_id, variant_id, vendor_id, product_type, cartitem_params, product_qty, product_options)
VALUES
    (1, 1, 1, 0, 0, 'simple', '{}', 2, '{}'),
    (2, 1, 2, 0, 0, 'simple', '{}', 1, '{}');
SQL
    echo "[j2c4] Cart data seeded."

    # Signal ready
    echo "OK" > "$JOOMLA_ROOT/health.txt"
    echo "[j2c4] Setup complete."
) &

exec /entrypoint.sh "$@"
