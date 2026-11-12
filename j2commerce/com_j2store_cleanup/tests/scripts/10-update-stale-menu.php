<?php
/**
 * Update over an existing installation whose administrator menu item is stale or missing.
 *
 * A site may keep the administrator menu item of an earlier installation: its component id points
 * to no installed extension or to another installed component, it keeps the alias, and it may still
 * link the earlier option com_j2commerce_cleanup. The other component's own items must stay. Joomla then fails to rebuild the menu item with
 * «JLIB_DATABASE_ERROR_MENU_UNIQUE_ALIAS» and the component has no item in the Components menu.
 * Another site may have no item at all. Each case is updated through Joomla's CLI, as on a real
 * site: no error, exactly the component's own menu item afterwards, no orphan left.
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
$extensionId = static fn (): int => (int) $db->setQuery($query()->select($db->quoteName('extension_id'))->from($db->quoteName('#__extensions'))
    ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
    ->where($db->quoteName('element') . ' = ' . $db->quote('com_j2store_cleanup')))->loadResult();
// Every administrator item that is the component's, an orphan of it or of its earlier option.
$items = static function () use ($db, $query): array {
    return $db->setQuery($query()->select($db->quoteName(['id', 'component_id', 'alias', 'link']))->from($db->quoteName('#__menu'))
        ->where($db->quoteName('client_id') . ' = 1')
        ->where('(' . $db->quoteName('link') . ' LIKE ' . $db->quote('index.php?option=com_j2store_cleanup%')
            . ' OR ' . $db->quoteName('link') . ' LIKE ' . $db->quote('index.php?option=com_j2commerce_cleanup%')
            . ' OR ' . $db->quoteName('alias') . ' IN (' . $db->quote('com_j2store_cleanup') . ', ' . $db->quote('com-j2store-cleanup') . '))'))
        ->loadObjectList() ?: [];
};
$update = static function (): string {
    // Joomla removes the package it installed: update from a copy of the built package.
    $package = JPATH_BASE . '/tmp/update-stale-menu-' . bin2hex(random_bytes(4)) . '.zip';

    if (!copy('/tmp/extension.zip', $package)) {
        throw new RuntimeException('The package could not be copied.');
    }

    $output = [];
    exec('HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=' . escapeshellarg($package) . ' --no-interaction 2>&1',
        $output, $exitCode);

    return $exitCode . "\n" . implode("\n", $output);
};
$verify = static function (string $case, string $text) use ($db, $check, $items, $extensionId): void {
    $id = $extensionId();
    $check(str_starts_with($text, "0\n") && stripos($text, 'installed successfully') !== false, $case . ': the update succeeds');
    $check(!str_contains($text, 'JLIB_DATABASE_ERROR_MENU_UNIQUE_ALIAS') && !str_contains($text, '[ERROR]'),
        $case . ': the update reports no error, in particular no menu alias conflict');
    $after = $items();
    $check(count($after) === 1 && (int) $after[0]->component_id === $id && $after[0]->link === 'index.php?option=com_j2store_cleanup',
        $case . ': exactly the component\'s own administrator menu item remains');
    $check((int) $db->setQuery('SELECT COUNT(*) - COUNT(DISTINCT ' . $db->quoteName('lft') . ') FROM ' . $db->quoteName('#__menu'))->loadResult() === 0
        && (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__menu') . ' WHERE ' . $db->quoteName('rgt') . ' <= ' . $db->quoteName('lft')
            . ' AND ' . $db->quoteName('id') . ' > 1')->loadResult() === 0,
        $case . ': the menu tree stays consistent');

    if (str_contains($text, '[ERROR]') || count($after) !== 1) {
        echo substr($text, 0, 1500) . "\n" . json_encode($after) . "\n";
    }
};
// Removes an item with its children from the nested set, like the menu manager's delete.
$remove = static function (int $id) use ($db, $query): void {
    $node = $db->setQuery($query()->select($db->quoteName(['lft', 'rgt']))->from($db->quoteName('#__menu'))
        ->where($db->quoteName('id') . ' = ' . $id))->loadObject();

    if (!$node) {
        return;
    }

    $width = (int) $node->rgt - (int) $node->lft + 1;
    $db->setQuery('DELETE FROM ' . $db->quoteName('#__menu') . ' WHERE ' . $db->quoteName('lft') . ' BETWEEN ' . (int) $node->lft . ' AND ' . (int) $node->rgt)->execute();
    $db->setQuery('UPDATE ' . $db->quoteName('#__menu') . ' SET ' . $db->quoteName('lft') . ' = ' . $db->quoteName('lft') . ' - ' . $width
        . ' WHERE ' . $db->quoteName('lft') . ' > ' . (int) $node->rgt)->execute();
    $db->setQuery('UPDATE ' . $db->quoteName('#__menu') . ' SET ' . $db->quoteName('rgt') . ' = ' . $db->quoteName('rgt') . ' - ' . $width
        . ' WHERE ' . $db->quoteName('rgt') . ' > ' . (int) $node->rgt)->execute();
};

try {
    $before = $items();
    $check($extensionId() > 0 && count($before) === 1 && (int) $before[0]->component_id === $extensionId(),
        'The installed component has its administrator menu item');

    // 1. The item of an earlier installation: same alias and link, a component id of no extension.
    $db->setQuery('UPDATE ' . $db->quoteName('#__menu') . ' SET ' . $db->quoteName('component_id') . ' = ' . (900000 + random_int(1, 49999))
        . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $before[0]->id)->execute();
    $verify('Stale item with the own link', $update());

    // 2. As found on a site: the earlier option in the link, the same alias, a component id of no extension.
    $current = $items();
    $db->setQuery('UPDATE ' . $db->quoteName('#__menu') . ' SET ' . $db->quoteName('component_id') . ' = ' . (950000 + random_int(1, 49999))
        . ', ' . $db->quoteName('link') . ' = ' . $db->quote('index.php?option=com_j2commerce_cleanup')
        . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) ($current[0]->id ?? 0))->execute();
    $verify('Stale item with the earlier option', $update());

    // 3. No item at all.
    foreach ($items() as $row) {
        $remove((int) $row->id);
    }

    $check($items() === [], 'Without any item: the component has no administrator menu item before the update');
    $verify('No item at all', $update());

    // 4. As found on a site: the earlier option in the link and the own alias, but attributed to another
    // installed component that has its own valid items. Only the misattributed item may go.
    $candidates = $db->setQuery($query()->select($db->quoteName(['m.component_id', 'm.link', 'e.element']))
        ->from($db->quoteName('#__menu', 'm'))
        ->join('INNER', $db->quoteName('#__extensions', 'e'), $db->quoteName('e.extension_id') . ' = ' . $db->quoteName('m.component_id'))
        ->where($db->quoteName('m.client_id') . ' = 1')
        ->where($db->quoteName('m.menutype') . ' = ' . $db->quote('main'))
        ->where($db->quoteName('e.type') . ' = ' . $db->quote('component'))
        ->where($db->quoteName('e.element') . ' <> ' . $db->quote('com_j2store_cleanup'))
        ->order('CASE WHEN ' . $db->quoteName('e.element') . ' = ' . $db->quote('com_j2commerce') . ' THEN 0 ELSE 1 END, ' . $db->quoteName('m.id')))
        ->loadObjectList() ?: [];
    $otherId = 0;
    $otherElement = '';

    foreach ($candidates as $candidate) {
        parse_str((string) parse_url((string) $candidate->link, PHP_URL_QUERY), $linkQuery);

        if (strtolower((string) ($linkQuery['option'] ?? '')) === strtolower((string) $candidate->element)) {
            $otherId = (int) $candidate->component_id;
            $otherElement = (string) $candidate->element;
            break;
        }
    }

    $check($otherId > 0, 'Another installed component with its own administrator menu items exists (' . $otherElement . ')');
    $snapshot = static fn (): array => $db->setQuery($query()
        ->select($db->quoteName(['id', 'title', 'alias', 'path', 'link', 'parent_id', 'level', 'published', 'component_id', 'menutype']))
        ->from($db->quoteName('#__menu'))
        ->where($db->quoteName('client_id') . ' = 1')
        ->where($db->quoteName('component_id') . ' = ' . $otherId)
        ->order($db->quoteName('id')))->loadAssocList() ?: [];
    $otherBefore = $snapshot();
    $check(count($otherBefore) > 0, 'The other component has administrator menu items before the update');
    $current = $items();
    $db->setQuery('UPDATE ' . $db->quoteName('#__menu') . ' SET ' . $db->quoteName('component_id') . ' = ' . $otherId
        . ', ' . $db->quoteName('link') . ' = ' . $db->quote('index.php?option=com_j2commerce_cleanup')
        . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) ($current[0]->id ?? 0))->execute();
    $verify('Item attributed to another installed component', $update());
    $check($snapshot() === $otherBefore, 'Item attributed to another installed component: the other component\'s items are untouched');
} catch (Throwable $e) {
    $check(false, 'Update over a stale menu item - Error: ' . $e->getMessage());
}

echo "\n=== Update Test Summary ===\n";
echo "Passed: {$passed}, Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
