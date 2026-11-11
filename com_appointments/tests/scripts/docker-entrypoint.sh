#!/bin/bash
set -e

echo "=== Appointments Test Environment Setup ==="
echo "Extension: ${EXTENSION_NAME:-unknown}"
echo "============================================"

/entrypoint.sh apache2-foreground &
JOOMLA_PID=$!

echo "Waiting for Joomla to initialize..."
TIMEOUT=120
ELAPSED=0
while [ ! -f /var/www/html/configuration.php ] && [ $ELAPSED -lt $TIMEOUT ]; do
    sleep 5
    ELAPSED=$((ELAPSED + 5))
    echo "  Waiting... ($ELAPSED/$TIMEOUT seconds)"
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

if [ -f /var/www/html/configuration.php ]; then
    echo "Joomla is initialized, installing the component..."
    php /usr/local/bin/appointments-configure-mail.php
    sleep 5

    echo "Installing extension via Joomla CLI..."
    cp /tmp/extension.zip /var/www/html/tmp/extension.zip
    if HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=/var/www/html/tmp/extension.zip; then
        echo "✅ Extension installed via Joomla CLI"
    else
        echo "❌ Extension installation FAILED via Joomla CLI"
        exit 1
    fi

    # Without a public site URL the component sends no mail and takes no booking (fail closed),
    # so the test site gets one, as every production site needs it.
    if APPOINTMENTS_PUBLIC_SITE_URL="${APPOINTMENTS_PUBLIC_SITE_URL:-http://localhost}" php -r '
        require "/var/www/html/configuration.php";
        $c = new JConfig();
        $db = new mysqli($c->host, $c->user, $c->password, $c->db);
        $t = $c->dbprefix . "extensions";
        $where = " WHERE type = \"component\" AND element = \"com_appointments\"";
        $row = $db->query("SELECT params FROM `$t`" . $where)->fetch_row();
        $p = json_decode((string) ($row[0] ?? ""), true) ?: [];
        $p["public_site_url"] = getenv("APPOINTMENTS_PUBLIC_SITE_URL");
        exit($db->query("UPDATE `$t` SET params = \"" . $db->real_escape_string(json_encode($p)) . "\"" . $where) === true ? 0 : 1);'; then
        echo "✅ Public site URL configured"
    else
        echo "❌ Public site URL could not be configured"
        exit 1
    fi

    echo "OK" > /var/www/html/health.txt
    chown www-data:www-data /var/www/html/health.txt 2>/dev/null || true
    echo "✅ Health file created"
fi

wait $JOOMLA_PID
