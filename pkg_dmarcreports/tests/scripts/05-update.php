<?php
/**
 * Update / data-preservation tests for the DMARC Reports package.
 *
 * Reinstalling the package over an existing installation must behave as an
 * update: it must not drop the component tables and must not lose the stored
 * domains or the encrypted IMAP source credentials, and it must not create
 * duplicate extension registrations.
 */

class UpdateTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $dbPrefix;
    private $marker = 'pkg-update-test.example';
    private $secret = 'ENC::preserve-me-across-update';

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->dbPrefix = $config->dbprefix;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);
    }

    public function run(): bool
    {
        echo "=== Package Update Tests ===\n\n";

        // Seed data that must survive an update.
        $domain = $this->db->real_escape_string($this->marker);
        $secret = $this->db->real_escape_string($this->secret);
        $this->db->query("INSERT INTO {$this->dbPrefix}dmarc_domains (domain, title, published, created) VALUES ('$domain', 'Update marker', 1, NOW())");
        $domainId = (int) $this->db->insert_id;
        $this->db->query("INSERT INTO {$this->dbPrefix}dmarc_sources (name, domain_id, host, username, password_enc, published, created) VALUES ('Update marker source', $domainId, 'imap.example', 'reporter@example', '$secret', 1, NOW())");

        $this->test('Seed data inserted', function () use ($domainId) {
            return $domainId > 0;
        });

        // Reinstall the same package archive (update path).
        $output = [];
        $exitCode = 0;
        $zip = file_exists('/var/www/html/tmp/extension.zip') ? '/var/www/html/tmp/extension.zip' : '/tmp/extension.zip';
        exec("HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=$zip 2>&1", $output, $exitCode);

        $this->test('Reinstall (update) command executed', function () use ($exitCode, $output) {
            if ($exitCode !== 0) {
                echo '    ' . implode("\n    ", $output) . "\n";
            }
            return $exitCode === 0;
        });

        $this->test('Component tables still present after update', function () {
            foreach (['dmarc_domains', 'dmarc_sources', 'dmarc_reports', 'dmarc_tlsrpt_reports'] as $table) {
                $result = $this->db->query("SHOW TABLES LIKE '{$this->dbPrefix}{$table}'");
                if (!$result || $result->num_rows === 0) {
                    return false;
                }
            }
            return true;
        });

        $this->test('Stored domain preserved after update', function () {
            $domain = $this->db->real_escape_string($this->marker);
            $result = $this->db->query("SELECT id FROM {$this->dbPrefix}dmarc_domains WHERE domain = '$domain'");
            return $result && $result->num_rows === 1;
        });

        $this->test('Encrypted source credentials preserved after update', function () {
            $secret = $this->db->real_escape_string($this->secret);
            $result = $this->db->query("SELECT id FROM {$this->dbPrefix}dmarc_sources WHERE password_enc = '$secret'");
            return $result && $result->num_rows === 1;
        });

        foreach ([
            'package' => "type = 'package' AND element = 'pkg_dmarcreports'",
            'component' => "type = 'component' AND element = 'com_dmarcreports'",
            'module' => "type = 'module' AND element = 'mod_dmarcreports'",
            'task plugin' => "type = 'plugin' AND folder = 'task' AND element = 'dmarcreports'",
            'system plugin' => "type = 'plugin' AND folder = 'system' AND element = 'dmarcmtasts'",
        ] as $label => $where) {
            $this->test("Exactly one $label registration (no duplicate)", function () use ($where) {
                $result = $this->db->query("SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}extensions WHERE $where");
                $row = $result ? $result->fetch_assoc() : null;
                return $row && (int) $row['cnt'] === 1;
            });
        }

        echo "\n=== Package Update Test Summary ===\n";
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

$test = new UpdateTest();
exit($test->run() ? 0 : 1);
