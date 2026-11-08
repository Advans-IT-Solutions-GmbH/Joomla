<?php
/**
 * @package     DMARC Reports Dashboard Module
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Module\DmarcReports\Administrator\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\RadioField;

defined('_JEXEC') or die;

/**
 * The core summary switcher, with assets scoped to this module's editor.
 */
class SummaryField extends RadioField
{
    protected function getInput()
    {
        Factory::getApplication()->getDocument()->getWebAssetManager()->registerAndUseStyle(
            'mod_dmarcreports.admin',
            'mod_dmarcreports/admin.css',
            ['version' => 'auto'],
            [],
            ['webcomponent.joomla-tab']
        );

        return parent::getInput();
    }
}
