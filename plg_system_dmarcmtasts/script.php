<?php
/**
 * @package     DMARC MTA-STS Policy System Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;

class PlgSystemDmarcmtastsInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    public function postflight(string $type, object $parent): void
    {
        if ($type === 'install') {
            $this->enablePlugin();

            return;
        }

        if ($type !== 'update') {
            return;
        }

        // An update preserves the published state, so the only step that can
        // still be open is enabling the plugin. Point the admin at it while it
        // is disabled; stay silent when it is already enabled.
        if ($this->isPluginEnabled()) {
            return;
        }

        $app  = Factory::getApplication();
        $lang = $app->getLanguage();
        $lang->load('plg_system_dmarcmtasts', JPATH_ADMINISTRATOR);
        $lang->load('plg_system_dmarcmtasts', $parent->getParent()->getPath('source'));

        $app->enqueueMessage(Text::_('PLG_SYSTEM_DMARCMTASTS_ENABLE_HINT'), 'message');
    }

    /**
     * Publish the plugin after a fresh install so the policy endpoint works as
     * soon as MTA-STS is enabled in the component. Updates keep the current state.
     */
    private function enablePlugin(): void
    {
        try {
            $db    = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('dmarcmtasts'))
                ->where($db->quoteName('enabled') . ' = 0');
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // A failed auto-enable is not fatal; the admin can enable it manually.
        }
    }

    /**
     * Whether this plugin is currently enabled in #__extensions. On any error
     * it reports enabled so the update stays quiet instead of nagging.
     */
    private function isPluginEnabled(): bool
    {
        try {
            $db    = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('enabled'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('dmarcmtasts'));

            return (int) $db->setQuery($query)->loadResult() === 1;
        } catch (\Throwable $e) {
            return true;
        }
    }
}
