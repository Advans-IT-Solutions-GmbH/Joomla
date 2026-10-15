<?php
/**
 * Graceful degradation test for the DMARC Reports ingestion task plugin.
 *
 * The plugin's own test environment installs ONLY the task plugin; the
 * com_dmarcreports component is absent. Installing the plugin on its own must
 * not break the site, and the shipped, Joomla-free IngestSummary support class
 * must work independently of the component.
 *
 * This boots a real Joomla console application so the test exercises
 * ComponentHelper and the real autoloader rather than a file_exists() stand-in.
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Advans\Plugin\Task\DmarcReports\Support\IngestSummary;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;

$base   = '/var/www/html/plugins/task/dmarcreports';
$passed = 0;
$failed = 0;

$check = static function (string $name, callable $fn) use (&$passed, &$failed): void {
    try {
        if ($fn()) {
            echo "✓ {$name}\n";
            $passed++;
        } else {
            echo "✗ {$name}\n";
            $failed++;
        }
    } catch (\Throwable $e) {
        echo "✗ {$name} - Error: {$e->getMessage()}\n";
        $failed++;
    }
};

echo "=== Graceful Degradation Tests ===\n\n";

$container = Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

$app = $container->get(\Joomla\Console\Application::class);
Factory::$application = $app;

$check('The com_dmarcreports component is absent in this environment', static function () {
    return !ComponentHelper::isEnabled('com_dmarcreports');
});

// The shipped support class is deliberately free of Joomla dependencies, so it
// loads and works even when the component is not installed.
require_once $base . '/src/Support/IngestSummary.php';

$check('IngestSummary support class is available without the component', static function () {
    return class_exists(IngestSummary::class);
});

$check('IngestSummary aggregates per-source results', static function () {
    $totals = IngestSummary::aggregate([
        ['status' => 'ok', 'summary' => ['imported' => 2, 'duplicates' => 1, 'failed' => 0]],
        ['status' => IngestSummary::STATUS_ERROR, 'message' => 'boom'],
    ]);

    return $totals['imported'] === 2
        && $totals['duplicates'] === 1
        && $totals['errors'] === 1;
});

// Load the plugin extension class file directly, mirroring how the shipped
// support class is loaded above. The class composes the core Scheduler
// TaskPluginTrait, so its namespace is registered first exactly as the live
// scheduler does; the component's SourcePoller is merely a `use` import
// resolved at execution time, so the file must still compile cleanly even
// though the component is absent.
\JLoader::registerNamespace(
    'Joomla\\Component\\Scheduler\\Administrator',
    JPATH_ADMINISTRATOR . '/components/com_scheduler/src'
);
require_once $base . '/src/Extension/DmarcReports.php';

$check('Plugin extension class loads without fatally requiring the component', static function () {
    return class_exists(\Advans\Plugin\Task\DmarcReports\Extension\DmarcReports::class);
});

echo "\n=== Graceful Degradation Test Summary ===\n";
echo "Passed: {$passed}, Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
