<?php
/**
 * @package     DMARC Reports Ingestion Task Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;

class PlgTaskDmarcreportsInstallerScript extends InstallerScript
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
     * Publish the plugin after a fresh install so the scheduler task type is
     * selectable immediately. Updates keep the current published state.
     */
    private function enablePlugin(): void
    {
        try {
            $db    = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('task'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('dmarcreports'))
                ->where($db->quoteName('enabled') . ' = 0');
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // A failed auto-enable is not fatal; the admin can enable it manually.
        }
    }
}
