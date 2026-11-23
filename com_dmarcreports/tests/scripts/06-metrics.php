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
require_once '/var/www/html/administrator/components/com_dmarcreports/src/Helper/SourceClassifier.php';
require_once '/var/www/html/administrator/components/com_dmarcreports/src/Helper/FlaggedSourceLimit.php';
require_once '/var/www/html/administrator/components/com_dmarcreports/src/Helper/Metrics.php';
require_once '/var/www/html/administrator/components/com_dmarcreports/src/Helper/ActionStatus.php';

use Advans\Component\DmarcReports\Administrator\Helper\ActionStatus;
use Advans\Component\DmarcReports\Administrator\Helper\Metrics;
use Advans\Component\DmarcReports\Administrator\Helper\SourceClassifier;
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

        $this->test('Flagged sources surface a current-window delivered failing source only', function () use ($bundle) {
            $ips = array_map(static fn (array $s): string => $s['ip'], $bundle['flagged_sources']);

            // 10.0.0.3 fails alignment inside the current rolling window and its
            // mail was delivered, so it must surface; 10.0.0.2 failed only in the
            // previous window, so once a sender is fixed it drops out. The
            // DKIM-aligned senders 10.0.0.1 and 10.0.0.4 are confirmed.
            return $ips === ['10.0.0.3'];
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

        $this->test('Bulk flagged sources match the detail view', function () use ($action, $bundle) {
            return ($action['flagged_sources'] ?? null) === $bundle['flagged_sources'];
        });

        $this->runClassificationTests();

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

        $this->runTlsRptSummaryTests();

        $this->cleanup();

        $this->test('The suite leaves no rows behind', fn (): bool => $this->leftovers() === 0);

        echo "\n=== Metrics Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    /**
     * Exercise the TLS-RPT summary aggregation (RFC 8460) the Domain view and
     * dashboard render from. The suite that stores TLS-RPT reports proves the
     * rows land; here the shipped Metrics::tlsRptSummary() SQL is driven against
     * a real database on both Joomla 5 and 6, covering the three things the
     * dashboard depends on: report/session totals summed from the per-policy
     * rows, failures grouped by result type, receiving MX and sending MTA and
     * ordered by session volume, and per-policy-domain attribution when one
     * report bundles policies for several domains.
     */
    private function runTlsRptSummaryTests(): void
    {
        // A second monitored domain that appears only as a secondary policy
        // inside the primary domain's single report. RFC 8460 lets one report
        // bundle policies for several policy domains; the summary must attribute
        // each policy to its own domain, not to the report's owning domain.
        $secondaryId = $this->insertDomain('tls-secondary.' . self::DOMAIN);
        $reportId    = 0;

        try {
            $reportId = $this->insertTlsReport($this->domainId);

            // Two policies for the primary domain in the one report: totals must
            // sum both and count the report once (COUNT(DISTINCT report_id)).
            $this->insertTlsPolicy($reportId, $this->domainId, 100, 5);
            $this->insertTlsPolicy($reportId, $this->domainId, 50, 5);
            // One policy for the secondary domain, bundled in the same report.
            $this->insertTlsPolicy($reportId, $secondaryId, 7, 1);

            // Failures: two rows share a (result_type, MX, sending IP) triple and
            // must collapse into one group whose sessions sum; a third distinct
            // triple has fewer sessions and must sort after it.
            $this->insertTlsFailure($reportId, $this->domainId, 'certificate-expired', 'mx1.example', '192.0.2.10', 3);
            $this->insertTlsFailure($reportId, $this->domainId, 'certificate-expired', 'mx1.example', '192.0.2.10', 4);
            $this->insertTlsFailure($reportId, $this->domainId, 'starttls-not-supported', 'mx2.example', '192.0.2.20', 2);

            $metrics = new Metrics($this->db);
            $summary = $metrics->tlsRptSummary($this->domainId);

            $this->test('TLS-RPT summary sums sessions and counts the report once', function () use ($summary) {
                return (int) $summary['reports'] === 1
                    && (int) $summary['success'] === 150
                    && (int) $summary['failure'] === 10;
            });

            $this->test('TLS-RPT failures group by result/MX/IP and sort by session volume', function () use ($summary) {
                if (count($summary['failures']) !== 2) {
                    return false;
                }

                $top = $summary['failures'][0];

                return (int) $top['sessions'] === 7
                    && $top['result_type'] === 'certificate-expired'
                    && $top['receiving_mx_host'] === 'mx1.example'
                    && $top['sending_mta_ip'] === '192.0.2.10'
                    && (int) $summary['failures'][1]['sessions'] === 2;
            });

            $this->test('TLS-RPT policies attribute to their own domain, not the report owner', function () use ($metrics, $secondaryId) {
                $secondary = $metrics->tlsRptSummary($secondaryId);

                // The secondary domain sees only its bundled policy, and its
                // success total is excluded from the primary domain's 150.
                return (int) $secondary['reports'] === 1
                    && (int) $secondary['success'] === 7
                    && (int) $secondary['failure'] === 1
                    && $secondary['failures'] === [];
            });
        } finally {
            if ($reportId > 0) {
                foreach (['#__dmarc_tlsrpt_failures', '#__dmarc_tlsrpt_policies'] as $table) {
                    $query = $this->query()
                        ->delete($this->db->quoteName($table))
                        ->where($this->db->quoteName('report_id') . ' = ' . (int) $reportId);
                    $this->db->setQuery($query)->execute();
                }

                $query = $this->query()
                    ->delete($this->db->quoteName('#__dmarc_tlsrpt_reports'))
                    ->where($this->db->quoteName('id') . ' = ' . (int) $reportId);
                $this->db->setQuery($query)->execute();
            }

            $this->deleteDomain($secondaryId);
        }
    }

    private function insertDomain(string $domain): int
    {
        $row = (object) [
            'domain'          => $domain,
            'title'           => 'TLS-RPT Secondary',
            'expected_policy' => 'reject',
            'published'       => 1,
            'created'         => gmdate('Y-m-d H:i:s'),
        ];
        $this->db->insertObject('#__dmarc_domains', $row, 'id');

        return (int) $row->id;
    }

    private function insertTlsReport(int $domainId): int
    {
        $report = (object) [
            'domain_id'     => $domainId,
            'source_id'     => 0,
            'org_name'      => 'tls-reporter',
            'contact_info'  => 'tls@reporter.test',
            'report_id'     => 'tls-' . $domainId . '-' . self::ANCHOR,
            'report_hash'   => hash('sha256', 'tls|' . $domainId . '|' . self::ANCHOR),
            'date_begin'    => self::ANCHOR - self::DAY,
            'date_end'      => self::ANCHOR,
            'policy_domain' => self::DOMAIN,
            'success_count' => 0,
            'failure_count' => 0,
            'created'       => gmdate('Y-m-d H:i:s'),
        ];
        $this->db->insertObject('#__dmarc_tlsrpt_reports', $report, 'id');

        return (int) $report->id;
    }

    private function insertTlsPolicy(int $reportId, int $domainId, int $success, int $failure): void
    {
        $policy = (object) [
            'report_id'     => $reportId,
            'domain_id'     => $domainId,
            'policy_type'   => 'sts',
            'policy_domain' => self::DOMAIN,
            'success_count' => $success,
            'failure_count' => $failure,
        ];
        $this->db->insertObject('#__dmarc_tlsrpt_policies', $policy);
    }

    private function insertTlsFailure(int $reportId, int $domainId, string $resultType, string $mx, string $ip, int $sessions): void
    {
        $failure = (object) [
            'report_id'            => $reportId,
            'policy_id'            => 0,
            'domain_id'            => $domainId,
            'policy_domain'        => self::DOMAIN,
            'result_type'          => $resultType,
            'sending_mta_ip'       => $ip,
            'receiving_mx_host'    => $mx,
            'receiving_ip'         => '',
            'failed_session_count' => $sessions,
            'additional_info'      => '',
            'failure_reason_code'  => '',
        ];
        $this->db->insertObject('#__dmarc_tlsrpt_failures', $failure);
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

    /**
     * The automatic judgement of sending sources against a real database: the
     * per-source figures, the flagged list in both paths, the domain-view table
     * that always lists flagged sources, and the automatic closing of stored
     * "new source" items whose source is now confirmed through DKIM.
     */
    private function runClassificationTests(): void
    {
        $domain   = 'classify.' . self::DOMAIN;
        $domainId = $this->seedClassification($domain);

        try {
            $metrics = new Metrics($this->db);
            $bundle  = $metrics->domainMetrics($domainId);
            $rows    = [];

            foreach ($bundle['top_sources'] as $row) {
                $rows[$row['source_ip']] = $row;
            }

            $this->test('Flagged sources: SPF-only first, then delivered failing mail; DKIM and rejected mail are not flagged', function () use ($bundle) {
                return array_column($bundle['flagged_sources'], 'ip') === ['10.0.3.2', '10.0.3.5', '10.0.3.4'];
            });

            $this->test('Flagged source figures split DKIM, SPF-only and failing mail', function () use ($bundle) {
                $mixed = $bundle['flagged_sources'][1];

                return $mixed['total'] === 7 && $mixed['dkim'] === 5 && $mixed['spf_only'] === 2
                    && $mixed['fail'] === 0 && $mixed['fail_delivered'] === 0
                    && $mixed['last_seen'] === self::ANCHOR;
            });

            $this->test('Bulk flagged sources match the detail view for the classified domain', function () use ($metrics, $bundle, $domainId) {
                foreach ($metrics->domainSummaries() as $row) {
                    if ((int) ($row['id'] ?? 0) === $domainId) {
                        return ($metrics->actionMetricsForAll([$row])[$domainId]['flagged_sources'] ?? null) === $bundle['flagged_sources'];
                    }
                }

                return false;
            });

            $this->test('Source table lists the 20 largest sources plus every flagged one', function () use ($rows) {
                return count($rows) === 23
                    && isset($rows['10.0.3.2'], $rows['10.0.3.4'], $rows['10.0.3.5'])
                    && !isset($rows['10.0.3.1'], $rows['10.0.3.3'], $rows['10.0.3.6']);
            });

            $this->test('Source table rows carry their classification', function () use ($rows) {
                return $rows['10.0.4.1']['class'] === SourceClassifier::DKIM
                    && $rows['10.0.3.2']['class'] === SourceClassifier::SPF_ONLY
                    && $rows['10.0.3.4']['class'] === SourceClassifier::UNAUTHENTICATED
                    && $rows['10.0.3.5']['class'] === SourceClassifier::SPF_ONLY;
            });

            $this->test('Source rows expose complete DMARC evidence for the reporting window', function () use ($rows, $domain) {
                $row = $rows['10.0.3.2'] ?? [];

                foreach ([
                    'source_ip', 'policy_domain', 'policy_adkim', 'policy_aspf', 'header_from',
                    'dkim_pass_aligned', 'spf_pass_aligned',
                    'disposition_none', 'disposition_quarantine', 'disposition_reject',
                    'total', 'last_seen',
                ] as $key) {
                    if (!array_key_exists($key, $row)) {
                        return false;
                    }
                }

                return ($row['source_ip'] ?? '') === '10.0.3.2'
                    && (int) ($row['total'] ?? 0) === 7
                    && (int) ($row['spf_pass_aligned'] ?? 0) === 7
                    && (int) ($row['dkim_pass_aligned'] ?? 0) === 0
                    && (int) ($row['disposition_none'] ?? 0) === 7
                    && ($row['policy_domain'] ?? '') === $domain
                    && ($row['header_from'] ?? '') === $domain
                    && ($row['policy_adkim'] ?? '') !== ''
                    && ($row['policy_aspf'] ?? '') !== '';
            });

            $this->test('Classification judges the current window, else the whole history', function () use ($metrics, $domainId) {
                $classes = $metrics->classifySources($domainId, ['10.0.3.1', '10.0.3.3', '10.0.3.6', '10.0.3.7', '10.0.9.9']);

                ksort($classes);

                // 10.0.3.7 sent SPF-only mail long ago and DKIM-signed mail now;
                // 10.0.3.6 sent only long ago; 10.0.9.9 never sent and is absent.
                return $classes === [
                    '10.0.3.1' => SourceClassifier::DKIM,
                    '10.0.3.3' => SourceClassifier::REJECTED,
                    '10.0.3.6' => SourceClassifier::SPF_ONLY,
                    '10.0.3.7' => SourceClassifier::DKIM,
                ];
            });

            $this->test('Bulk classification matches detail classification and remains scoped by domain', function () use ($metrics, $domainId) {
                $ips = ['10.0.3.1', '10.0.3.3', '10.0.3.6', '10.0.3.7'];
                $single = $metrics->classifySources($domainId, $ips);
                $bulk = $metrics->classifySourcesAll([$domainId => $ips]);
                $bulkDomain = (array) ($bulk[$domainId] ?? []);
                ksort($single);
                ksort($bulkDomain);

                return $bulkDomain === $single;
            });

            $this->testMixedPolicyContext();
            $this->runManyFlaggedSourcesTest();
            $this->runHandledSourceSelectionTest();

            $this->test('Stored new-source items of DKIM-confirmed sources close automatically', function () use ($metrics, $domainId) {
                $this->insertAction($domainId, 'new_source:' . $domainId . ':10.0.3.1', 'snoozed');
                $this->insertAction($domainId, 'new_source:' . $domainId . ':10.0.3.2', 'snoozed');
                $this->insertAction($domainId, 'new_source:' . $domainId . ':10.0.3.7', 'snoozed');
                $raceKey = 'new_source:' . $domainId . ':10.0.3.1';

                $status   = new ActionStatus($this->db);
                $resolved = $status->resolveConfirmedNewSources(
                    function (int $id, array $ips) use ($metrics, $domainId, $raceKey): array {
                        $update = $this->query()
                            ->update($this->db->quoteName('#__dmarc_actions'))
                            ->set($this->db->quoteName('status') . ' = ' . $this->db->quote('ignored'))
                            ->set($this->db->quoteName('modified_by') . ' = 7')
                            ->where($this->db->quoteName('domain_id') . ' = ' . (int) $domainId)
                            ->where($this->db->quoteName('action_key') . ' = ' . $this->db->quote($raceKey))
                            ->where($this->db->quoteName('status') . ' IN (' . $this->db->quote('open') . ', ' . $this->db->quote('snoozed') . ')');
                        $this->db->setQuery($update)->execute();

                        return $metrics->classifySources($id, $ips);
                    }
                );

                $states = [];
                $query  = $this->query()
                    ->select([$this->db->quoteName('action_key'), $this->db->quoteName('status'), $this->db->quoteName('modified_by')])
                    ->from($this->db->quoteName('#__dmarc_actions'))
                    ->where($this->db->quoteName('domain_id') . ' = ' . (int) $domainId);

                foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
                    $states[$row->action_key] = $row->status . '/' . $row->modified_by;
                }

                $auto = array_column($status->autoResolved(), 'action_key');

                return $resolved === 1
                    // Status changed concurrently while classification ran:
                    // the conditional update leaves this administrator decision intact.
                    && $states['new_source:' . $domainId . ':10.0.3.1'] === 'ignored/7'
                    // SPF only: stays, the new SPF-only item takes over.
                    && $states['new_source:' . $domainId . ':10.0.3.2'] === 'snoozed/7'
                    // A separate source remains DKIM-confirmed and closes normally.
                    && $states['new_source:' . $domainId . ':10.0.3.7'] === 'auto_done/0'
                    && in_array('new_source:' . $domainId . ':10.0.3.7', $auto, true);
            });
        } finally {
            $this->deleteDomain($domainId);
        }
    }

    /**
     * Mixed aggregate-report policy contexts must never be represented by an
     * arbitrary MAX() policy tuple next to counts summed across those reports.
     */
    private function testMixedPolicyContext(): void
    {
        $domain = 'mixed-context.' . self::DOMAIN;
        $domainId = $this->seedSubdomain($domain, self::DOMAIN, 'none', 'reject');

        try {
            $update = $this->query()
                ->update($this->db->quoteName('#__dmarc_reports'))
                ->set($this->db->quoteName('policy_adkim') . ' = ' . $this->db->quote('r'))
                ->set($this->db->quoteName('policy_aspf') . ' = ' . $this->db->quote('r'))
                ->where($this->db->quoteName('domain_id') . ' = ' . $domainId);
            $this->db->setQuery($update)->execute();

            $report = (object) [
                'domain_id' => $domainId,
                'source_id' => 0,
                'org_name' => 'mixed-context-reporter',
                'org_email' => 'reports@mixed-context-reporter.test',
                'report_id' => 'mixed-context-' . $domainId,
                'report_hash' => hash('sha256', $domain . '|mixed-context'),
                'date_begin' => self::ANCHOR - self::DAY,
                'date_end' => self::ANCHOR,
                'policy_domain' => $domain,
                'policy_adkim' => 's',
                'policy_aspf' => 's',
                'policy_p' => 'none',
                'policy_pct' => 100,
                'message_count' => 0,
                'created' => gmdate('Y-m-d H:i:s'),
            ];
            $this->db->insertObject('#__dmarc_reports', $report, 'id');

            $record = (object) [
                'report_id' => (int) $report->id,
                'domain_id' => $domainId,
                'source_ip' => '10.0.1.1',
                'count' => 5,
                'disposition' => 'none',
                'dkim_result' => 'fail',
                'spf_result' => 'pass',
                'dkim_aligned' => 0,
                'spf_aligned' => 1,
                'header_from' => $domain,
            ];
            $this->db->insertObject('#__dmarc_records', $record);

            $metrics = new Metrics($this->db);
            $detail = $metrics->domainMetrics($domainId);
            $detailSource = null;

            foreach ($detail['top_sources'] as $source) {
                if ($source['source_ip'] === '10.0.1.1') {
                    $detailSource = $source;
                    break;
                }
            }

            $summary = null;

            foreach ($metrics->domainSummaries() as $row) {
                if ((int) ($row['id'] ?? 0) === $domainId) {
                    $summary = $row;
                    break;
                }
            }

            $bulk = $summary === null ? [] : ($metrics->actionMetricsForAll([$summary])[$domainId]['flagged_sources'] ?? []);
            $bulkSource = $bulk[0] ?? [];

            $this->test('Detail evidence marks mixed DMARC policy contexts instead of choosing one tuple', function () use ($detailSource) {
                return \is_array($detailSource)
                    && !empty($detailSource['policy_context_mixed'])
                    && $detailSource['policy_domain'] === ''
                    && $detailSource['policy_adkim'] === ''
                    && $detailSource['policy_aspf'] === ''
                    && $detailSource['header_from'] === ''
                    && (int) $detailSource['dkim_pass_aligned'] === 10
                    && (int) $detailSource['spf_pass_aligned'] === 5;
            });

            $this->test('Bulk action evidence marks the same mixed policy context', function () use ($bulkSource) {
                return \is_array($bulkSource)
                    && $bulkSource['ip'] === '10.0.1.1'
                    && !empty($bulkSource['policy_context_mixed'])
                    && $bulkSource['policy_domain'] === ''
                    && $bulkSource['policy_adkim'] === ''
                    && $bulkSource['policy_aspf'] === ''
                    && $bulkSource['header_from'] === '';
            });
        } finally {
            $this->deleteDomain($domainId);
        }
    }

    /**
     * A domain whose current window holds one source of every class, a mixed
     * source, twenty large DKIM-signed sources (so the small flagged ones fall
     * below the top 20 by volume) and, in an old report, an SPF-only source and a
     * source that has since switched to DKIM.
     */
    private function seedClassification(string $domain): int
    {
        $domainId = $this->insertDomain($domain);

        $report = function (string $org, int $begin, int $end, string $policy) use ($domainId, $domain): int {
            $row = (object) [
                'domain_id'     => $domainId,
                'source_id'     => 0,
                'org_name'      => $org,
                'org_email'     => 'reports@' . $org . '.test',
                'report_id'     => $org . '-' . $domainId,
                'report_hash'   => hash('sha256', $domain . '|' . $org . '|' . $begin),
                'date_begin'    => $begin,
                'date_end'      => $end,
                'policy_domain' => $domain,
                'policy_p'      => $policy,
                'policy_pct'    => 100,
                'message_count' => 0,
                'created'       => gmdate('Y-m-d H:i:s'),
            ];
            $this->db->insertObject('#__dmarc_reports', $row, 'id');

            return (int) $row->id;
        };

        $record = function (int $reportId, string $ip, int $count, int $dkim, int $spf, string $disposition) use ($domainId, $domain): void {
            $row = (object) [
                'report_id'    => $reportId,
                'domain_id'    => $domainId,
                'source_ip'    => $ip,
                'count'        => $count,
                'disposition'  => $disposition,
                'dkim_result'  => $dkim === 1 ? 'pass' : 'fail',
                'spf_result'   => $spf === 1 ? 'pass' : 'fail',
                'dkim_aligned' => $dkim,
                'spf_aligned'  => $spf,
                'header_from'  => $domain,
            ];
            $this->db->insertObject('#__dmarc_records', $row);
        };

        $old = $report('cls-old', self::ANCHOR - 30 * self::DAY, self::ANCHOR - 29 * self::DAY, 'none');
        $cur = $report('cls-cur', self::ANCHOR - self::DAY, self::ANCHOR, 'reject');

        $record($old, '10.0.3.6', 3, 0, 1, 'none');      // SPF only, long ago
        $record($old, '10.0.3.7', 4, 0, 1, 'none');      // SPF only long ago ...
        $record($cur, '10.0.3.7', 4, 1, 1, 'none');      // ... DKIM-signed now

        $record($cur, '10.0.3.1', 12, 1, 1, 'none');     // DKIM and SPF aligned
        $record($cur, '10.0.3.2', 7, 0, 1, 'none');      // SPF only
        $record($cur, '10.0.3.3', 9, 0, 0, 'reject');    // failing, rejected
        $record($cur, '10.0.3.4', 4, 0, 0, 'none');      // failing, delivered
        $record($cur, '10.0.3.5', 5, 1, 1, 'none');      // mixed: DKIM ...
        $record($cur, '10.0.3.5', 2, 0, 1, 'none');      // ... and SPF only

        for ($i = 1; $i <= 20; $i++) {
            $record($cur, '10.0.4.' . $i, 100, 1, 1, 'none');
        }

        return $domainId;
    }

    private function runManyFlaggedSourcesTest(): void
    {
        $domainId = $this->insertDomain('many-flags.' . self::DOMAIN);

        try {
            $report = (object) [
                'domain_id'     => $domainId,
                'source_id'     => 0,
                'org_name'      => 'many-flags',
                'org_email'     => 'reports@many-flags.test',
                'report_id'     => 'many-flags-' . $domainId,
                'report_hash'   => hash('sha256', 'many-flags|' . $domainId . '|' . self::ANCHOR),
                'date_begin'    => self::ANCHOR - self::DAY,
                'date_end'      => self::ANCHOR,
                'policy_domain' => 'many-flags.' . self::DOMAIN,
                'policy_p'      => 'none',
                'policy_pct'    => 100,
                'message_count' => 0,
                'created'       => gmdate('Y-m-d H:i:s'),
            ];
            $this->db->insertObject('#__dmarc_reports', $report, 'id');

            $expectedIps = [];

            for ($i = 1; $i <= 505; $i++) {
                $ip = sprintf('2001:db8:6::%x', $i);
                $spfOnly = $i % 2 === 0;
                $expectedIps[] = $ip;
                $record = (object) [
                    'report_id'    => (int) $report->id,
                    'domain_id'    => $domainId,
                    'source_ip'    => $ip,
                    'count'        => 1,
                    'disposition'  => 'none',
                    'dkim_result'  => 'fail',
                    'spf_result'   => $spfOnly ? 'pass' : 'fail',
                    'dkim_aligned' => 0,
                    'spf_aligned'  => (int) $spfOnly,
                    'header_from'  => 'many-flags.' . self::DOMAIN,
                ];
                $this->db->insertObject('#__dmarc_records', $record);
            }

            $metrics = new Metrics($this->db);
            $detail  = $metrics->domainMetrics($domainId);
            $detailIps = array_map(static fn (array $source): string => $source['ip'], $detail['flagged_sources']);

            $this->test('Detail metrics include every SPF-only and delivered-failing source beyond 500', function () use ($detailIps, $expectedIps) {
                sort($detailIps, SORT_STRING);
                sort($expectedIps, SORT_STRING);

                return $detailIps === $expectedIps;
            });

            $bulk = $metrics->actionMetricsForAll($metrics->domainSummaries())[$domainId]['flagged_sources'] ?? [];

            $limit   = \Advans\Component\DmarcReports\Administrator\Helper\FlaggedSourceLimit::PER_DOMAIN;
            $totalOk = (int) ($metrics->actionMetricsForAll($metrics->domainSummaries())[$domainId]['flagged_sources_total'] ?? 0) === count($detailIps);

            $this->test('Bulk (dashboard) metrics are bounded to the top-ranked sources of the detail list', function () use ($bulk, $detailIps, $limit, $totalOk) {
                $bulkIps = array_map(static fn (array $source): string => $source['ip'], $bulk);

                return count($bulk) === $limit
                    && $bulkIps === array_slice($detailIps, 0, $limit)
                    && $totalOk
                    && !in_array(false, array_map(
                        static fn (array $source): bool => (int) ($source['evidence_window_end'] ?? 0) === self::ANCHOR,
                        $bulk
                    ), true);
            });
            // A done action of another class (align_source) for a source whose
            // current action is spf_only_source must not hide the open action.
            $stale = [];

            foreach (array_slice($detailIps, 0, $limit) as $ip) {
                $row = (object) [
                    'domain_id'  => $domainId,
                    'action_key' => 'align_source:' . $domainId . ':' . $ip,
                    'category'   => 'alignment',
                    'severity'   => 1,
                    'status'     => 'done',
                ];
                $this->db->insertObject('#__dmarc_actions', $row);
                $stale[] = $row->action_key;
            }

            $afterStale = $metrics->actionMetricsForAll($metrics->domainSummaries())[$domainId]['flagged_sources'] ?? [];

            $this->test('Handled actions of a different class do not take the open slots', function () use ($afterStale, $detailIps, $limit) {
                $ips = array_map(static fn (array $source): string => $source['ip'], $afterStale);

                return count($afterStale) === $limit && $ips === array_slice($detailIps, 0, $limit);
            });

            $this->db->setQuery(
                $this->query()->delete($this->db->quoteName('#__dmarc_actions'))
                    ->where($this->db->quoteName('domain_id') . ' = ' . (int) $domainId)
            )->execute();
            $this->test('The domain source table includes all flagged sources beyond 500', function () use ($detail, $expectedIps) {
                $tableIps = array_column($detail['top_sources'], 'source_ip');
                sort($tableIps, SORT_STRING);
                sort($expectedIps, SORT_STRING);

                return $tableIps === $expectedIps;
            });
        } finally {
            $this->deleteDomain($domainId);
        }
    }

    /**
     * The dashboard's bulk query keeps at most 50 open and 200 handled flagged
     * sources per domain. Handled means a stored action with the exact key of the
     * source's current class (spf_only_source, otherwise align_source) for the same
     * domain that is done, ignored, auto_done or still snoozed; an expired or
     * empty snooze and the status open count as open again.
     */
    private function runHandledSourceSelectionTest(): void
    {
        $domainId = $this->insertDomain('handled-sel.' . self::DOMAIN);
        $other    = $domainId + 1000000;
        $limit    = \Advans\Component\DmarcReports\Administrator\Helper\FlaggedSourceLimit::PER_DOMAIN;
        $max      = \Advans\Component\DmarcReports\Administrator\Helper\FlaggedSourceLimit::MAX;

        try {
            $report = (object) [
                'domain_id'     => $domainId,
                'source_id'     => 0,
                'org_name'      => 'handled-sel',
                'org_email'     => 'reports@handled-sel.test',
                'report_id'     => 'handled-sel-' . $domainId,
                'report_hash'   => hash('sha256', 'handled-sel|' . $domainId . '|' . self::ANCHOR),
                'date_begin'    => self::ANCHOR - self::DAY,
                'date_end'      => self::ANCHOR,
                'policy_domain' => 'handled-sel.' . self::DOMAIN,
                'policy_p'      => 'none',
                'policy_pct'    => 100,
                'message_count' => 0,
                'created'       => gmdate('Y-m-d H:i:s'),
            ];
            $this->db->insertObject('#__dmarc_reports', $report, 'id');

            // 1..260 pass DMARC only through SPF (class spf_only, ranked first, in
            // address order); 261..263 only fail while delivered (class align).
            $ips = [];

            for ($i = 1; $i <= 263; $i++) {
                $ip      = sprintf('2001:db8:7::%04x', $i);
                $spfOnly = $i <= 260;
                $ips[$i] = $ip;
                $this->db->insertObject('#__dmarc_records', (object) [
                    'report_id'    => (int) $report->id,
                    'domain_id'    => $domainId,
                    'source_ip'    => $ip,
                    'count'        => 1,
                    'disposition'  => 'none',
                    'dkim_result'  => 'fail',
                    'spf_result'   => $spfOnly ? 'pass' : 'fail',
                    'dkim_aligned' => 0,
                    'spf_aligned'  => (int) $spfOnly,
                    'header_from'  => 'handled-sel.' . self::DOMAIN,
                ]);
            }

            $metrics = new Metrics($this->db);

            $reset = function () use ($domainId): void {
                $this->db->setQuery(
                    $this->query()->delete($this->db->quoteName('#__dmarc_actions'))
                        ->where($this->db->quoteName('action_key') . ' LIKE ' . $this->db->quote('%:' . $domainId . ':2001:db8:7::%'))
                )->execute();
            };

            $put = function (int $i, string $code, string $status, ?string $snooze = null, ?int $column = null) use ($domainId, $ips): void {
                $this->db->insertObject('#__dmarc_actions', (object) [
                    'domain_id'    => $column ?? $domainId,
                    'action_key'   => $code . ':' . $domainId . ':' . $ips[$i],
                    'category'     => 'source',
                    'severity'     => 0,
                    'status'       => $status,
                    'snooze_until' => $snooze,
                    'params'       => '',
                    'first_seen'   => gmdate('Y-m-d H:i:s'),
                    'last_seen'    => gmdate('Y-m-d H:i:s'),
                    'modified'     => gmdate('Y-m-d H:i:s'),
                    'modified_by'  => 7,
                ]);
            };

            $returned = function () use ($metrics, $domainId): array {
                $rows = $metrics->actionMetricsForAll($metrics->domainSummaries())[$domainId] ?? [];

                return [
                    array_map(static fn (array $s): string => $s['ip'], $rows['flagged_sources'] ?? []),
                    (int) ($rows['flagged_sources_total'] ?? 0),
                ];
            };

            // Expected result: the first $limit open sources in rank order plus
            // every handled source up to $max, open ones first, both in rank order.
            $expect = function (array $handled) use ($ips, $limit, $max): array {
                $open = [];
                $done = [];

                foreach ($ips as $i => $ip) {
                    if (isset($handled[$i])) {
                        if (count($done) < $max) {
                            $done[$i] = $ip;
                        }
                    } elseif (count($open) < $limit) {
                        $open[$i] = $ip;
                    }
                }

                // Contract: open sources first, then handled ones, each in detail rank order.
                return array_merge(array_values($open), array_values($done));
            };

            foreach ([199, 200, 201, 230] as $count) {
                $reset();
                $handled = [];

                for ($i = 1; $i <= $count; $i++) {
                    $put($i, 'spf_only_source', $i % 2 ? 'done' : 'ignored');
                    $handled[$i] = true;
                }

                [$got, $total] = $returned();
                $want = $expect($handled);

                $this->test("{$count} handled sources: open ones still selected, handled capped at {$max}", function () use ($got, $want, $total, $ips, $count, $max) {
                    return $got === $want
                        && $total === count($ips)
                        && ($count <= $max || !in_array($ips[$max + 1], $got, true))
                        && ($count < $max || in_array($ips[$max], $got, true));
                });
            }

            // Handled sources interleaved with open ones, around the 200 boundary:
            // the result is the top 50 open sources in detail order, then the
            // handled ones in detail order, never a mix of the two rankings.
            $patterns = [
                'every third source'             => static fn (int $i): bool => $i <= 260 && $i % 3 === 0,
                'exactly 200 handled'            => static fn (int $i): bool => $i <= 250 && $i % 5 !== 0,
                '201 handled (one over the cap)' => static fn (int $i): bool => $i <= 251 && $i % 5 !== 0,
            ];

            foreach ($patterns as $label => $isHandled) {
                $reset();
                $handled = [];

                foreach (array_keys($ips) as $i) {
                    if ($i <= 260 && $isHandled($i)) {
                        $put($i, 'spf_only_source', 'done');
                        $handled[$i] = true;
                    }
                }

                [$got] = $returned();
                $want  = $expect($handled);

                $this->test("Mixed open and handled sources ({$label}): open first, both in detail order", function () use ($got, $want, $handled, $ips, $max, $limit) {
                    $firstHandled = null;

                    foreach ($got as $position => $ip) {
                        if (isset($handled[array_search($ip, $ips, true)])) {
                            $firstHandled = $position;
                            break;
                        }
                    }

                    return $got === $want
                        && ($firstHandled === null || $firstHandled === $limit)
                        && count(array_filter($got, static fn (string $ip): bool => isset($handled[array_search($ip, $ips, true)]))) === min(count($handled), $max);
                });
            }
            // Exact action key per class, domain match and snooze expiry.
            $reset();
            $future = gmdate('Y-m-d H:i:s', time() + 3600);
            $past   = gmdate('Y-m-d H:i:s', time() - 1);
            $now    = gmdate('Y-m-d H:i:s');

            $put(1, 'spf_only_source', 'done', null, $other);      // another domain's row: open
            $put(2, 'align_source', 'done');                      // wrong class for an SPF-only source: open
            $put(3, 'spf_only_source', 'snoozed', $future);       // running snooze: handled
            $put(4, 'spf_only_source', 'snoozed', $past);         // expired snooze: open
            $put(5, 'spf_only_source', 'snoozed', $now);          // expires exactly now: open
            $put(6, 'spf_only_source', 'snoozed', null);          // snooze without end: open
            $put(7, 'spf_only_source', 'open');                   // reopened: open
            $put(8, 'spf_only_source', 'ignored');                // handled
            $put(9, 'spf_only_source', 'auto_done');              // handled
            $put(261, 'align_source', 'done');                    // exact key of an align source: handled
            $put(262, 'spf_only_source', 'done');                 // wrong class for an align source: open

            $handled = [3 => true, 8 => true, 9 => true, 261 => true];
            [$got, $total] = $returned();
            $want = $expect($handled);

            $this->test('Handled lookup uses the exact key, the domain and the snooze rule', function () use ($got, $want, $total, $ips) {
                return $got === $want && $total === count($ips);
            });

            $this->test('Open sources 1, 2, 4 to 7 stay selected and align source 261 is kept as handled', function () use ($got, $ips) {
                foreach ([1, 2, 4, 5, 6, 7, 3, 8, 9, 261] as $i) {
                    if (!in_array($ips[$i], $got, true)) {
                        return false;
                    }
                }

                return !in_array($ips[262], $got, true) && !in_array($ips[263], $got, true);
            });
        } finally {
            $this->deleteStrayActions($domainId, $other);
            $this->deleteDomain($domainId);
        }
    }

    private function insertAction(int $domainId, string $key, string $status): void
    {
        $row = (object) [
            'domain_id'   => $domainId,
            'action_key'  => $key,
            'category'    => 'source',
            'severity'    => 0,
            'status'      => $status,
            'params'      => '',
            'first_seen'  => gmdate('Y-m-d H:i:s'),
            'last_seen'   => gmdate('Y-m-d H:i:s'),
            'modified'    => gmdate('Y-m-d H:i:s'),
            'modified_by' => 7,
        ];
        $this->db->insertObject('#__dmarc_actions', $row);
    }

    private function deleteDomain(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        foreach (['#__dmarc_actions', '#__dmarc_records', '#__dmarc_reports', '#__dmarc_domains'] as $table) {
            $column = $table === '#__dmarc_domains' ? 'id' : 'domain_id';
            $query  = $this->query()
                ->delete($this->db->quoteName($table))
                ->where($this->db->quoteName($column) . ' = ' . (int) $id);
            $this->db->setQuery($query)->execute();
        }
    }

    /**
     * Remove the actions stored under another domain's id whose key names the
     * given domain (deleteDomain() only removes rows of the domain itself).
     */
    private function deleteStrayActions(int $domainId, int $otherDomainId): void
    {
        $this->db->setQuery(
            $this->query()->delete($this->db->quoteName('#__dmarc_actions'))
                ->where($this->db->quoteName('domain_id') . ' = ' . $otherDomainId)
                ->where($this->db->quoteName('action_key') . ' LIKE ' . $this->db->quote('%:' . $domainId . ':%'))
        )->execute();
    }

    /**
     * Rows of this suite that are still in the database. Every fixture uses the
     * suite's own domain suffix or documentation address prefixes, so a leftover
     * shows up here and fails the run.
     */
    private function leftovers(): int
    {
        $count = function (string $table, string $column, string $like): int {
            return (int) $this->db->setQuery(
                $this->query()->select('COUNT(*)')->from($this->db->quoteName($table))
                    ->where($this->db->quoteName($column) . ' LIKE ' . $this->db->quote($like))
            )->loadResult();
        };

        return $count('#__dmarc_domains', 'domain', '%' . self::DOMAIN)
            + $count('#__dmarc_reports', 'policy_domain', '%' . self::DOMAIN)
            + $count('#__dmarc_tlsrpt_reports', 'policy_domain', '%' . self::DOMAIN)
            + $count('#__dmarc_records', 'source_ip', '2001:db8:6::%')
            + $count('#__dmarc_records', 'source_ip', '2001:db8:7::%')
            + $count('#__dmarc_actions', 'action_key', '%:2001:db8:6::%')
            + $count('#__dmarc_actions', 'action_key', '%:2001:db8:7::%');
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

        foreach (['#__dmarc_actions', '#__dmarc_records', '#__dmarc_reports', '#__dmarc_domains'] as $table) {
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
