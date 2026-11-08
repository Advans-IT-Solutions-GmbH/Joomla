<?php
/**
 * @package     Advans.Plugin
 * @subpackage  Ajax.JoomlaAjaxForms
 *
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

require_once is_file(__DIR__ . '/advansinstaller/load.php')
    ? __DIR__ . '/advansinstaller/load.php'
    : dirname(__DIR__) . '/shared/installer/load.php';

use Joomla\CMS\Factory;
use Advans\Installer\InstallerScript;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class PlgAjaxJoomlaajaxformsInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp    = '8.1';

    protected function extensionPostflight(string $type, object $parent): void
    {
        if ($type !== 'install' && $type !== 'update') {
            return;
        }

        $language = Factory::getApplication()->getLanguage();
        $language->load('plg_ajax_joomlaajaxforms', JPATH_ADMINISTRATOR);
        $language->load('plg_ajax_joomlaajaxforms', JPATH_PLUGINS . '/ajax/joomlaajaxforms');

        $this->removeLegacyUpdateSites();
        $this->checkHtaccess();

        // Joomla installs plugins disabled and an update keeps the state. The
        // only setup step is enabling the plugin, so it is mentioned only while
        // the plugin is still disabled.
        if (!$this->isPluginEnabled()) {
            Factory::getApplication()->enqueueMessage(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_ENABLE_HINT'), 'message');
        }
    }

    /**
     * Whether this plugin is enabled in #__extensions.
     */
    private function isPluginEnabled(): bool
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('enabled'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('ajax'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('joomlaajaxforms'));

            return (int) $db->setQuery($query)->loadResult() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if .htaccess allows com_ajax requests.
     *
     * If the server uses rewrite rules that block /component/ or
     * index.php?option=com_* URLs, com_ajax must be whitelisted.
     * This check is generic and works on any Joomla site.
     */
    private function checkHtaccess(): void
    {
        $htaccess = JPATH_ROOT . '/.htaccess';

        if (!file_exists($htaccess)) {
            return;
        }

        $content = file_get_contents($htaccess);

        if ($content === false) {
            return;
        }

        $issues = [];

        foreach ($this->analyseHtaccess($content) as $issue) {
            $issues[] = Text::_(
                $issue === 'component'
                    ? 'PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_COMPONENT_BLOCKED'
                    : 'PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_OPTION_BLOCKED'
            );
        }

        if (empty($issues)) {
            return;
        }

        $msg  = '<strong>' . Text::_('PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_WARNING_TITLE') . '</strong><ul>';
        foreach ($issues as $issue) {
            $msg .= '<li>' . $issue . '</li>';
        }
        $msg .= '</ul>';
        $msg .= '<p>' . Text::_('PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_WARNING_ACTION') . '</p>';

        Factory::getApplication()->enqueueMessage($msg, 'warning');
    }

    private function analyseHtaccess(string $content): array
    {
        return (new \Advans\Installer\RoutingCheck())->analyse($content);
    }

    /**
     * Remove update sites of this plugin that still point to the repository's
     * former organisation name. The current update server is registered by
     * Joomla from the manifest.
     */
    private function removeLegacyUpdateSites(): void
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $this->dbQuery($db)
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('ajax'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('joomlaajaxforms'));
            $extensionId = (int) $db->setQuery($query)->loadResult();

            if ($extensionId === 0) {
                return;
            }

            $legacyPattern = '%/advansit/Joomla/%';

            $query = $this->dbQuery($db)
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
                $query = $this->dbQuery($db)
                    ->delete($db->quoteName('#__update_sites_extensions'))
                    ->where($db->quoteName('update_site_id') . ' = :siteId')
                    ->where($db->quoteName('extension_id') . ' = :extId')
                    ->bind(':siteId', $siteId, ParameterType::INTEGER)
                    ->bind(':extId', $extensionId, ParameterType::INTEGER);
                $db->setQuery($query)->execute();

                $query = $this->dbQuery($db)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__update_sites_extensions'))
                    ->where($db->quoteName('update_site_id') . ' = :siteId')
                    ->bind(':siteId', $siteId, ParameterType::INTEGER);

                if ((int) $db->setQuery($query)->loadResult() > 0) {
                    continue;
                }

                foreach (['#__updates', '#__update_sites'] as $table) {
                    $query = $this->dbQuery($db)
                        ->delete($db->quoteName($table))
                        ->where($db->quoteName('update_site_id') . ' = :siteId')
                        ->bind(':siteId', $siteId, ParameterType::INTEGER);
                    $db->setQuery($query)->execute();
                }
            }
        } catch (\Throwable $e) {
            // Cleaning up outdated update sites must never block the installation.
        }
    }

    /**
     * Returns a fresh query object compatible with Joomla 5 and 6.
     */
    private function dbQuery(DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
