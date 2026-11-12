<?php
/**
 * Installer script for com_j2store_cleanup
 *
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
defined('_JEXEC') or die;

require_once is_file(__DIR__ . '/advansinstaller/load.php')
    ? __DIR__ . '/advansinstaller/load.php'
    : dirname(__DIR__, 2) . '/shared/installer/load.php';

use Joomla\CMS\Factory;
use Advans\Installer\InstallerScript;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class Com_j2store_cleanupInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    public function preflight($type, $parent)
    {
        if (!parent::preflight($type, $parent)) {
            return false;
        }

        if ($type === 'install' || $type === 'update') {
            $this->removeStaleAdminMenus();
        }

        return true;
    }

    /** The option of this component and the one an earlier build of it used. */
    private const MENU_LINKS = ['index.php?option=com_j2store_cleanup', 'index.php?option=com_j2commerce_cleanup'];

    /**
     * Joomla rebuilds the administrator menu item of the component on every installation and
     * update, but removes only items that belong to the installed component. An item left by an
     * earlier installation keeps the alias, and the new item then fails with
     * «JLIB_DATABASE_ERROR_MENU_UNIQUE_ALIAS», which leaves the component without its menu item.
     * Removed are only orphaned items of the «main» administrator menu: their component id belongs
     * to no installed extension, and their alias or path is this component's, or their link opens
     * this component or its earlier option. Valid items are never touched; a failure leaves
     * Joomla's message and the check in postflight.
     */
    private function removeStaleAdminMenus(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $this->createDbQuery($db)
                ->select($db->quoteName(['m.id']))
                ->from($db->quoteName('#__menu', 'm'))
                ->join('LEFT', $db->quoteName('#__extensions', 'e'), $db->quoteName('e.extension_id') . ' = ' . $db->quoteName('m.component_id'))
                ->where($db->quoteName('m.client_id') . ' = 1')
                ->where($db->quoteName('m.menutype') . ' = ' . $db->quote('main'))
                ->where($db->quoteName('e.extension_id') . ' IS NULL');
            $own = [
                $db->quoteName('m.alias') . ' = ' . $db->quote('com_j2store_cleanup'),
                $db->quoteName('m.alias') . ' = ' . $db->quote('com-j2store-cleanup'),
                $db->quoteName('m.path') . ' = ' . $db->quote('com_j2store_cleanup'),
                $db->quoteName('m.path') . ' = ' . $db->quote('com-j2store-cleanup'),
            ];

            foreach (self::MENU_LINKS as $link) {
                $own[] = $db->quoteName('m.link') . ' = ' . $db->quote($link);
                $own[] = $db->quoteName('m.link') . ' LIKE ' . $db->quote($link . '&%');
            }

            $query->where('(' . implode(' OR ', $own) . ')')->order($db->quoteName('m.lft') . ' DESC');

            foreach ($db->setQuery($query)->loadColumn() ?: [] as $id) {
                $table = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createTable('Menu', 'Administrator');

                // An item already gone with its parent is fine.
                if ($table && $table->load((int) $id) && !$table->delete((int) $id)) {
                    throw new \RuntimeException('An orphaned administrator menu item could not be removed.');
                }
            }
        } catch (\Throwable $e) {
            // Joomla still reports a remaining conflict; postflight creates a missing item.
        }
    }

    /**
     * The component's own item in the «Components» menu of the administrator. Joomla creates it,
     * unless a conflict stopped it or an earlier installation left the component without one:
     * then it is created here, as Joomla would (title, alias and link from the manifest).
     */
    private function ensureAdminMenu($parent): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $this->createDbQuery($db)
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_j2store_cleanup'))
                ->where($db->quoteName('client_id') . ' = 1');
            $extensionId = (int) $db->setQuery($query)->loadResult();

            if ($extensionId < 1) {
                return;
            }

            $query = $this->createDbQuery($db)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('client_id') . ' = 1')
                ->where($db->quoteName('menutype') . ' = ' . $db->quote('main'))
                ->where($db->quoteName('parent_id') . ' = 1')
                ->where($db->quoteName('component_id') . ' = ' . $extensionId);

            if ((int) $db->setQuery($query)->loadResult() > 0) {
                return;
            }

            $manifest = method_exists($parent, 'getManifest') ? $parent->getManifest() : null;
            $title = $manifest instanceof \SimpleXMLElement && isset($manifest->administration->menu)
                ? trim((string) $manifest->administration->menu) : 'COM_J2STORE_CLEANUP';
            $table = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createTable('Menu', 'Administrator');
            $table->setLocation(1, 'last-child');
            $data = [
                'menutype' => 'main',
                'client_id' => 1,
                'title' => $title !== '' ? $title : 'COM_J2STORE_CLEANUP',
                'alias' => 'com_j2store_cleanup',
                'type' => 'component',
                'published' => 1,
                'parent_id' => 1,
                'component_id' => $extensionId,
                'img' => 'class:component',
                'home' => 0,
                'link' => 'index.php?option=com_j2store_cleanup',
                'language' => '*',
                'params' => '{}',
            ];

            if (!$table->bind($data) || !$table->check() || !$table->store()) {
                throw new \RuntimeException('The administrator menu item could not be created.');
            }

            $table->rebuildPath($table->id);
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_J2STORE_CLEANUP_MENU_FAILED'), 'warning');
        }
    }
    protected function extensionPostflight($type, $parent)
    {
        if ($type === 'install' || $type === 'update') {
            $this->ensureUpdateSite();
            $this->ensureAdminMenu($parent);

            $app = Factory::getApplication();
            $lang = $app->getLanguage();
            $lang->load('com_j2store_cleanup', JPATH_ADMINISTRATOR);

            // Updates only get a short confirmation; the first installation shows
            // where the component is found.
            if ($type === 'update') {
                $manifest = method_exists($parent, 'getManifest') ? $parent->getManifest() : null;
                $version  = $manifest instanceof \SimpleXMLElement ? (string) $manifest->version : '';

                $app->enqueueMessage(
                    Text::sprintf('COM_J2STORE_CLEANUP_POSTINSTALL_UPDATED', htmlspecialchars($version)),
                    'message'
                );

                return;
            }

            $sBox = 'padding:16px 20px;margin:16px 0;border-radius:4px;border-left:4px solid;background:#eff6ff;border-color:#2563eb';

            $message = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:860px">';
            $message .= '<h2 style="margin-bottom:16px">' . Text::_('COM_J2STORE_CLEANUP_POSTINSTALL_TITLE') . '</h2>';
            $message .= '<div style="' . $sBox . '">';
            $message .= '<p>' . Text::_('COM_J2STORE_CLEANUP_POSTINSTALL_DESC') . '</p>';
            $message .= '</div>';
            $message .= '<p style="margin-top:12px;color:#6b7280;font-size:13px">' . Text::_('COM_J2STORE_CLEANUP_POSTINSTALL_DOCS') . '</p>';
            $message .= '</div>';

            $app->enqueueMessage($message, 'message');
        }
    }

    private function ensureUpdateSite(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $updateUrl = 'https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/j2commerce/com_j2store_cleanup/updates/update.xml';
        $element = 'com_j2store_cleanup';

        $query = $this->createDbQuery($db)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = :element')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->bind(':element', $element);
        $db->setQuery($query);
        $extensionId = (int) $db->loadResult();

        if (!$extensionId) { return; }

        $this->removeLegacyUpdateSites($db, $extensionId);

        $query = $this->createDbQuery($db)
            ->select($db->quoteName('update_site_id'))
            ->from($db->quoteName('#__update_sites'))
            ->where($db->quoteName('location') . ' = :url')
            ->bind(':url', $updateUrl);
        $db->setQuery($query);
        $siteId = (int) $db->loadResult();

        if ($siteId) {
            $query = $this->createDbQuery($db)->select('COUNT(*)')->from($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')->where($db->quoteName('extension_id') . ' = :extId')
                ->bind(':siteId', $siteId, ParameterType::INTEGER)->bind(':extId', $extensionId, ParameterType::INTEGER);
            $db->setQuery($query);
            if (!(int) $db->loadResult()) {
                $query = $this->createDbQuery($db)->insert($db->quoteName('#__update_sites_extensions'))
                    ->columns([$db->quoteName('update_site_id'), $db->quoteName('extension_id')])
                    ->values(':siteId, :extId')
                    ->bind(':siteId', $siteId, ParameterType::INTEGER)->bind(':extId', $extensionId, ParameterType::INTEGER);
                $db->setQuery($query);
                $db->execute();
            }
            return;
        }

        $name = 'J2Store Cleanup';
        $sType = 'extension';
        $query = $this->createDbQuery($db)->insert($db->quoteName('#__update_sites'))
            ->columns([$db->quoteName('name'), $db->quoteName('type'), $db->quoteName('location'), $db->quoteName('enabled')])
            ->values(':name, :type, :url, 1')
            ->bind(':name', $name)->bind(':type', $sType)->bind(':url', $updateUrl);
        $db->setQuery($query);
        $db->execute();
        $siteId = (int) $db->insertid();

        $query = $this->createDbQuery($db)->insert($db->quoteName('#__update_sites_extensions'))
            ->columns([$db->quoteName('update_site_id'), $db->quoteName('extension_id')])
            ->values(':siteId, :extId')
            ->bind(':siteId', $siteId, ParameterType::INTEGER)->bind(':extId', $extensionId, ParameterType::INTEGER);
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
