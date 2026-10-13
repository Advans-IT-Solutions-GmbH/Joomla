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

class PlgSystemDmarcmtastsInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    public function postflight(string $type, object $parent): void
    {
        if ($type !== 'install') {
            return;
        }

        $this->enablePlugin();
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
}
