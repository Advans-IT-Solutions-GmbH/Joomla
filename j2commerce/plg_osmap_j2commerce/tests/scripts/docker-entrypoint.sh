#!/bin/bash
set -e

echo "=== OSMap J2Commerce Test Setup ==="
echo "Base image: Joomla + OSMap + J2Commerce already installed"
echo "==================================="

# Start Apache in background
apache2-foreground &
APACHE_PID=$!

DB_HOST="${JOOMLA_DB_HOST:-mysql}"
DB_USER="${JOOMLA_DB_USER:-joomla}"
DB_PASS="${JOOMLA_DB_PASSWORD:-joomla_pass}"
DB_NAME="${JOOMLA_DB_NAME:-joomla_db}"

# Wait for MySQL (base image has Joomla files but DB is fresh per test run)
echo "Waiting for MySQL..."
timeout 60 bash -c "until mysqladmin ping -h '$DB_HOST' -u '$DB_USER' -p'$DB_PASS' --silent 2>/dev/null; do sleep 2; done"
echo "✅ MySQL ready"

# Restore Joomla DB from base snapshot if available, else run installer
if [ -f /var/www/html/configuration.php ]; then
    echo "✅ Joomla already configured"
else
    echo "Running Joomla CLI installer..."
    php /var/www/html/cli/joomla.php installation:install \
        --site-name="Test Site" \
        --admin-user="admin" \
        --admin-username="admin" \
        --admin-password="Admin1234!" \
        --admin-email="admin@test.local" \
        --db-type="mysqli" \
        --db-host="$DB_HOST" \
        --db-user="$DB_USER" \
        --db-pass="$DB_PASS" \
        --db-name="$DB_NAME" \
        --db-prefix="j_" 2>&1
    echo "✅ Joomla installed"

    echo "Installing OSMap..."
    cp /tmp/com_osmap.zip /var/www/html/tmp/
    php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/com_osmap.zip 2>&1
    echo "✅ OSMap installed"

    echo "Installing J2Commerce..."
    cp /tmp/com_j2store.zip /var/www/html/tmp/
    php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/com_j2store.zip 2>&1
    echo "✅ J2Commerce installed"
fi

# Install the plugin under test
echo ""
echo "--- Installing plg_osmap_j2commerce ---"
cp /tmp/plg_osmap_j2commerce.zip /var/www/html/tmp/
php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/plg_osmap_j2commerce.zip 2>&1
echo "✅ plg_osmap_j2commerce installed"

# Enable all plugins
DB_PREFIX=$(php -r "require '/var/www/html/configuration.php'; echo (new JConfig)->dbprefix;" 2>/dev/null || echo "j_")
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -e "UPDATE ${DB_PREFIX}extensions SET enabled=1 WHERE type='plugin' AND enabled=0;" 2>/dev/null
echo "✅ Plugins enabled"

# Insert test fixtures
echo ""
echo "--- Inserting test fixtures ---"
ROOT_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -se "SELECT id FROM ${DB_PREFIX}menu WHERE menutype='mainmenu' AND parent_id=1 AND client_id=0 LIMIT 1;" 2>/dev/null || echo "1")
COM_CONTENT_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -se "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_content' AND type='component' LIMIT 1;" 2>/dev/null || echo "0")
COM_J2STORE_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -se "SELECT extension_id FROM ${DB_PREFIX}extensions WHERE element='com_j2store' AND type='component' LIMIT 1;" 2>/dev/null || echo "0")

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null <<SQL
INSERT INTO ${DB_PREFIX}menu (id,menutype,title,alias,link,type,published,parent_id,level,component_id,browserNav,access,img,template_style_id,params,lft,rgt,home,language,client_id)
VALUES (9001,'mainmenu','Shop','shop','index.php?option=com_j2store&view=products','component',1,$ROOT_ID,2,$COM_J2STORE_ID,0,1,'',0,'{}',100,105,0,'*',0)
ON DUPLICATE KEY UPDATE title='Shop';

INSERT INTO ${DB_PREFIX}content (id,asset_id,title,alias,introtext,fulltext,state,catid,created,modified,language,metadesc,metakey,access,hits,featured)
VALUES
    (9001,0,'Test Product Alpha','test-product-alpha','<p>Alpha.</p>','',1,2,NOW(),NOW(),'*','Alpha desc','alpha',1,0,0),
    (9002,0,'Test Product Beta','test-product-beta','<p>Beta.</p>','',1,2,NOW(),NOW(),'*','Beta desc','beta',1,0,0)
ON DUPLICATE KEY UPDATE title=VALUES(title);

INSERT INTO ${DB_PREFIX}menu (id,menutype,title,alias,link,type,published,parent_id,level,component_id,browserNav,access,img,template_style_id,params,lft,rgt,home,language,client_id)
VALUES
    (9002,'mainmenu','Test Product Alpha','test-product-alpha','index.php?option=com_content&view=article&id=9001','component',-2,9001,3,$COM_CONTENT_ID,0,1,'',0,'{}',101,102,0,'*',0),
    (9003,'mainmenu','Test Product Beta','test-product-beta','index.php?option=com_content&view=article&id=9002','component',-2,9001,3,$COM_CONTENT_ID,0,1,'',0,'{}',103,104,0,'*',0)
ON DUPLICATE KEY UPDATE title=VALUES(title);

INSERT INTO ${DB_PREFIX}j2store_products (j2store_product_id,product_source_id,product_source,product_type,enabled,created_on,modified_on)
VALUES
    (9001,9001,'com_content','simple',1,NOW(),NOW()),
    (9002,9002,'com_content','simple',1,NOW(),NOW())
ON DUPLICATE KEY UPDATE enabled=1;
SQL
echo "✅ Test fixtures inserted"

# Signal readiness
echo "READY" > /var/www/html/ready.txt
chown www-data:www-data /var/www/html/ready.txt 2>/dev/null || true
echo ""
echo "✅ Test environment ready"

wait $APACHE_PID
