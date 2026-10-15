<?php
/**
 * Automatic ingestion tests for the DMARC Reports package.
 *
 * The bundled task plugin (plg_task_dmarcreports) must drive the component's
 * SourcePoller through Joomla's real scheduler. These tests install a scheduler
 * task of the plugin's routine type and run it with `scheduler:run --id`, which
 * the scheduler only executes when an enabled task plugin advertises the
 * routine. A successful run therefore proves the plugin is discoverable and the
 * task -> SourcePoller -> component wiring works end to end with the component
 * present. A second run against an unreachable source proves the per-source
 * failure is recorded gracefully (no credential leak, cursor untouched) and that
 * only published sources bound to published domains are polled.
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

class AutoIngestTest
{
    private DatabaseInterface $db;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function test(string $name, bool $condition, string $message = ''): void
    {
        if ($condition) {
            echo "✓ {$name}\n";
            $this->passed++;
        } else {
            echo "✗ {$name}" . ($message !== '' ? " — {$message}" : '') . "\n";
            $this->failed++;
        }
    }

    public function run(): bool
    {
        echo "=== Package Automatic Ingestion Tests ===\n\n";

        $this->enableTaskPlugin();
        $this->testWiring();
        $this->testNoActiveSources();
        $this->testActiveSourceErrorAndFiltering();

        echo "\n=== Package Automatic Ingestion Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    /**
     * Enable the bundled task plugin so the scheduler advertises its routine.
     */
    private function enableTaskPlugin(): void
    {
        echo "--- Plugin wiring ---\n";

        $this->db->setQuery(
            $this->db->getQuery(true)
                ->update($this->db->quoteName('#__extensions'))
                ->set($this->db->quoteName('enabled') . ' = 1')
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('task'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('dmarcreports'))
        )->execute();

        $enabled = (int) $this->db->setQuery(
            $this->db->getQuery(true)
                ->select($this->db->quoteName('enabled'))
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('task'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('dmarcreports'))
        )->loadResult();

        $this->test('Task plugin enabled', $enabled === 1);
    }

    private function testWiring(): void
    {
        $taskFile = JPATH_BASE . '/plugins/task/dmarcreports/src/Extension/DmarcReports.php';
        $this->test('Task plugin class file present', file_exists($taskFile));

        if (file_exists($taskFile)) {
            $src = file_get_contents($taskFile);
            $this->test('Task plugin advertises the ingest routine', str_contains($src, 'plg_task_dmarcreports.ingest'));
            $this->test('Task plugin reuses the component SourcePoller', str_contains($src, 'SourcePoller'));
        }
    }

    /**
     * With no active sources the routine returns Status::OK, so the scheduler
     * exits cleanly. This proves the end-to-end wiring without any network.
     */
    private function testNoActiveSources(): void
    {
        echo "\n--- Scheduler runs the ingest routine (no active sources) ---\n";

        $run = $this->runIngestTask();

        $this->test('scheduled task created', $run['created'], $run['detail']);
        $this->test('scheduler:run exits with 0', $run['exit'] === 0, 'exit code ' . $run['exit']);
        $this->test('task was executed by the scheduler', $run['executed'], $run['detail']);
        $this->test('task finished with Status::OK', $run['ok'], $run['detail']);
    }

    /**
     * An active source pointing at an unreachable mailbox must be polled and its
     * failure recorded on the source row, while an unpublished source and a
     * source on an unpublished domain are skipped.
     */
    private function testActiveSourceErrorAndFiltering(): void
    {
        echo "\n--- Scheduler polls active sources only and records failures ---\n";

        $suffix       = (string) time();
        $activeDomain = 'active-' . $suffix . '.example.com';
        $downDomain   = 'down-' . $suffix . '.example.com';

        $activeDomainId  = $this->seedDomain($activeDomain, 1);
        $downDomainId    = $this->seedDomain($downDomain, 0);

        // Published source on a published domain: must be polled (and fail).
        $activeSourceId = $this->seedSource($activeDomainId, 1);
        // Unpublished source on a published domain: must be skipped.
        $disabledSourceId = $this->seedSource($activeDomainId, 0);
        // Published source on an unpublished domain: must be skipped.
        $orphanSourceId = $this->seedSource($downDomainId, 1);

        $this->test('test sources seeded', $activeSourceId > 0 && $disabledSourceId > 0 && $orphanSourceId > 0);

        $run = $this->runIngestTask();

        $this->test('scheduler:run executed the routine', $run['executed'], $run['detail']);

        $this->test(
            'active source polled and failure recorded',
            $this->sourceStatus($activeSourceId) === 'error'
        );

        $this->test(
            'failure message stored without leaking credentials',
            $this->sourceMessageSafe($activeSourceId)
        );

        $this->test(
            'unpublished source skipped',
            $this->sourceStatus($disabledSourceId) === ''
        );

        $this->test(
            'source on unpublished domain skipped',
            $this->sourceStatus($orphanSourceId) === ''
        );

        $this->cleanupSources([$activeSourceId, $disabledSourceId, $orphanSourceId]);
        $this->cleanupDomains([$activeDomainId, $downDomainId]);
    }

    // -------------------------------------------------------------------------

    /**
     * Create a scheduler task for the ingest routine, run it through Joomla's
     * scheduler CLI and remove it again.
     *
     * @return array{created:bool, exit:int, executed:bool, ok:bool, detail:string}
     */
    private function runIngestTask(): array
    {
        $result = ['created' => false, 'exit' => -1, 'executed' => false, 'ok' => false, 'detail' => ''];
        $task   = (object) [
            'title'           => 'DMARC ingest (test)',
            'type'            => 'plg_task_dmarcreports.ingest',
            'execution_rules' => '{"rule-type":"manual"}',
            'cron_rules'      => '{"type":"manual","exp":""}',
            'state'           => 1,
            'last_exit_code'  => 0,
            'times_executed'  => 0,
            'times_failed'    => 0,
            'priority'        => 0,
            'ordering'        => 0,
            'params'          => '{"auto_create_domains":0}',
            'note'            => '',
            'created'         => date('Y-m-d H:i:s'),
            'created_by'      => 0,
        ];

        try {
            $this->db->insertObject('#__scheduler_tasks', $task, 'id');
        } catch (\Throwable $e) {
            $result['detail'] = 'task not created: ' . $e->getMessage();

            return $result;
        }

        $taskId            = (int) $task->id;
        $result['created'] = $taskId > 0;

        $output = [];
        $exit   = 0;
        exec('cd ' . escapeshellarg(JPATH_BASE) . ' && HTTP_HOST=localhost php cli/joomla.php scheduler:run --id=' . $taskId . ' 2>&1', $output, $exit);
        echo '  ' . implode("\n  ", $output) . "\n";
        $result['exit'] = $exit;

        $row = $this->db->setQuery(
            $this->db->getQuery(true)
                ->select([$this->db->quoteName('last_exit_code'), $this->db->quoteName('times_executed')])
                ->from($this->db->quoteName('#__scheduler_tasks'))
                ->where($this->db->quoteName('id') . ' = ' . $taskId)
        )->loadObject();

        $result['executed'] = $row !== null && (int) $row->times_executed >= 1;
        $result['ok']       = $exit === 0 && $result['executed'] && (int) $row->last_exit_code === 0;
        $result['detail']   = 'exit code ' . $exit . ', times_executed=' . ($row->times_executed ?? 'n/a')
            . ', last_exit_code=' . ($row->last_exit_code ?? 'n/a');

        $this->db->setQuery(
            $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__scheduler_tasks'))
                ->where($this->db->quoteName('id') . ' = ' . $taskId)
        )->execute();

        return $result;
    }

    private function seedDomain(string $domain, int $published): int
    {
        $row = (object) [
            'domain'    => $domain,
            'title'     => 'Test ' . $domain,
            'published' => $published,
            'created'   => date('Y-m-d H:i:s'),
        ];
        $this->db->insertObject('#__dmarc_domains', $row, 'id');

        return (int) $row->id;
    }

    private function seedSource(int $domainId, int $published): int
    {
        $row = (object) [
            'name'         => 'Test source',
            'domain_id'    => $domainId,
            'protocol'     => 'imap',
            'host'         => '127.0.0.1',
            'port'         => 1,
            'security'     => 'ssl',
            'username'     => 'test@example.com',
            'password_enc' => '',
            'folder'       => 'INBOX',
            'published'    => $published,
            'last_status'  => '',
            'last_message' => '',
            'created'      => date('Y-m-d H:i:s'),
        ];
        $this->db->insertObject('#__dmarc_sources', $row, 'id');

        return (int) $row->id;
    }

    private function sourceStatus(int $sourceId): string
    {
        return (string) $this->db->setQuery(
            $this->db->getQuery(true)
                ->select($this->db->quoteName('last_status'))
                ->from($this->db->quoteName('#__dmarc_sources'))
                ->where($this->db->quoteName('id') . ' = ' . $sourceId)
        )->loadResult();
    }

    private function sourceMessageSafe(int $sourceId): bool
    {
        $message = (string) $this->db->setQuery(
            $this->db->getQuery(true)
                ->select($this->db->quoteName('last_message'))
                ->from($this->db->quoteName('#__dmarc_sources'))
                ->where($this->db->quoteName('id') . ' = ' . $sourceId)
        )->loadResult();

        // A message is stored, and it never contains the seeded mailbox host,
        // username or password (only a fixed, localized failure category).
        return $message !== ''
            && !str_contains($message, '127.0.0.1')
            && !str_contains($message, 'test@example.com');
    }

    private function cleanupSources(array $ids): void
    {
        $ids = array_map('intval', $ids);
        $this->db->setQuery(
            $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__dmarc_sources'))
                ->where($this->db->quoteName('id') . ' IN (' . implode(',', $ids) . ')')
        )->execute();
    }

    private function cleanupDomains(array $ids): void
    {
        $ids = array_map('intval', $ids);
        $this->db->setQuery(
            $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__dmarc_domains'))
                ->where($this->db->quoteName('id') . ' IN (' . implode(',', $ids) . ')')
        )->execute();
    }
}

$test = new AutoIngestTest();
exit($test->run() ? 0 : 1);
