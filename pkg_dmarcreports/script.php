<?php
/**
 * @package     DMARC Reports Package
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;

/**
 * Installer script for the DMARC Reports package.
 *
 * The package bundles the component, the dashboard module, the ingestion task
 * plugin and the MTA-STS system plugin so the administrator installs, updates
 * and removes the whole DMARC solution in one step. Each bundled extension
 * ships its own installer script that handles its own tables and enablement;
 * this script enforces the shared minimum platform requirements and prints a
 * short confirmation from the package language file.
 */
class Pkg_DmarcreportsInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    public function postflight(string $type, object $parent): void
    {
        if ($type !== 'install' && $type !== 'update') {
            return;
        }

        $app  = Factory::getApplication();
        $lang = $app->getLanguage();
        $lang->load('pkg_dmarcreports', JPATH_ADMINISTRATOR);
        $lang->load('pkg_dmarcreports', $parent->getParent()->getPath('source'));

        // An update only confirms the new version; the first installation shows
        // the short orientation message that points at the next steps.
        if ($type === 'update') {
            $manifest = method_exists($parent, 'getManifest') ? $parent->getManifest() : null;
            $version  = $manifest instanceof \SimpleXMLElement ? (string) $manifest->version : '';

            $app->enqueueMessage(
                Text::sprintf('PKG_DMARCREPORTS_POSTINSTALL_UPDATED', htmlspecialchars($version)),
                'message'
            );

            return;
        }

        $app->enqueueMessage(Text::_('PKG_DMARCREPORTS_POSTINSTALL_INTRO'), 'message');
    }
}
