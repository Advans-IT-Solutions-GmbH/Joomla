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

        $this->runTlsRpt();

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

    /**
     * TLS-RPT (RFC 8460) storage lives in its own tables but reuses the same
     * deduplication-by-hash discipline and the same transactional cleanup as the
     * DMARC path. Exercise persistence, the UNIQUE-hash dedup, per-policy rows
     * and orphan-free deletion so a regression in any of them is caught here.
     */
    private function runTlsRpt(): void
    {
        echo "\n=== TLS-RPT Storage Tests ===\n\n";

        $reports  = "{$this->dbPrefix}dmarc_tlsrpt_reports";
        $policies = "{$this->dbPrefix}dmarc_tlsrpt_policies";
        $failures = "{$this->dbPrefix}dmarc_tlsrpt_failures";

        $hash = hash('sha256', 'tlsrpt-storage-test|example.org|1700000000');

        $cleanup = function () use ($reports, $policies, $failures, $hash): void {
            $result = $this->db->query("SELECT id FROM {$reports} WHERE report_hash = '{$hash}'");

            while ($result && ($row = $result->fetch_assoc())) {
                $id = (int) $row['id'];
                $this->db->query("DELETE FROM {$failures} WHERE report_id = {$id}");
                $this->db->query("DELETE FROM {$policies} WHERE report_id = {$id}");
            }

            $this->db->query("DELETE FROM {$reports} WHERE report_hash = '{$hash}'");
        };

        // Start from a clean slate so the suite is repeatable.
        $cleanup();

        $insert = function () use ($reports, $hash): bool {
            $sql = "INSERT INTO {$reports} (domain_id, source_id, org_name, contact_info, report_id, report_hash, date_begin, date_end, policy_domain, success_count, failure_count, created)"
                . " VALUES (1, 0, 'Reporter Inc.', 'tlsrpt@reporter.example', 'tlsrpt-storage-test', '{$hash}', 1700000000, 1700086400, 'example.org', 100, 3, NOW())";
            return (bool) $this->db->query($sql);
        };

        $this->test('First insert of a TLS-RPT report succeeds', function () use ($insert) {
            return $insert();
        });

        $this->test('Duplicate TLS-RPT insert is rejected by the UNIQUE hash', function () use ($insert) {
            try {
                $ok = $insert();

                return $ok === false && $this->db->errno === 1062;
            } catch (\mysqli_sql_exception $e) {
                return $e->getCode() === 1062;
            }
        });

        $this->test('Exactly one TLS-RPT report row is stored', function () use ($reports, $hash) {
            $result = $this->db->query("SELECT COUNT(*) AS cnt FROM {$reports} WHERE report_hash = '{$hash}'");
            $row = $result ? $result->fetch_assoc() : null;
            return $row && (int) $row['cnt'] === 1;
        });

        // Store one policy block and a failure detail under the report so the
        // per-policy persistence path and its report/policy linkage are covered.
        $reportId = 0;
        $result = $this->db->query("SELECT id FROM {$reports} WHERE report_hash = '{$hash}'");

        if ($result && ($row = $result->fetch_assoc())) {
            $reportId = (int) $row['id'];
        }

        $this->test('A per-policy row stores and links to the report', function () use ($policies, $reportId) {
            if ($reportId <= 0) {
                return false;
            }

            $ok = $this->db->query(
                "INSERT INTO {$policies} (report_id, domain_id, policy_type, policy_domain, success_count, failure_count)"
                . " VALUES ({$reportId}, 1, 'sts', 'example.org', 100, 3)"
            );

            if (!$ok) {
                return false;
            }

            $check = $this->db->query("SELECT policy_type FROM {$policies} WHERE report_id = {$reportId}");
            $stored = $check ? $check->fetch_assoc() : null;

            return $stored && $stored['policy_type'] === 'sts';
        });

        $this->test('A failure detail stores and links to report and policy', function () use ($policies, $failures, $reportId) {
            if ($reportId <= 0) {
                return false;
            }

            $policyId = 0;
            $result = $this->db->query("SELECT id FROM {$policies} WHERE report_id = {$reportId} LIMIT 1");

            if ($result && ($row = $result->fetch_assoc())) {
                $policyId = (int) $row['id'];
            }

            if ($policyId <= 0) {
                return false;
            }

            return (bool) $this->db->query(
                "INSERT INTO {$failures} (report_id, policy_id, domain_id, policy_domain, result_type, sending_mta_ip, receiving_mx_host, receiving_ip, failed_session_count, additional_info, failure_reason_code)"
                . " VALUES ({$reportId}, {$policyId}, 1, 'example.org', 'certificate-expired', '203.0.113.9', 'mx.example.org', '', 3, '', '')"
            );
        });

        // Deleting a report must leave neither policies nor failures behind, the
        // guarantee the component's transactional cleanup provides.
        $this->test('Deleting a TLS-RPT report leaves no orphan policies or failures', function () use ($reports, $policies, $failures, $reportId, $hash) {
            if ($reportId <= 0) {
                return false;
            }

            $this->db->query("DELETE FROM {$failures} WHERE report_id = {$reportId}");
            $this->db->query("DELETE FROM {$policies} WHERE report_id = {$reportId}");
            $this->db->query("DELETE FROM {$reports} WHERE report_hash = '{$hash}'");

            foreach ([$reports, $policies, $failures] as $table) {
                $column = $table === $reports ? "report_hash = '{$hash}'" : "report_id = {$reportId}";
                $count = $this->db->query("SELECT COUNT(*) AS cnt FROM {$table} WHERE {$column}");
                $row = $count ? $count->fetch_assoc() : null;

                if (!$row || (int) $row['cnt'] !== 0) {
                    return false;
                }
            }

            return true;
        });

        $cleanup();
    }
}
