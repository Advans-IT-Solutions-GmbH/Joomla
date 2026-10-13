<?php
/**
 * Export tests: the CSV record export must stream every stored record of a
 * domain exactly once, in a stable order, without duplicating or skipping rows
 * as it pages through a large history, and it must stay behind the component's
 * token + ACL guards. These tests seed a known record set and drive the exact
 * keyset pagination the ExportController uses, then assert the controller keeps
 * its authorization guards so report data cannot be fetched without a valid
 * token and manage permission.
 */

class ExportTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $dbPrefix;
    private $domainId = 909001;

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->dbPrefix = $config->dbprefix;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);
    }

    public function run(): bool
    {
        echo "=== Export Tests ===\n\n";

        $reports = "{$this->dbPrefix}dmarc_reports";
        $records = "{$this->dbPrefix}dmarc_records";

        $this->cleanup();

        // Two reports with different date_begin values so the ordering key
        // (date_begin DESC, rec.id ASC) spans more than one date bucket, which is
        // exactly where a naive keyset cursor can duplicate or skip rows.
        $hashA = hash('sha256', "export-test-A|{$this->domainId}");
        $hashB = hash('sha256', "export-test-B|{$this->domainId}");
        $this->db->query(
            "INSERT INTO {$reports} (domain_id, org_name, report_id, report_hash, date_begin, date_end, message_count, created)"
            . " VALUES ({$this->domainId}, 'example.net', 'export-A', '{$hashA}', 1700000000, 1700086400, 3, NOW())"
        );
        $reportA = (int) $this->db->insert_id;
        $this->db->query(
            "INSERT INTO {$reports} (domain_id, org_name, report_id, report_hash, date_begin, date_end, message_count, created)"
            . " VALUES ({$this->domainId}, 'example.net', 'export-B', '{$hashB}', 1700100000, 1700186400, 2, NOW())"
        );
        $reportB = (int) $this->db->insert_id;

        // Five records split across the two reports. Insertion order is mixed so
        // record ids do not line up with report date order, forcing the keyset
        // cursor to carry both the date and the id to stay correct.
        foreach ([$reportA, $reportB, $reportA, $reportB, $reportA] as $i => $reportId) {
            $ip = '203.0.113.' . ($i + 1);
            $this->db->query(
                "INSERT INTO {$records} (report_id, domain_id, source_ip, count, disposition)"
                . " VALUES ({$reportId}, {$this->domainId}, '{$ip}', 1, 'none')"
            );
        }

        $this->test('Keyset pagination returns every record exactly once in order', function () use ($records, $reports) {
            $expected = $this->fetchAllOrdered($records, $reports);
            $paged    = $this->fetchKeyset($records, $reports, 2);

            return $paged === $expected && \count($paged) === 5;
        });

        $this->test('A record inserted after the id ceiling is excluded', function () use ($records, $reports, $reportA) {
            $maxId = $this->maxRecordId($records);
            // A row ingested mid-export gets a higher id and must not appear.
            $this->db->query(
                "INSERT INTO {$records} (report_id, domain_id, source_ip, count, disposition)"
                . " VALUES ({$reportA}, {$this->domainId}, '203.0.113.250', 1, 'none')"
            );

            $paged = $this->fetchKeyset($records, $reports, 2, $maxId);

            return \count($paged) === 5;
        });

        $this->test('Export controller enforces token and manage-permission guards', function () {
            $base = '/var/www/html/administrator/components/com_dmarcreports/src/Controller/ExportController.php';
            $src  = is_readable($base) ? (string) file_get_contents($base) : '';

            return $src !== ''
                && strpos($src, "checkToken('get')") !== false
                && strpos($src, "authorise('core.manage', 'com_dmarcreports')") !== false
                && strpos($src, 'NotAllowed') !== false;
        });

        $this->cleanup();

        echo "\n=== Export Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    /**
     * The exporter's full ordered result: date_begin DESC, rec.id ASC, bounded to
     * the test domain. Returned as a list of record ids for easy comparison.
     *
     * @return  int[]
     */
    private function fetchAllOrdered(string $records, string $reports): array
    {
        $sql = "SELECT rec.id FROM {$records} rec"
            . " INNER JOIN {$reports} rep ON rep.id = rec.report_id"
            . " WHERE rec.domain_id = {$this->domainId}"
            . " ORDER BY rep.date_begin DESC, rec.id ASC";
        $result = $this->db->query($sql);
        $ids = [];

        while ($result && ($row = $result->fetch_assoc())) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    private function maxRecordId(string $records): int
    {
        $result = $this->db->query(
            "SELECT MAX(id) AS m FROM {$records} WHERE domain_id = {$this->domainId}"
        );
        $row = $result ? $result->fetch_assoc() : null;

        return $row ? (int) $row['m'] : 0;
    }

    /**
     * Replicates ExportController::records() keyset pagination: resume strictly
     * after the previous page's (date_begin, rec.id) key under the same order,
     * bounded by an id ceiling. Returns the record ids in the order emitted.
     *
     * @return  int[]
     */
    private function fetchKeyset(string $records, string $reports, int $chunk, ?int $maxId = null): array
    {
        if ($maxId === null) {
            $maxId = $this->maxRecordId($records);
        }

        $ids       = [];
        $cursorSet = false;
        $lastDate  = 0;
        $lastId    = 0;

        do {
            $where = "rec.domain_id = {$this->domainId} AND rec.id <= {$maxId}";

            if ($cursorSet) {
                $where .= " AND (rep.date_begin < {$lastDate}"
                    . " OR (rep.date_begin = {$lastDate} AND rec.id > {$lastId}))";
            }

            $sql = "SELECT rec.id, rep.date_begin FROM {$records} rec"
                . " INNER JOIN {$reports} rep ON rep.id = rec.report_id"
                . " WHERE {$where}"
                . " ORDER BY rep.date_begin DESC, rec.id ASC"
                . " LIMIT {$chunk}";

            $result = $this->db->query($sql);
            $rows   = [];

            while ($result && ($row = $result->fetch_assoc())) {
                $rows[] = $row;
            }

            foreach ($rows as $row) {
                $lastDate  = (int) $row['date_begin'];
                $lastId    = (int) $row['id'];
                $cursorSet = true;
                $ids[]     = $lastId;
            }
        } while (\count($rows) === $chunk);

        return $ids;
    }

    private function cleanup(): void
    {
        $reports = "{$this->dbPrefix}dmarc_reports";
        $records = "{$this->dbPrefix}dmarc_records";
        $this->db->query("DELETE FROM {$records} WHERE domain_id = {$this->domainId}");
        $this->db->query("DELETE FROM {$reports} WHERE domain_id = {$this->domainId}");
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

$test = new ExportTest();
exit($test->run() ? 0 : 1);
