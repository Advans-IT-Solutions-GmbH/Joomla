<?php
/**
 * Graceful degradation test for the DMARC Reports dashboard module.
 *
 * The module's own test environment installs ONLY the module; the
 * com_dmarcreports component is absent. The module must then degrade gracefully
 * instead of fatalling: the helper reports the 'unavailable' state and the
 * layout renders a short, translated notice rather than any statistics.
 *
 * This boots a real Joomla console application, so the test exercises
 * ComponentHelper, the shipped helper and the shipped layout together — not a
 * reflection or a file_exists() stand-in.
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Advans\Module\DmarcReports\Administrator\Helper\DmarcReportsHelper;
use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;

$base   = '/var/www/html/administrator/modules/mod_dmarcreports';
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

// Boot a real Joomla console application the way cli/joomla.php does. The
// shipped administrator web application cannot start from a test process, but
// the console application is a genuine CMSApplicationInterface with a live
// database, so ComponentHelper reports the real (absent) state of the
// component and the helper runs against real Joomla services.
$app = Factory::getContainer()->get(ConsoleApplication::class);
Factory::$application = $app;

$app->getLanguage()->load('mod_dmarcreports', JPATH_ADMINISTRATOR);

require_once $base . '/src/Helper/DmarcReportsHelper.php';

$check('The com_dmarcreports component is absent in this environment', static function () {
    return !ComponentHelper::isEnabled('com_dmarcreports');
});

$params    = new Registry(['show_summary' => 1, 'action_limit' => 5]);
$helper    = new DmarcReportsHelper();
$dashboard = $helper->getDashboard($params, $app);

$check('Helper reports the "unavailable" state when the component is absent', static function () use ($dashboard) {
    return ($dashboard['state'] ?? null) === 'unavailable';
});

$check('Helper returns no action items and a link into the component', static function () use ($dashboard) {
    return $dashboard['actions'] === []
        && (int) ($dashboard['activeCount'] ?? -1) === 0
        && strpos((string) ($dashboard['link'] ?? ''), 'com_dmarcreports') !== false;
});

// Render the shipped layout with the degraded payload and capture its output.
$render = static function (array $dashboard, Registry $params) use ($base, $app): string {
    ob_start();
    include $base . '/tmpl/default.php';
    return (string) ob_get_clean();
};

$html = $render($dashboard, $params);

$check('Layout renders the translated "component unavailable" notice', static function () use ($html) {
    $expected = Text::_('MOD_DMARCREPORTS_COMPONENT_UNAVAILABLE');

    return $expected !== '' && strpos($html, $expected) !== false;
});

$check('Layout does not leak a raw language key', static function () use ($html) {
    return strpos($html, 'MOD_DMARCREPORTS_') === false;
});

$check('Layout does not emit a PHP error', static function () use ($html) {
    return !preg_match('/\b(Fatal error|Parse error|Call to undefined|Uncaught)\b/i', $html);
});

$check('Layout does not render the summary cards while degraded', static function () use ($html) {
    return strpos($html, 'mod-dmarcreports__summary') === false;
});

echo "\n=== Graceful Degradation Test Summary ===\n";
echo "Passed: {$passed}, Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
