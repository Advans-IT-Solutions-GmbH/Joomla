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

        $this->test('Schema baseline registered for future migrations', function () {
            $result = $this->db->query(
                "SELECT s.version_id FROM {$this->dbPrefix}schemas AS s"
                . " INNER JOIN {$this->dbPrefix}extensions AS e ON e.extension_id = s.extension_id"
                . " WHERE e.element = 'com_appointments' AND e.type = 'component'"
            );
            $row = $result ? $result->fetch_assoc() : null;

            return $row && $row['version_id'] === '1.0.0';
        });

        $this->test('Administrator language file en-GB is installed', function () {
            return file_exists('/var/www/html/administrator/language/en-GB/com_appointments.ini')
                && file_exists('/var/www/html/administrator/language/en-GB/com_appointments.sys.ini');
        });

        $this->test('Site language file en-GB is installed', function () {
            return file_exists('/var/www/html/language/en-GB/com_appointments.ini');
        });

        $this->test('Package ships de-DE and fr-FR for both parts', function () {
            // Joomla copies a <language> entry only when that language exists on
            // the target site: Installer::parseLanguages() skips an entry whose
            // destination folder is missing. The test site has en-GB only, so
            // de-DE and fr-FR are asserted in the package, which is where they
            // have to be for a site that does have those language packs. The
            // installer-messages suite then proves they are really used, because
            // it reinstalls the package once per language.
            $zip = new \ZipArchive();

            if ($zip->open('/tmp/extension.zip') !== true) {
                return false;
            }

            $expected = [
                'administrator/language/de-DE/com_appointments.ini',
                'administrator/language/de-DE/com_appointments.sys.ini',
                'administrator/language/fr-FR/com_appointments.ini',
                'administrator/language/fr-FR/com_appointments.sys.ini',
                'language/de-DE/com_appointments.ini',
                'language/fr-FR/com_appointments.ini',
            ];

            foreach ($expected as $entry) {
                if ($zip->locateName($entry) === false) {
                    $zip->close();

                    return false;
                }
            }

            $zip->close();

            return true;
        });

        $this->test('No signing secret is stored in the component parameters', function () {
            // The component derives every signing key from the Joomla site
            // secret, so no signing key is ever written to the database, printed
            // into the Options screen or lost when that screen is saved.
            //
            // Only the parameter names that would reintroduce a stored signing
            // key are rejected. A substring search for "secret" would be wrong:
            // an operator's Jitsi JWT secret is a legitimate provider setting
            // and belongs in the component parameters.
            $forbidden = ['install_secret', 'signing_secret', 'link_secret', 'hmac_secret', 'secret'];

            $result = $this->db->query("SELECT params FROM {$this->dbPrefix}extensions WHERE element = 'com_appointments'");
            $row = $result ? $result->fetch_assoc() : null;

            if (!$row) {
                return false;
            }

            $params = json_decode((string) $row['params'], true);

            if (!\is_array($params)) {
                // No parameters at all is the expected state after a fresh
                // install and satisfies the assertion.
                return true;
            }

            foreach ($forbidden as $name) {
                if (\array_key_exists($name, $params)) {
                    echo "    found forbidden parameter: {$name}\n";

                    return false;
                }
            }

            return true;
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
