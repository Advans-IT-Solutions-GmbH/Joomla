<?php
/**
 * Focused real-Joomla regression. Invoke after bootstrapping an isolated test site
 * and loading its normally installed shared helper. All fixture rows roll back.
 */

defined('_JEXEC') or die;

return static function (\Joomla\Database\DatabaseInterface $db, string $installedHelper): array {
    $file = (new ReflectionClass(\Advans\Installer\SystemCheck::class))->getFileName();

    if (realpath($file) !== realpath($installedHelper)) {
        throw new RuntimeException('The actual installed shared helper must be exercised.');
    }

    $out = ['helper_file' => $file, 'helper_sha256' => hash_file('sha256', $file)];
    $module = $db->quote('mod_dmarcreports');

    if ((int) $db->setQuery('SELECT COUNT(*) FROM #__modules WHERE module=' . $module)->loadResult()
        || (int) $db->setQuery('SELECT COUNT(*) FROM #__extensions WHERE type=' . $db->quote('module')
            . ' AND element=' . $module)->loadResult()) {
        throw new RuntimeException('Refusing to alter an existing module installation.');
    }

    $db->transactionStart();

    try {
        $extension = new \Joomla\CMS\Table\Extension($db);
        $data = ['type' => 'module', 'element' => 'mod_dmarcreports', 'client_id' => 1,
            'name' => 'Synthetic module URL fixture', 'enabled' => 0, 'access' => 1,
            'manifest_cache' => '{}', 'params' => '{}', 'folder' => '', 'custom_data' => '', 'system_data' => ''];

        if (!$extension->bind($data) || !$extension->store()) {
            throw new RuntimeException('Cannot register synthetic module fixture: ' . $extension->getError());
        }

        $out['extension_id'] = (int) $extension->extension_id;

        foreach ([0, 1, 1] as $client) {
            $row = (object) ['title' => 'Synthetic module URL fixture', 'module' => 'mod_dmarcreports',
                'client_id' => $client, 'published' => 0, 'position' => '', 'content' => '',
                'params' => '{}', 'access' => 1, 'language' => '*', 'note' => '', 'ordering' => 0,
                'showtitle' => 0, 'asset_id' => 0];
            $db->insertObject('#__modules', $row, 'id');
            $out['module_ids'][] = (int) $row->id;
        }

        $out['existing_url'] = \Advans\Installer\SystemCheck::url('mod_dmarcreports');

        if ($out['existing_url'] !== 'index.php?option=com_modules&task=module.edit&client_id=1&id=' . $out['module_ids'][1]) {
            throw new RuntimeException('The lowest administrator instance must be selected, not the lower site id.');
        }

        $db->setQuery('DELETE FROM #__modules WHERE id IN ('
            . $out['module_ids'][1] . ',' . $out['module_ids'][2] . ')')->execute();
        $out['missing_url'] = \Advans\Installer\SystemCheck::url('mod_dmarcreports');

        if ($out['missing_url'] !== 'index.php?option=com_modules&task=module.add&client_id=1&eid=' . $out['extension_id']) {
            throw new RuntimeException('A missing administrator instance must link to the registered module add page.');
        }
    } finally {
        $db->transactionRollback();
    }

    $out['rolled_back'] = (int) $db->setQuery('SELECT COUNT(*) FROM #__modules WHERE module=' . $module)->loadResult() === 0
        && (int) $db->setQuery('SELECT COUNT(*) FROM #__extensions WHERE type=' . $db->quote('module')
            . ' AND element=' . $module)->loadResult() === 0;

    if (!$out['rolled_back']) {
        throw new RuntimeException('Synthetic module fixture rows must not survive the test.');
    }

    return $out;
};
