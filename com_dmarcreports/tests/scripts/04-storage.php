<?php
/**
 * Storage tests: the schema stores reports idempotently. A report is identified
 * by its hash, so importing the same report twice must never create a duplicate
 * row — the UNIQUE index on report_hash is the guarantee the parser relies on.
 */

class StorageTest
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
        echo "=== Storage Tests ===\n\n";

        $hash = hash('sha256', 'example.net|storage-test|1700000000');
        $table = "{$this->dbPrefix}dmarc_reports";

        // Clean any residue from an earlier run so the test is repeatable.
        $this->db->query("DELETE FROM {$table} WHERE report_hash = '{$hash}'");

        $insert = function () use ($table, $hash): bool {
            $sql = "INSERT INTO {$table} (domain_id, org_name, report_id, report_hash, date_begin, date_end, policy_domain, message_count, created)"
                . " VALUES (1, 'example.net', 'storage-test', '{$hash}', 1700000000, 1700086400, 'example.org', 5, NOW())";
            return (bool) $this->db->query($sql);
        };

        $this->test('First insert of a report succeeds', function () use ($insert) {
            return $insert();
        });

        $this->test('Duplicate insert is rejected by the UNIQUE hash', function () use ($insert) {
            // Under PHP 8.1+ mysqli reports errors as exceptions by default, so a
            // duplicate key throws rather than returning false. Either way the
            // import must be refused; accept both signals as a rejection.
            try {
                $ok = $insert();

                return $ok === false && $this->db->errno === 1062;
            } catch (\mysqli_sql_exception $e) {
                return $e->getCode() === 1062;
            }
        });

        $this->test('Exactly one row is stored for the report', function () use ($table, $hash) {
            $result = $this->db->query("SELECT COUNT(*) AS cnt FROM {$table} WHERE report_hash = '{$hash}'");
            $row = $result ? $result->fetch_assoc() : null;
            return $row && (int) $row['cnt'] === 1;
        });

        // Tidy up so the suite leaves no test data behind.
        $this->db->query("DELETE FROM {$table} WHERE report_hash = '{$hash}'");

        echo "\n=== Storage Test Summary ===\n";
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

$test = new StorageTest();
exit($test->run() ? 0 : 1);
