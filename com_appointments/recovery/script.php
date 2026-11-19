<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

class Com_appointmentsrecoveryInstallerScript extends \Joomla\CMS\Installer\InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    public function preflight($type, $parent)
    {
        return parent::preflight($type, $parent);
    }
}
