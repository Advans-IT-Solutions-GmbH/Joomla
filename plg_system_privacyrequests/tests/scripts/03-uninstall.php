<?php
require __DIR__ . '/bootstrap.php';

$package = getenv('PRIVACY_PACKAGE') ?: '/tmp/extension.zip';
privacyAssert(is_file($package), 'The exact installable package is available for the reinstall');
$cliArgs = array_values(array_filter(explode(' ', (string) getenv('PRIVACY_CLI_ARGS'))));
// Optional PHP options for hosts whose CLI loads extensions only through -d arguments.
$phpArgs = array_values(array_filter(explode(' ', (string) getenv('PRIVACY_PHP_ARGS'))));
$tables = ['privacy_guest_subjects', 'privacy_guest_attempts', 'privacy_guest_outcomes'];
$prefix = $db->getPrefix();

$cli = static function (array $arguments) use ($cliArgs, $phpArgs): array {
    $command = array_merge([PHP_BINARY], $phpArgs, [JPATH_BASE . '/cli/joomla.php'], $arguments, ['--no-interaction'], $cliArgs);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, JPATH_BASE,
        array_merge(getenv(), ['HTTP_HOST' => 'localhost']));
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($process);
    if ($code !== 0) {
        echo substr((string) $output, -2000), "\n";
    }

    return [$code, (string) $output];
};
$plugin = static function () use ($db): ?object {
    return $db->setQuery("SELECT extension_id, enabled, params, ordering, access FROM #__extensions"
        . " WHERE type='plugin' AND folder='system' AND element='privacyrequests'")->loadObject();
};
$present = static function () use ($db, $prefix, $tables): array {
    $list = array_map('strtolower', $db->getTableList());

    return array_values(array_filter($tables, static fn (string $t): bool => in_array(strtolower($prefix . $t), $list, true)));
};
$snapshot = static function (string $table, int $maxId) use ($db): string {
    $rows = $db->setQuery('SELECT * FROM ' . $db->quoteName($table) . ' WHERE id <= ' . $maxId . ' ORDER BY id')
        ->loadAssocList();

    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
};

$saved = $plugin();
privacyAssert($saved !== null, 'The plugin is installed before the uninstall case');
privacyAssert($present() === $tables, 'All three auxiliary tables exist before the uninstall');

$requestId = 0;
$logId = 0;
try {
    $now = \Joomla\CMS\Factory::getDate()->toSql();
    $request = (object) ['email' => 'uninstall-case@example.test', 'requested_at' => $now, 'status' => 1,
        'request_type' => 'remove', 'confirm_token' => '', 'confirm_token_created_at' => null];
    $db->insertObject('#__privacy_requests', $request, 'id');
    $requestId = (int) $request->id;
    $log = (object) ['message_language_key' => 'PLG_SYSTEM_PRIVACYREQUESTS_UNINSTALL_CASE', 'message' => '{}',
        'log_date' => $now, 'extension' => 'com_privacy.request', 'user_id' => 0, 'item_id' => $requestId,
        'ip_address' => 'COM_ACTIONLOGS_DISABLED'];
    $db->insertObject('#__action_logs', $log, 'id');
    $logId = (int) $log->id;
    $db->setQuery('INSERT INTO ' . $db->quoteName('#__privacy_guest_subjects')
        . ' (subject_hash, request_id, delivery_failed) VALUES ('
        . $db->quote(hash('sha256', 'uninstall-case')) . ', ' . $requestId . ', 0)')->execute();

    $maxRequest = (int) $db->setQuery('SELECT MAX(id) FROM #__privacy_requests')->loadResult();
    $maxLog = (int) $db->setQuery('SELECT MAX(id) FROM #__action_logs')->loadResult();
    $requestsBefore = $snapshot('#__privacy_requests', $maxRequest);
    $logsBefore = $snapshot('#__action_logs', $maxLog);

    [$code, $output] = $cli(['extension:remove', (string) $saved->extension_id]);
    privacyAssert($code === 0, 'The native uninstall command succeeds');
    privacyAssert($plugin() === null, 'The native uninstall removes the extension registration');
    privacyAssert(!is_dir(JPATH_PLUGINS . '/system/privacyrequests'), 'The native uninstall removes the plugin folder');
    privacyAssert($present() === [], 'The uninstall drops exactly the three auxiliary tables and their indexes');
    privacyAssert($snapshot('#__privacy_requests', $maxRequest) === $requestsBefore,
        'Existing Core privacy requests stay byte-for-byte unchanged');
    privacyAssert($snapshot('#__action_logs', $maxLog) === $logsBefore,
        'Existing Core action-log rows stay byte-for-byte unchanged');
    privacyAssert((int) $db->setQuery('SELECT COUNT(*) FROM #__privacy_requests WHERE id > ' . $maxRequest)
        ->loadResult() === 0, 'The uninstall creates no Core privacy request');

    [$code, $output] = $cli(['extension:install', '--path=' . $package]);
    privacyAssert($code === 0, 'A reinstall after the uninstall succeeds');
    $reinstalled = $plugin();
    privacyAssert($reinstalled !== null && (int) $reinstalled->enabled === 0,
        'The reinstall registers the plugin again and leaves it disabled');
    privacyAssert($present() === $tables, 'The reinstall recreates the three auxiliary tables');
    foreach ($tables as $table) {
        privacyAssert((int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__' . $table))->loadResult() === 0,
            "The recreated $table carries no earlier rows");
    }
    $keys = static fn (string $table, string $name): bool => array_filter($db->getTableKeys($table),
        static fn (object $key): bool => ($key->Key_name ?? $key->idxName ?? '') === $db->replacePrefix($name)) !== [];
    privacyAssert($keys('#__privacy_guest_attempts', '#__privacy_guest_expiry')
        && $keys('#__privacy_guest_subjects', '#__privacy_guest_request'), 'The reinstall recreates both indexes');
    privacyAssert($snapshot('#__privacy_requests', $maxRequest) === $requestsBefore
        && $snapshot('#__action_logs', $maxLog) === $logsBefore, 'The reinstall leaves Core requests and action logs unchanged');

    // Test restoration of the operator's earlier plugin settings; a real reinstall leaves this to the operator.
    $db->setQuery('UPDATE #__extensions SET enabled=' . (int) $saved->enabled . ', params=' . $db->quote((string) $saved->params)
        . ', ordering=' . (int) $saved->ordering . ', access=' . (int) $saved->access
        . ' WHERE extension_id=' . (int) $reinstalled->extension_id)->execute();
} finally {
    if ($requestId > 0) {
        $db->setQuery('DELETE FROM #__privacy_requests WHERE id=' . $requestId)->execute();
    }
    if ($logId > 0) {
        $db->setQuery('DELETE FROM #__action_logs WHERE id=' . $logId)->execute();
    }
    if ($present() === $tables) {
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__privacy_guest_subjects')
            . ' WHERE subject_hash=' . $db->quote(hash('sha256', 'uninstall-case')))->execute();
    }
}
