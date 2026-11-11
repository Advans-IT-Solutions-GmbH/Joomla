<?php
/**
 * Installer script for com_j2commerce_importexport
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

class Com_j2commerce_importexportInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    protected function extensionPostflight($type, $parent)
    {
        if ($type === 'install' || $type === 'update') {
            $this->ensureUpdateSite();
            $this->seedActionRules();

            $app = Factory::getApplication();
            $lang = $app->getLanguage();
            $lang->load('com_j2commerce_importexport', JPATH_ADMINISTRATOR);

            // Updates only get a short confirmation; the first installation shows
            // where the component is found.
            if ($type === 'update') {
                $manifest = method_exists($parent, 'getManifest') ? $parent->getManifest() : null;
                $version  = $manifest instanceof \SimpleXMLElement ? (string) $manifest->version : '';

                $app->enqueueMessage(
                    Text::sprintf('COM_J2COMMERCE_IMPORTEXPORT_POSTINSTALL_UPDATED', htmlspecialchars($version)),
                    'message'
                );

                return;
            }

            $sBox = 'padding:16px 20px;margin:16px 0;border-radius:4px;border-left:4px solid;background:#eff6ff;border-color:#2563eb';

            $message = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:860px">';
            $message .= '<h2 style="margin-bottom:16px">' . Text::_('COM_J2COMMERCE_IMPORTEXPORT_POSTINSTALL_TITLE') . '</h2>';
            $message .= '<div style="' . $sBox . '">';
            $message .= '<p>' . Text::_('COM_J2COMMERCE_IMPORTEXPORT_POSTINSTALL_DESC') . '</p>';
            $message .= '</div>';
            $message .= '<p style="margin-top:12px;color:#6b7280;font-size:13px">' . Text::_('COM_J2COMMERCE_IMPORTEXPORT_POSTINSTALL_DOCS') . '</p>';
            $message .= '</div>';

            $app->enqueueMessage($message, 'message');
        }
    }

    /**
     * Gives the new import and export actions to the groups that already may manage the
     * component, so an update does not lock out existing administrators.
     *
     * An action without a rule is denied for everyone except Super Users. Administrators
     * receive core.manage from the root asset, so this falls back to the Administrator group
     * when the component asset sets no core.manage rule. Existing rules are never changed.
     */
    private function seedActionRules(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $name = 'com_j2commerce_importexport';

        $query = $this->createDbQuery($db)
            ->select([$db->quoteName('id'), $db->quoteName('rules')])
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name') . ' = :name')
            ->bind(':name', $name);
        $asset = $db->setQuery($query)->loadObject();

        if (!$asset) {
            return;
        }

        $rules = json_decode((string) $asset->rules, true);
        $rules = is_array($rules) ? $rules : [];
        $manage = isset($rules['core.manage']) && is_array($rules['core.manage']) ? $rules['core.manage'] : ['7' => 1];
        $changed = false;

        foreach (['importexport.import', 'importexport.export'] as $action) {
            if (!array_key_exists($action, $rules)) {
                $rules[$action] = (object) $manage;
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        $json = json_encode((object) $rules);
        $assetId = (int) $asset->id;
        $query = $this->createDbQuery($db)
            ->update($db->quoteName('#__assets'))
            ->set($db->quoteName('rules') . ' = :rules')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':rules', $json)
            ->bind(':id', $assetId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    private function ensureUpdateSite(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $updateUrl = 'https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/j2commerce/com_j2commerce_importexport/updates/update.xml';
        $element = 'com_j2commerce_importexport';

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

        $name = 'J2Commerce Import/Export Updates';
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
