<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class PlgJ2commerceProductcompareInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    public function preflight($type, $parent)
    {
        return parent::preflight($type, $parent);
    }

    public function postflight($type, $parent)
    {
        $isJ6 = (int) \Joomla\CMS\Version::MAJOR_VERSION >= 6;

        if ($type === 'uninstall') {
            // Joomla has removed the registered plugin folder by now. On Joomla 5
            // the files Joomla installed under the manifest group (j2commerce/)
            // are not registered anywhere and are removed here.
            if (!$isJ6) {
                $this->removePath(JPATH_PLUGINS . '/j2commerce/productcompare');
            }

            return;
        }

        if ($type === 'install' || $type === 'update') {
            // Joomla 5 looks for an existing plugin in the manifest group
            // (j2commerce) and does not find the row that was moved to j2store,
            // so every update arrives here as a new installation with a second
            // row. The rows are merged back into the existing one first.
            $keptId   = $isJ6 ? 0 : $this->mergeDuplicateExtensionRows();
            $merged   = $keptId > 0;
            $isUpdate = $type === 'update' || $merged;

            if ($merged) {
                $this->reportExtensionId($parent, $keptId);
            }

            $this->setGroupForInstalledStack();
            $this->ensureUpdateSite();

            $app = Factory::getApplication();
            $lang = $app->getLanguage();
            $lang->load('plg_j2commerce_productcompare', JPATH_ADMINISTRATOR);
            $lang->load('plg_j2commerce_productcompare', $parent->getParent()->getPath('source'));

            $enabled = $this->isPluginEnabled();

            // Updates only get a short confirmation, plus a hint while the plugin
            // is disabled; the first installation shows the setup notes and asks
            // to enable the plugin only while it is disabled.
            if ($isUpdate) {
                $manifest = method_exists($parent, 'getManifest') ? $parent->getManifest() : null;
                $version  = $manifest instanceof \SimpleXMLElement ? (string) $manifest->version : '';

                $app->enqueueMessage(
                    Text::sprintf('PLG_J2COMMERCE_PRODUCTCOMPARE_POSTINSTALL_UPDATED', htmlspecialchars($version)),
                    'message'
                );

                if (!$enabled) {
                    $app->enqueueMessage(Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_ENABLE_HINT'), 'message');
                }

                return;
            }

            $sBox = 'padding:16px 20px;margin:16px 0;border-radius:4px;border-left:4px solid;background:#eff6ff;border-color:#2563eb';

            $message = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:860px">';
            $message .= '<h2 style="margin-bottom:16px">' . Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_POSTINSTALL_TITLE') . '</h2>';
            $message .= '<div style="' . $sBox . '">';

            if (!$enabled) {
                $message .= '<p>' . Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_POSTINSTALL_ENABLE') . '</p>';
            }

            $message .= '<p>' . Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_POSTINSTALL_SEARCH') . '</p>';

            if ($this->isJ2StoreActiveShop()) {
                $message .= '<p>' . Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_POSTINSTALL_J2STORE_NOTE') . '</p>';
            }
            $message .= '</div>';
            $message .= '<p style="margin-top:12px;color:#6b7280;font-size:13px">' . Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_POSTINSTALL_DOCS') . '</p>';
            $message .= '</div>';

            $app->enqueueMessage($message, 'message');
        }
    }

    public function uninstall($parent)
    {
        // Joomla removes the registered plugin folder itself after this method
        // and fails when that folder is missing or is a symlink, so nothing is
        // deleted here. Installations from older versions may still have
        // plugins/j2store/productcompare as a symlink; it is replaced by a real
        // copy so Joomla can remove it. The unregistered j2commerce/ copy on
        // Joomla 5 is removed in postflight('uninstall').
        if ((int) \Joomla\CMS\Version::MAJOR_VERSION >= 6) {
            return;
        }

        $mirror = JPATH_PLUGINS . '/j2store/productcompare';

        if (!is_link($mirror)) {
            return;
        }

        $target = realpath($mirror);
        $tmp    = $mirror . '.tmp-' . getmypid();

        if ($target !== false && is_dir($target)) {
            $this->copyDir($target, $tmp);
        } else {
            @mkdir($tmp, 0755, true);
        }

        if (@unlink($mirror) && !@rename($tmp, $mirror)) {
            // Keep a folder in place so Joomla's removal does not fail.
            @mkdir($mirror, 0755, true);
        }

        $this->removePath($tmp);
    }

    /**
     * Merge a second #__extensions row of this plugin on Joomla 5.
     *
     * The installer stores a new row with folder=j2commerce when the existing
     * row was moved to folder=j2store. The existing row is kept (ID, params,
     * enabled state, ordering) and receives the manifest data of the new row;
     * the new row and its update-site links, schema entries and pending
     * updates are removed, and update sites that no extension uses any more
     * afterwards are removed as well. Duplicate rows left by earlier versions
     * are removed the same way.
     *
     * @return int ID of the kept row when rows were merged (this run was an
     *             update), otherwise 0.
     */
    private function mergeDuplicateExtensionRows(): int
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $this->createDbQuery($db)
            ->select($db->quoteName(['extension_id', 'folder', 'name', 'manifest_cache']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('productcompare'))
            ->whereIn($db->quoteName('folder'), ['j2commerce', 'j2store'], ParameterType::STRING)
            ->order($db->quoteName('extension_id') . ' ASC');
        $rows = $db->setQuery($query)->loadObjectList() ?: [];

        $keep = null;
        $new  = null;

        foreach ($rows as $row) {
            if ($row->folder === 'j2store' && $keep === null) {
                $keep = $row;
            }

            if ($row->folder === 'j2commerce') {
                $new = $row;
            }
        }

        if ($keep === null || count($rows) < 2) {
            return 0;
        }

        $source = $new ?? end($rows);
        $keepId = (int) $keep->extension_id;

        $query = $this->createDbQuery($db)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('manifest_cache') . ' = :cache')
            ->set($db->quoteName('name') . ' = :name')
            ->where($db->quoteName('extension_id') . ' = :id')
            ->bind(':cache', $source->manifest_cache)
            ->bind(':name', $source->name)
            ->bind(':id', $keepId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        $removedIds = [];

        foreach ($rows as $row) {
            if ((int) $row->extension_id !== $keepId) {
                $removedIds[] = (int) $row->extension_id;
            }
        }

        // Update sites linked to the removed rows; checked again afterwards.
        $query = $this->createDbQuery($db)
            ->select('DISTINCT ' . $db->quoteName('update_site_id'))
            ->from($db->quoteName('#__update_sites_extensions'))
            ->whereIn($db->quoteName('extension_id'), $removedIds);
        $siteIds = array_map('intval', $db->setQuery($query)->loadColumn() ?: []);

        foreach (['#__update_sites_extensions', '#__updates', '#__schemas', '#__extensions'] as $table) {
            $query = $this->createDbQuery($db)
                ->delete($db->quoteName($table))
                ->whereIn($db->quoteName('extension_id'), $removedIds);
            $db->setQuery($query)->execute();
        }

        // Remove update sites (and their pending updates) that no extension
        // uses any more; sites still linked to another extension stay.
        foreach ($siteIds as $siteId) {
            $query = $this->createDbQuery($db)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :site')
                ->bind(':site', $siteId, ParameterType::INTEGER);

            if ((int) $db->setQuery($query)->loadResult() > 0) {
                continue;
            }

            foreach (['#__updates', '#__update_sites'] as $table) {
                $query = $this->createDbQuery($db)
                    ->delete($db->quoteName($table))
                    ->where($db->quoteName('update_site_id') . ' = :site')
                    ->bind(':site', $siteId, ParameterType::INTEGER);
                $db->setQuery($query)->execute();
            }
        }

        return $keepId;
    }

    /**
     * Make the installer report the kept row as the installed extension.
     *
     * Joomla returns the ID of the row it stored in this run and passes it to
     * onExtensionAfterInstall, where the core extension plugin links the
     * manifest's update server to that ID. After a merge that row no longer
     * exists, so the installer's extension record is pointed at the kept row;
     * the update server is then linked to the kept row and no link to a deleted
     * row is created. The record is a protected property of the installer
     * adapter and is only reachable from the adapter's class scope.
     */
    private function reportExtensionId(object $adapter, int $extensionId): void
    {
        if (!$adapter instanceof \Joomla\CMS\Installer\InstallerAdapter) {
            return;
        }

        $setId = \Closure::bind(
            static function (\Joomla\CMS\Installer\InstallerAdapter $adapter, int $id): void {
                if (isset($adapter->extension) && \is_object($adapter->extension)) {
                    $adapter->extension->extension_id = $id;
                }
            },
            null,
            \Joomla\CMS\Installer\InstallerAdapter::class
        );

        $setId($adapter, $extensionId);
    }

    /**
     * Copy plugin files to plugins/j2store/productcompare/ on Joomla 5 installs.
     *
     * Joomla installs plugin files to plugins/{manifest-group}/ — always j2commerce
     * here. J2Store 4's eventWithHtml() only imports the j2store group, so the
     * plugin must also be reachable under plugins/j2store/productcompare/.
     *
     * The files are copied (no symlink: Joomla's uninstaller cannot remove a
     * symlinked plugin folder) and #__extensions.folder is set to j2store so
     * Joomla's plugin loader and uninstaller use that folder.
     *
     * On J6 (no com_j2store): nothing to do, folder stays j2commerce.
     */
    private function setGroupForInstalledStack(): void
    {
        // Detect Joomla major version. Joomla 6+ ships with J2Commerce 6 which
        // imports the j2commerce plugin group. Joomla 5 uses J2Store 4 which
        // imports the j2store group. Using the Joomla version is more reliable
        // than checking for com_j2commerce in #__extensions, because J2Commerce
        // may not yet be installed when postflight() runs.
        $isJ6 = (int) \Joomla\CMS\Version::MAJOR_VERSION >= 6;

        if ($isJ6) {
            // J6: canonical location j2commerce/ is correct, nothing to do.
            return;
        }

        $src  = JPATH_PLUGINS . '/j2commerce/productcompare';
        $dest = JPATH_PLUGINS . '/j2store/productcompare';

        if (!is_dir($src) || is_link($src)) {
            return;
        }

        // Ensure the parent directory exists (plugins/j2store/ may not exist if no
        // j2store plugin has been installed yet).
        $destParent = dirname($dest);
        if (!is_dir($destParent)) {
            mkdir($destParent, 0755, true);
        }

        // Replace the previous copy (or a symlink from older versions).
        $this->removePath($dest);
        $this->copyDir($src, $dest);

        // Update #__extensions so Joomla's plugin loader resolves the correct path.
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $q  = $this->createDbQuery($db)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('folder') . ' = ' . $db->quote('j2store'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('productcompare'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('j2commerce'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));
        $db->setQuery($q);
        $db->execute();
    }

    /**
     * Remove a symlink or a directory tree; missing paths are ignored.
     */
    private function removePath(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            $this->removeDir($path);
        }
    }

    /**
     * Recursively copy a directory tree.
     */
    private function copyDir(string $src, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $item) {
            $target = $dest . '/' . $iter->getSubPathname();
            $item->isDir() ? mkdir($target, 0755, true) : copy($item->getRealPath(), $target);
        }
    }

    /**
     * Recursively remove a directory tree.
     */
    private function removeDir(string $dir): void
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }

    /**
     * Whether J2Store / J2Commerce 4 is the active shop, by the same rule the
     * plugin uses: com_j2commerce enabled with #__j2commerce_products wins;
     * otherwise com_j2store enabled with #__j2store_products.
     */
    private function isJ2StoreActiveShop(): bool
    {
        try {
            $db      = Factory::getContainer()->get(DatabaseInterface::class);
            $query   = $this->createDbQuery($db)
                ->select($db->quoteName('element'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('enabled') . ' = 1')
                ->whereIn($db->quoteName('element'), ['com_j2commerce', 'com_j2store'], ParameterType::STRING);
            $enabled = $db->setQuery($query)->loadColumn() ?: [];

            if (\in_array('com_j2commerce', $enabled, true) && $this->tableExists($db, 'j2commerce_products')) {
                return false;
            }

            return \in_array('com_j2store', $enabled, true) && $this->tableExists($db, 'j2store_products');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether a table exists (name without prefix). Mirrors the runtime detector:
     * SHOW TABLES LIKE avoids the stale getTableList() cache during installation.
     */
    private function tableExists(DatabaseInterface $db, string $table): bool
    {
        $like = $db->quote($db->escape($db->getPrefix() . $table, true), false);

        return !empty($db->setQuery('SHOW TABLES LIKE ' . $like)->loadResult());
    }

    /**
     * Whether this plugin is enabled in #__extensions. The group is j2commerce
     * on Joomla 6 and j2store on Joomla 5, so only type and element are matched.
     */
    private function isPluginEnabled(): bool
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $this->createDbQuery($db)
                ->select('MAX(' . $db->quoteName('enabled') . ')')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('productcompare'))
                ->whereIn($db->quoteName('folder'), ['j2commerce', 'j2store'], ParameterType::STRING);

            return (int) $db->setQuery($query)->loadResult() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function ensureUpdateSite(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $updateUrl = 'https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/j2commerce/plg_j2commerce_productcompare/updates/update.xml';
        $element = 'productcompare';

        // Resolve the actual installed folder (set by setGroupForInstalledStack)
        $q = $this->createDbQuery($db)
            ->select($db->quoteName('folder'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote($element))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));
        $db->setQuery($q);
        $folder = $db->loadResult() ?: 'j2commerce';

        $query = $this->createDbQuery($db)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = :element')
            ->where($db->quoteName('folder') . ' = :folder')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->bind(':element', $element)
            ->bind(':folder', $folder);
        $db->setQuery($query);
        $extensionId = (int) $db->loadResult();

        if (!$extensionId) {
            return;
        }

        $this->removeLegacyUpdateSites($db, $extensionId);

        $query = $this->createDbQuery($db)
            ->select($db->quoteName('update_site_id'))
            ->from($db->quoteName('#__update_sites'))
            ->where($db->quoteName('location') . ' = :url')
            ->bind(':url', $updateUrl);
        $db->setQuery($query);
        $siteId = (int) $db->loadResult();

        if ($siteId) {
            // On Joomla 5 an update briefly creates a second plugin row, and
            // Joomla links the update site to it after this script has run.
            // Links to rows that no longer exist are removed here.
            $existing = $this->createDbQuery($db)
                ->select($db->quoteName('e.extension_id'))
                ->from($db->quoteName('#__extensions', 'e'));
            $query = $this->createDbQuery($db)
                ->delete($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')
                ->where($db->quoteName('extension_id') . ' NOT IN (' . $existing . ')')
                ->bind(':siteId', $siteId, ParameterType::INTEGER);
            $db->setQuery($query);
            $db->execute();

            $query = $this->createDbQuery($db)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')
                ->where($db->quoteName('extension_id') . ' = :extId')
                ->bind(':siteId', $siteId, ParameterType::INTEGER)
                ->bind(':extId', $extensionId, ParameterType::INTEGER);
            $db->setQuery($query);
            if (!(int) $db->loadResult()) {
                $query = $this->createDbQuery($db)
                    ->insert($db->quoteName('#__update_sites_extensions'))
                    ->columns([$db->quoteName('update_site_id'), $db->quoteName('extension_id')])
                    ->values(':siteId, :extId')
                    ->bind(':siteId', $siteId, ParameterType::INTEGER)
                    ->bind(':extId', $extensionId, ParameterType::INTEGER);
                $db->setQuery($query);
                $db->execute();
            }
            return;
        }

        $name = 'J2Store Product Compare';
        $type = 'extension';
        $query = $this->createDbQuery($db)
            ->insert($db->quoteName('#__update_sites'))
            ->columns([$db->quoteName('name'), $db->quoteName('type'), $db->quoteName('location'), $db->quoteName('enabled')])
            ->values(':name, :type, :url, 1')
            ->bind(':name', $name)->bind(':type', $type)->bind(':url', $updateUrl);
        $db->setQuery($query);
        $db->execute();
        $siteId = (int) $db->insertid();

        $query = $this->createDbQuery($db)
            ->insert($db->quoteName('#__update_sites_extensions'))
            ->columns([$db->quoteName('update_site_id'), $db->quoteName('extension_id')])
            ->values(':siteId, :extId')
            ->bind(':siteId', $siteId, ParameterType::INTEGER)
            ->bind(':extId', $extensionId, ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Remove update sites of this extension that still point to the repository's
     * former organisation name. Joomla would otherwise keep querying both the
     * old and the new update URL.
     */
    private function removeLegacyUpdateSites(DatabaseInterface $db, int $extensionId): void
    {
        $legacyPattern = '%/advansit/Joomla/%';

        $query = $this->createDbQuery($db)
            ->select($db->quoteName('s.update_site_id'))
            ->from($db->quoteName('#__update_sites', 's'))
            ->join(
                'INNER',
                $db->quoteName('#__update_sites_extensions', 'map'),
                $db->quoteName('map.update_site_id') . ' = ' . $db->quoteName('s.update_site_id')
            )
            ->where($db->quoteName('map.extension_id') . ' = :extId')
            ->where($db->quoteName('s.location') . ' LIKE :legacy')
            ->bind(':extId', $extensionId, ParameterType::INTEGER)
            ->bind(':legacy', $legacyPattern);
        $siteIds = array_map('intval', $db->setQuery($query)->loadColumn() ?: []);

        foreach ($siteIds as $siteId) {
            $query = $this->createDbQuery($db)
                ->delete($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')
                ->where($db->quoteName('extension_id') . ' = :extId')
                ->bind(':siteId', $siteId, ParameterType::INTEGER)
                ->bind(':extId', $extensionId, ParameterType::INTEGER);
            $db->setQuery($query)->execute();

            $query = $this->createDbQuery($db)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')
                ->bind(':siteId', $siteId, ParameterType::INTEGER);

            if ((int) $db->setQuery($query)->loadResult() > 0) {
                continue;
            }

            foreach (['#__updates', '#__update_sites'] as $table) {
                $query = $this->createDbQuery($db)
                    ->delete($db->quoteName($table))
                    ->where($db->quoteName('update_site_id') . ' = :siteId')
                    ->bind(':siteId', $siteId, ParameterType::INTEGER);
                $db->setQuery($query)->execute();
            }
        }
    }

    /**
     * Creates a query object compatible with Joomla 5 (getQuery) and 6 (createQuery).
     */
    private function createDbQuery(\Joomla\Database\DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
