<?php
/**
 * Graceful degradation test for the DMARC MTA-STS policy system plugin.
 *
 * The plugin's own test environment installs ONLY the system plugin; the
 * com_dmarcreports component (which provides the MtaStsPolicy generator) is
 * absent. Serving a policy must then degrade gracefully instead of fatalling,
 * and the shipped, Joomla-free PolicyRequest matcher must work independently.
 *
 * This boots a real Joomla console application so the test exercises
 * ComponentHelper and the real autoloader rather than a file_exists() stand-in.
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Advans\Plugin\System\DmarcMtaSts\Support\PolicyRequest;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;

$base   = '/var/www/html/plugins/system/dmarcmtasts';
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

$check('The MtaStsPolicy generator from the component is not loadable', static function () {
    // The plugin guards its servePolicy() with the same class_exists() check,
    // so an absent component means it returns early instead of fatalling.
    return !class_exists(\Advans\Component\DmarcReports\Administrator\Helper\MtaStsPolicy::class);
});

require_once $base . '/src/Support/PolicyRequest.php';

$check('PolicyRequest support class is available without the component', static function () {
    return class_exists(PolicyRequest::class);
});

$check('PolicyRequest resolves the base domain for a well-known request', static function () {
    return PolicyRequest::domainFor('mta-sts.example.com', '/.well-known/mta-sts.txt') === 'example.com';
});

$check('PolicyRequest ignores unrelated hosts and paths', static function () {
    return PolicyRequest::domainFor('www.example.com', '/.well-known/mta-sts.txt') === null
        && PolicyRequest::domainFor('mta-sts.example.com', '/index.php') === null;
});

$check('Plugin extension class loads without fatally requiring the component', static function () {
    return class_exists(\Advans\Plugin\System\DmarcMtaSts\Extension\DmarcMtaSts::class);
});

echo "\n=== Graceful Degradation Test Summary ===\n";
echo "Passed: {$passed}, Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
