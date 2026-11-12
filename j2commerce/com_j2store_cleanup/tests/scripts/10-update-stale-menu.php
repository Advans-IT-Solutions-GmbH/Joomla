<?php
/**
 * Update over an existing installation whose administrator menu item is stale.
 *
 * A site may keep the administrator menu item of an earlier installation whose component id no
 * longer points to the installed component. Joomla then fails to rebuild the menu item with
 * «JLIB_DATABASE_ERROR_MENU_UNIQUE_ALIAS». The installer script removes such stale items first:
 * the update through Joomla's CLI (as on a real site) must report no error and leave exactly the
 * component's own menu item.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

$db = Factory::getContainer()->get(DatabaseInterface::class);
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $name) use (&$passed, &$failed): void {
    echo ($condition ? '✓ ' : '✗ ') . $name . "\n";
    $condition ? $passed++ : $failed++;
};
$query = static fn () => method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
$menus = static function () use ($db, $query): array {
    return $db->setQuery($query()->select($db->quoteName(['id', 'component_id']))->from($db->quoteName('#__menu'))
        ->where($db->quoteName('client_id') . ' = 1')
        ->where($db->quoteName('link') . ' = ' . $db->quote('index.php?option=com_j2store_cleanup')))->loadObjectList() ?: [];
};

try {
    $extensionId = (int) $db->setQuery($query()->select($db->quoteName('extension_id'))->from($db->quoteName('#__extensions'))
        ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
        ->where($db->quoteName('element') . ' = ' . $db->quote('com_j2store_cleanup')))->loadResult();
    $before = $menus();
    $check($extensionId > 0 && count($before) === 1 && (int) $before[0]->component_id === $extensionId,
        'The installed component has its administrator menu item');

    // The item of an earlier installation: same alias, a component id of no installed extension.
    $stale = 900000 + random_int(1, 99999);
    $db->setQuery('UPDATE ' . $db->quoteName('#__menu') . ' SET ' . $db->quoteName('component_id') . ' = ' . $stale
        . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $before[0]->id)->execute();

    // The installer removes the package it installed: update from a copy of the built package.
    $package = JPATH_BASE . '/tmp/update-stale-menu.zip';

    if (!copy('/tmp/extension.zip', $package)) {
        throw new RuntimeException('The package could not be copied.');
    }

    $output = [];
    exec('HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=' . escapeshellarg($package) . ' --no-interaction 2>&1',
        $output, $exitCode);
    $text = implode("\n", $output);
    $check($exitCode === 0 && stripos($text, 'installed successfully') !== false,
        'The update over the installation with a stale menu item succeeds');
    $check(!str_contains($text, 'JLIB_DATABASE_ERROR_MENU_UNIQUE_ALIAS') && !str_contains($text, '[ERROR]'),
        'The update reports no error, in particular no menu alias conflict');
    $after = $menus();
    $check(count($after) === 1 && (int) $after[0]->component_id === $extensionId,
        'Exactly the component\'s own administrator menu item remains, the stale one is gone');

    if ($failed > 0) {
        echo substr($text, 0, 2000) . "\n";
    }
} catch (Throwable $e) {
    $check(false, 'Update over a stale menu item - Error: ' . $e->getMessage());
}

echo "\n=== Update Test Summary ===\n";
echo "Passed: {$passed}, Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
