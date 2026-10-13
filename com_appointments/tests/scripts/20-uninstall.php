<?php
/**
 * Uninstall tests for the Appointments component.
 */

class UninstallTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $dbPrefix;

    /**
     * Every table the install schema creates, without the `#__` prefix.
     */
    private const TABLES = [
        'appointments_schedules',
        'appointments_schedule_rules',
        'appointments_eventtypes',
        'appointments_blocked',
        'appointments_bookings',
        'appointments_fields',
        'appointments_templates',
        'appointments_audit',
        'appointments_ratelimit',
    ];

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->dbPrefix = $config->dbprefix;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);
    }

    public function run(): bool
    {
        echo "=== Uninstall Tests ===\n\n";

        $result = $this->db->query("SELECT extension_id FROM {$this->dbPrefix}extensions WHERE element = 'com_appointments' AND type = 'component'");
        $row = $result ? $result->fetch_assoc() : null;
        $extensionId = $row ? (int) $row['extension_id'] : 0;

        $this->test('Extension ID found before uninstall', function () use ($extensionId) {
            return $extensionId > 0;
        });

        $output = [];
        $exitCode = 0;
        exec("HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:remove $extensionId --no-interaction 2>&1", $output, $exitCode);

        $this->test('Uninstall command executed', function () use ($exitCode) {
            return $exitCode === 0;
        });

        $this->test('Component removed from #__extensions', function () {
            $result = $this->db->query("SELECT COUNT(*) as cnt FROM {$this->dbPrefix}extensions WHERE element = 'com_appointments'");
            $row = $result ? $result->fetch_assoc() : null;
            return $row && (int) $row['cnt'] === 0;
        });

        $this->test('Administrator files removed', function () {
            return !is_dir('/var/www/html/administrator/components/com_appointments');
        });

        $this->test('Site files removed', function () {
            return !is_dir('/var/www/html/components/com_appointments');
        });

        $this->test('Appointment tables dropped by the uninstall SQL', function () {
            // Every table created by the install schema must be gone, not just
            // one: a partial drop would leave personal data of invitees behind.
            foreach (self::TABLES as $table) {
                $result = $this->db->query("SHOW TABLES LIKE '{$this->dbPrefix}{$table}'");

                if (!$result || $result->num_rows !== 0) {
                    return false;
                }
            }

            return true;
        });

        $this->test('No update site is still linked to the removed extension', function () use ($extensionId) {
            $result = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}update_sites_extensions"
                . " WHERE extension_id = {$extensionId}"
            );
            $row = $result ? $result->fetch_assoc() : null;

            return $row && (int) $row['cnt'] === 0;
        });

        $this->test('Administrator menu entry removed', function () {
            $result = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}menu"
                . " WHERE link LIKE '%com_appointments%' AND client_id = 1"
            );
            $row = $result ? $result->fetch_assoc() : null;

            return $row && (int) $row['cnt'] === 0;
        });

        echo "\n=== Uninstall Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) { echo "✓ {$name}\n"; $this->passed++; }
            else { echo "✗ {$name}\n"; $this->failed++; }
        } catch (\Exception $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new UninstallTest();
exit($test->run() ? 0 : 1);
