<?php
/**
 * Metrics tests: drive the shipped Metrics aggregator against a real database.
 * The suite seeds one monitored domain with reports spanning two non-overlapping
 * rolling windows (and an older report outside both), from more than one
 * reporter, then asserts the lifetime totals, the volume-weighted window pass
 * rates, newly seen sources and the chronological timeline the dashboard and the
 * Action Center rely on. It uses the component's own Metrics class with a Joomla
 * database driver, so the SQL the dashboard runs is what is exercised here.
 */

define('_JEXEC', 1);

require_once '/var/www/html/libraries/vendor/autoload.php';
require_once '/var/www/html/administrator/components/com_dmarcreports/src/Helper/Metrics.php';

use Advans\Component\DmarcReports\Administrator\Helper\Metrics;
use Joomla\Database\DatabaseFactory;

class MetricsTest
{
    /** @var \Joomla\Database\DatabaseInterface */
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $domainId = 0;

    private const DOMAIN = 'metrics-suite.test';
    private const ANCHOR = 1700000000; // Fixed reference end timestamp.
    private const DAY    = 86400;

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config    = new JConfig();
        $factory   = new DatabaseFactory();
        $this->db  = $factory->getDriver('mysqli', [
            'host'     => $config->host,
            'user'     => $config->user,
            'password' => $config->password,
            'database' => $config->db,
            'prefix'   => $config->dbprefix,
        ]);
    }

    public function run(): bool
    {
        echo "=== Metrics Tests ===\n\n";

        try {
            $this->seed();
        } catch (\Throwable $e) {
            echo "✗ Failed to seed fixtures - {$e->getMessage()}\n";
            $this->cleanup();

            return false;
        }

        $metrics = new Metrics($this->db);
        $bundle  = $metrics->domainMetrics($this->domainId);

        $this->test('Lifetime volume sums every stored record', function () use ($bundle) {
            return (int) $bundle['total'] === 600;
        });

        $this->test('Lifetime DMARC pass counts aligned mail only', function () use ($bundle) {
            return (int) $bundle['dmarc_pass'] === 480;
        });

        $this->test('Lifetime pass rate is volume weighted', function () use ($bundle) {
            return abs((float) $bundle['pass_rate'] - 80.0) < 0.01;
        });

        $this->test('Current rolling window rate covers only the newest window', function () use ($bundle) {
            return $bundle['pass_rate_window'] !== null
                && abs((float) $bundle['pass_rate_window'] - 93.3) < 0.01;
        });

        $this->test('Previous rolling window rate is non-overlapping', function () use ($bundle) {
            return $bundle['pass_rate_prev'] !== null
                && abs((float) $bundle['pass_rate_prev'] - 50.0) < 0.01;
        });

        $this->test('Current policy is read from the newest report', function () use ($bundle) {
            return $bundle['current_policy'] === 'quarantine';
        });

        $this->test('Current policy percentage is read from the newest report', function () use ($bundle) {
            return (int) $bundle['current_policy_pct'] === 100;
        });

        $this->test('New sources list every source first seen in the current window', function () use ($bundle) {
            $ips = array_map(static fn (array $s): string => $s['ip'], $bundle['new_sources']);

            return in_array('10.0.0.3', $ips, true)
                && in_array('10.0.0.4', $ips, true)
                && !in_array('10.0.0.1', $ips, true);
        });

        $this->test('Unaligned senders surface a current-window failing source only', function () use ($bundle) {
            $ips = array_map(static fn (array $s): string => $s['ip'], $bundle['unaligned_sources']);

            // 10.0.0.3 fails alignment inside the current rolling window and must
            // surface; 10.0.0.2 failed only in the previous window, so once a
            // sender is fixed it drops out of the window-scoped Action Center.
            return in_array('10.0.0.3', $ips, true)
                && !in_array('10.0.0.2', $ips, true);
        });

        $this->test('Timeline is chronological and spans every report day', function () use ($bundle) {
            $days = array_map(static fn (array $t): string => $t['day'], $bundle['timeline']);

            if (count($days) !== 4) {
                return false;
            }

            $sorted = $days;
            sort($sorted);

            return $days === $sorted;
        });

        $this->test('Domain summary row reflects the seeded totals', function () use ($metrics) {
            foreach ($metrics->domainSummaries() as $row) {
                if (($row['domain'] ?? '') === self::DOMAIN) {
                    return (int) $row['total'] === 600 && abs((float) $row['pass_rate'] - 80.0) < 0.01;
                }
            }

            return false;
        });

        // The dashboard Action Center renders from actionMetricsForAll(), the
        // bulk sibling of domainMetrics() that resolves every domain's rolling
        // windows, new sources and unaligned senders in a fixed set of grouped
        // queries. Its derived-table and window-function SQL differs from the
        // per-domain path, so it is exercised here for parity against the same
        // seed rather than trusting that the detail-view tests cover it.
        $all    = $metrics->actionMetricsForAll($metrics->domainSummaries());
        $action = $all[$this->domainId] ?? [];

        $this->test('Bulk action metrics expose the seeded domain', function () use ($action) {
            return $action !== [] && (int) ($action['domain_id'] ?? 0) === $this->domainId;
        });

        $this->test('Bulk lifetime totals match the detail view', function () use ($action, $bundle) {
            return (int) ($action['total'] ?? -1) === (int) $bundle['total']
                && (int) ($action['dmarc_pass'] ?? -1) === (int) $bundle['dmarc_pass']
                && abs((float) ($action['pass_rate'] ?? -1) - (float) $bundle['pass_rate']) < 0.01;
        });

        $this->test('Bulk current window matches the detail view', function () use ($action, $bundle) {
            return (int) ($action['window_total'] ?? -1) === (int) $bundle['window_total']
                && (int) ($action['window_reject_under_p'] ?? -1) === (int) $bundle['window_reject_under_p']
                && $action['pass_rate_window'] !== null
                && abs((float) $action['pass_rate_window'] - (float) $bundle['pass_rate_window']) < 0.01;
        });

        $this->test('Bulk previous window matches the detail view', function () use ($action, $bundle) {
            return $action['pass_rate_prev'] !== null
                && $bundle['pass_rate_prev'] !== null
                && abs((float) $action['pass_rate_prev'] - (float) $bundle['pass_rate_prev']) < 0.01;
        });

        $this->test('Bulk new sources match the current-window sources', function () use ($action) {
            $ips = array_map(static fn (array $s): string => $s['ip'], $action['new_sources'] ?? []);

            return in_array('10.0.0.3', $ips, true)
                && in_array('10.0.0.4', $ips, true)
                && !in_array('10.0.0.1', $ips, true);
        });

        $this->test('Bulk unaligned senders surface a current-window failing source only', function () use ($action) {
            $ips = array_map(static fn (array $s): string => $s['ip'], $action['unaligned_sources'] ?? []);

            return in_array('10.0.0.3', $ips, true)
                && !in_array('10.0.0.2', $ips, true);
        });

        $this->test('Subdomain inherits the organizational parent sp policy', function () {
            // A monitored subdomain whose only report was published at the
            // organizational parent (policy_domain = parent) with p=none but
            // sp=reject must report reject as the effective current policy, both
            // in the detail view and the bulk dashboard path.
            $subId = $this->seedSubdomain('sub.' . self::DOMAIN, self::DOMAIN, 'none', 'reject');

            try {
                $metrics = new Metrics($this->db);
                $detail  = $metrics->domainMetrics($subId);

                $bulk = [];
                foreach ($metrics->domainSummaries() as $row) {
                    if ((int) ($row['id'] ?? 0) === $subId) {
                        $bulk = $row;
                        break;
                    }
                }

                return ($detail['current_policy'] ?? '') === 'reject'
                    && ($bulk['current_policy'] ?? '') === 'reject';
            } finally {
                $this->deleteDomain($subId);
            }
        });

        $this->cleanup();

        echo "\n=== Metrics Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    private function seed(): void
    {
        $this->cleanup();

        $domain = (object) [
            'domain'          => self::DOMAIN,
            'title'           => 'Metrics Suite',
            'expected_policy' => 'reject',
            'published'       => 1,
            'created'         => gmdate('Y-m-d H:i:s'),
        ];
        $this->db->insertObject('#__dmarc_domains', $domain, 'id');
        $this->domainId = (int) $domain->id;

        // Four reports: one outside both rolling windows, one in the previous
        // window, and two contemporaneous reports in the current window from
        // different reporters. The second current-window report proves that a
        // new source is detected across the whole window, not just the single
        // newest report id.
        $old  = $this->insertReport('historic', self::ANCHOR - 30 * self::DAY, 'none');
        $prev = $this->insertReport('orgB', self::ANCHOR - 10 * self::DAY, 'none');
        $curA = $this->insertReport('orgA', self::ANCHOR - 2 * self::DAY, 'quarantine', self::ANCHOR);
        $curB = $this->insertReport('orgC', self::ANCHOR - 3 * self::DAY, 'none');

        // 10.0.0.1 is an established, aligned sender present in every window.
        $this->insertRecord($old, '10.0.0.1', 100, 1);
        $this->insertRecord($prev, '10.0.0.1', 100, 1);
        $this->insertRecord($prev, '10.0.0.2', 100, 0);
        $this->insertRecord($curA, '10.0.0.1', 180, 1);
        // 10.0.0.3 appears only in the newest report: a genuinely new source.
        $this->insertRecord($curA, '10.0.0.3', 20, 0);
        // 10.0.0.4 appears only in a second, contemporaneous report from another
        // reporter: still a new source for the current window.
        $this->insertRecord($curB, '10.0.0.4', 100, 1);
    }

    private function insertReport(string $org, int $begin, string $policy, ?int $end = null): int
    {
        $report = (object) [
            'domain_id'     => $this->domainId,
            'source_id'     => 0,
            'org_name'      => $org,
            'org_email'     => 'reports@' . $org . '.test',
            'report_id'     => $org . '-' . $begin,
            'report_hash'   => hash('sha256', self::DOMAIN . '|' . $org . '|' . $begin),
            'date_begin'    => $begin,
            'date_end'      => $end ?? ($begin + self::DAY),
            'policy_domain' => self::DOMAIN,
            'policy_p'      => $policy,
            'policy_pct'    => 100,
            'message_count' => 0,
            'created'       => gmdate('Y-m-d H:i:s'),
        ];
        $this->db->insertObject('#__dmarc_reports', $report, 'id');

        return (int) $report->id;
    }

    private function insertRecord(int $reportId, string $ip, int $count, int $dkimAligned): void
    {
        $record = (object) [
            'report_id'    => $reportId,
            'domain_id'    => $this->domainId,
            'source_ip'    => $ip,
            'count'        => $count,
            'disposition'  => 'none',
            'dkim_result'  => $dkimAligned === 1 ? 'pass' : 'fail',
            'spf_result'   => 'fail',
            'dkim_aligned' => $dkimAligned,
            'spf_aligned'  => 0,
            'header_from'  => self::DOMAIN,
        ];
        $this->db->insertObject('#__dmarc_records', $record);
    }

    private function seedSubdomain(string $domain, string $parent, string $p, string $sp): int
    {
        $row = (object) [
            'domain'          => $domain,
            'title'           => 'Subdomain',
            'expected_policy' => 'reject',
            'published'       => 1,
            'created'         => gmdate('Y-m-d H:i:s'),
        ];
        $this->db->insertObject('#__dmarc_domains', $row, 'id');
        $domainId = (int) $row->id;

        $report = (object) [
            'domain_id'     => $domainId,
            'source_id'     => 0,
            'org_name'      => 'orgSP',
            'org_email'     => 'reports@orgsp.test',
            'report_id'     => 'sp-' . $domainId,
            'report_hash'   => hash('sha256', $domain . '|sp|' . $domainId),
            'date_begin'    => self::ANCHOR - self::DAY,
            'date_end'      => self::ANCHOR,
            'policy_domain' => $parent,
            'policy_p'      => $p,
            'policy_sp'     => $sp,
            'policy_pct'    => 100,
            'message_count' => 0,
            'created'       => gmdate('Y-m-d H:i:s'),
        ];
        $this->db->insertObject('#__dmarc_reports', $report, 'id');

        $record = (object) [
            'report_id'    => (int) $report->id,
            'domain_id'    => $domainId,
            'source_ip'    => '10.0.1.1',
            'count'        => 10,
            'disposition'  => 'none',
            'dkim_result'  => 'pass',
            'spf_result'   => 'fail',
            'dkim_aligned' => 1,
            'spf_aligned'  => 0,
            'header_from'  => $domain,
        ];
        $this->db->insertObject('#__dmarc_records', $record);

        return $domainId;
    }

    private function deleteDomain(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        foreach (['#__dmarc_records', '#__dmarc_reports', '#__dmarc_domains'] as $table) {
            $column = $table === '#__dmarc_domains' ? 'id' : 'domain_id';
            $query  = $this->query()
                ->delete($this->db->quoteName($table))
                ->where($this->db->quoteName($column) . ' = ' . (int) $id);
            $this->db->setQuery($query)->execute();
        }
    }

    private function cleanup(): void
    {
        $id = $this->domainId;

        if ($id === 0) {
            $query = $this->query()
                ->select($this->db->quoteName('id'))
                ->from($this->db->quoteName('#__dmarc_domains'))
                ->where($this->db->quoteName('domain') . ' = ' . $this->db->quote(self::DOMAIN));
            $id = (int) $this->db->setQuery($query)->loadResult();
        }

        if ($id === 0) {
            return;
        }

        foreach (['#__dmarc_records', '#__dmarc_reports', '#__dmarc_domains'] as $table) {
            $column = $table === '#__dmarc_domains' ? 'id' : 'domain_id';
            $query  = $this->query()
                ->delete($this->db->quoteName($table))
                ->where($this->db->quoteName($column) . ' = ' . (int) $id);
            $this->db->setQuery($query)->execute();
        }

        $this->domainId = 0;
    }

    /**
     * A new query builder via the forward-compatible factory. Joomla 6 replaces
     * the deprecated getQuery(true) with createQuery(); the guard keeps the
     * suite green on both Joomla 5 and 6.
     */
    private function query()
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) {
                echo "✓ {$name}\n";
                $this->passed++;
            } else {
                echo "✗ {$name}\n";
                $this->failed++;
            }
        } catch (\Throwable $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new MetricsTest();
exit($test->run() ? 0 : 1);
