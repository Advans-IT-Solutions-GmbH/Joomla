<?php
/**
 * @package     DMARC Reports Package
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerScript;

/**
 * Installer script for the DMARC Reports package.
 *
 * The package bundles the component, the dashboard module, the ingestion task
 * plugin and the MTA-STS system plugin so the administrator installs, updates
 * and removes the whole DMARC solution in one step. Each bundled extension
 * ships its own installer script that handles its own tables and enablement;
 * this script only enforces the shared minimum platform requirements.
 */
class Pkg_DmarcreportsInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';
}
