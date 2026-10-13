<?php
/**
 * Installation tests for the Appointments component.
 */

class InstallationTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $dbPrefix;

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->dbPrefix = $config->dbprefix;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);
    }

    public function run(): bool
    {
        echo "=== Installation Tests ===\n\n";

        $this->test('Component registered in extensions table', function () {
            $result = $this->db->query("SELECT extension_id FROM {$this->dbPrefix}extensions WHERE element = 'com_appointments' AND type = 'component'");
            return $result && $result->num_rows > 0;
        });

        $this->test('Component is enabled', function () {
            $result = $this->db->query("SELECT enabled FROM {$this->dbPrefix}extensions WHERE element = 'com_appointments'");
            $row = $result ? $result->fetch_assoc() : null;
            return $row && $row['enabled'] == 1;
        });

        $this->test('Admin menu entry exists', function () {
            $result = $this->db->query("SELECT id FROM {$this->dbPrefix}menu WHERE link LIKE '%com_appointments%' AND client_id = 1");
            return $result && $result->num_rows > 0;
        });

        $this->test('Administrator directory exists', function () {
            return is_dir('/var/www/html/administrator/components/com_appointments');
        });

        $this->test('Site directory exists', function () {
            // The booking pages live in the site part; a component installed
            // without it would offer no public booking page at all.
            return is_dir('/var/www/html/components/com_appointments');
        });

        $this->test('Administrator entry service exists', function () {
            return file_exists('/var/www/html/administrator/components/com_appointments/services/provider.php');
        });

        $this->test('Site entry service exists', function () {
            return file_exists('/var/www/html/components/com_appointments/services/provider.php');
        });

        $this->test('Media assets are installed and declared', function () {
            return file_exists('/var/www/html/media/com_appointments/joomla.asset.json')
                && file_exists('/var/www/html/media/com_appointments/css/appointments.css');
        });

        $this->test('Update site registered', function () {
            $result = $this->db->query(
                "SELECT s.update_site_id FROM {$this->dbPrefix}update_sites AS s"
                . " INNER JOIN {$this->dbPrefix}update_sites_extensions AS m ON m.update_site_id = s.update_site_id"
                . " INNER JOIN {$this->dbPrefix}extensions AS e ON e.extension_id = m.extension_id"
                . " WHERE e.element = 'com_appointments'"
            );
            return $result && $result->num_rows > 0;
        });

        foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
            $this->test("Administrator language file {$tag} exists", function () use ($tag) {
                return file_exists("/var/www/html/administrator/language/{$tag}/com_appointments.ini")
                    || file_exists("/var/www/html/administrator/components/com_appointments/language/{$tag}/com_appointments.ini");
            });

            $this->test("Site language file {$tag} exists", function () use ($tag) {
                return file_exists("/var/www/html/language/{$tag}/com_appointments.ini")
                    || file_exists("/var/www/html/components/com_appointments/language/{$tag}/com_appointments.ini");
            });
        }

        $this->test('No signing secret is stored in the component parameters', function () {
            // The component derives every key from the Joomla site secret, so
            // nothing secret is ever written to the database, printed into the
            // Options screen or lost when that screen is saved.
            $result = $this->db->query("SELECT params FROM {$this->dbPrefix}extensions WHERE element = 'com_appointments'");
            $row = $result ? $result->fetch_assoc() : null;

            if (!$row) {
                return false;
            }

            return stripos((string) $row['params'], 'secret') === false;
        });

        echo "\n=== Installation Test Summary ===\n";
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

$test = new InstallationTest();
exit($test->run() ? 0 : 1);
