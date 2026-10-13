<?php
/**
 * Action center tests: exercise the shipped recommendation engine on the target
 * PHP version. The engine is pure, so the suite drives it directly with sample
 * metrics and asserts the prioritised guidance an administrator would receive.
 */

define('_JEXEC', 1);

require_once '/var/www/html/administrator/components/com_dmarcreports/src/Helper/ActionCenter.php';

use Advans\Component\DmarcReports\Administrator\Helper\ActionCenter;

class ActionCenterTest
{
    private $passed = 0;
    private $failed = 0;

    private const CONFIG = [
        'quarantine_pass_threshold' => 98,
        'reject_pass_threshold'     => 99,
    ];

    private function codes(array $items): array
    {
        return array_map(static fn (array $i): string => $i['code'], $items);
    }

    public function run(): bool
    {
        echo "=== Action Center Tests ===\n\n";

        $this->test('No data yields a single informational item', function () {
            $items = ActionCenter::generate(['domain_id' => 1, 'domain' => 'example.org', 'total' => 0], self::CONFIG);
            return $this->codes($items) === ['no_data'];
        });

        $this->test('High pass rate at p=none recommends quarantine (act now)', function () {
            $items = ActionCenter::generate([
                'domain_id' => 1, 'domain' => 'example.org', 'total' => 1000,
                'current_policy' => 'none', 'dmarc_pass' => 990,
            ], self::CONFIG);
            foreach ($items as $item) {
                if ($item['code'] === 'move_to_quarantine') {
                    return $item['severity'] === 3;
                }
            }
            return false;
        });

        $this->test('Low pass rate at p=none recommends staying', function () {
            $items = ActionCenter::generate([
                'domain_id' => 1, 'domain' => 'example.org', 'total' => 1000,
                'current_policy' => 'none', 'dmarc_pass' => 800,
            ], self::CONFIG);
            return in_array('stay_at_none', $this->codes($items), true)
                && !in_array('move_to_quarantine', $this->codes($items), true);
        });

        $this->test('Unaligned high-volume source is flagged act now', function () {
            $items = ActionCenter::generate([
                'domain_id' => 1, 'domain' => 'example.org', 'total' => 1000,
                'current_policy' => 'none', 'dmarc_pass' => 990,
                'unaligned_sources' => [['ip' => '203.0.113.9', 'count' => 80]],
            ], self::CONFIG);
            foreach ($items as $item) {
                if ($item['code'] === 'align_source') {
                    return $item['severity'] === 3 && $item['action_key'] === 'align_source:1:203.0.113.9';
                }
            }
            return false;
        });

        $this->test('DNS findings merge and the list sorts by severity', function () {
            $items = ActionCenter::generate([
                'domain_id' => 1, 'domain' => 'example.org', 'total' => 1000,
                'current_policy' => 'reject', 'dmarc_pass' => 1000,
            ], self::CONFIG, [['code' => 'dns_dmarc_missing', 'severity' => 3, 'params' => ['example.org']]]);

            if (!in_array('dns_dmarc_missing', $this->codes($items), true)) {
                return false;
            }
            $severities = array_map(static fn (array $i): int => $i['severity'], $items);
            $sorted = $severities;
            rsort($sorted);
            return $severities === $sorted;
        });

        echo "\n=== Action Center Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) { echo "✓ {$name}\n"; $this->passed++; }
            else { echo "✗ {$name}\n"; $this->failed++; }
        } catch (\Throwable $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new ActionCenterTest();
exit($test->run() ? 0 : 1);
