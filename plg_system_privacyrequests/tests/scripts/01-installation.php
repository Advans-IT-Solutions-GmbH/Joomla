<?php
require __DIR__ . '/bootstrap.php';

$plugin = $db->setQuery("SELECT extension_id, enabled, client_id FROM #__extensions"
    . " WHERE type='plugin' AND folder='system' AND element='privacyrequests'")->loadObject();
privacyAssert($plugin !== null && (int) $plugin->extension_id > 0, 'Native installer registers the standalone plugin');
privacyAssert((int) $plugin->enabled === 0 && (int) $plugin->client_id === 0,
    'Installation leaves the site plugin disabled');
$base = JPATH_PLUGINS . '/system/privacyrequests/';
foreach (['services/provider.php', 'src/Extension/PrivacyRequests.php', 'LICENSE.txt',
    'advansinstaller/SystemCheck.php', 'script.php'] as $file) {
    privacyAssert(is_file($base . $file), "Native installer installs $file");
}
foreach (['de-DE', 'en-GB', 'fr-FR'] as $tag) {
    privacyAssert(is_file($base . "language/$tag/plg_system_privacyrequests.ini"), "Native installer installs $tag");
}
foreach (['privacy_guest_subjects', 'privacy_guest_attempts', 'privacy_guest_outcomes'] as $table) {
    $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__' . $table))->loadResult();
    privacyAssert(true, "Native SQL creates $table");
}
$expiryIndexes = array_filter($db->getTableKeys('#__privacy_guest_attempts'),
    static fn (object $key): bool => ($key->Key_name ?? $key->idxName ?? '') === $db->replacePrefix('#__privacy_guest_expiry'));
privacyAssert($expiryIndexes !== [], 'The native installer creates the indexed expiry path, also on upgrades');
$requestIndexes = array_filter($db->getTableKeys('#__privacy_guest_subjects'),
    static fn (object $key): bool => ($key->Key_name ?? $key->idxName ?? '') === $db->replacePrefix('#__privacy_guest_request')
        && ($key->Column_name ?? 'request_id') === 'request_id');
privacyAssert($requestIndexes !== [], 'The native installer indexes subject mappings by request, also on upgrades');
privacyAssert((int) $db->setQuery("SELECT COUNT(*) FROM #__scheduler_tasks"
    . " WHERE type LIKE '%privacyrequests%'")->loadResult() === 0, 'Installation creates no active or inactive Scheduler tasks');
privacyAssert((int) $db->setQuery("SELECT COUNT(*) FROM #__extensions WHERE type='plugin'"
    . " AND folder='task' AND element='privacyrequests'")->loadResult() === 0, 'No hidden task plugin is installed');
