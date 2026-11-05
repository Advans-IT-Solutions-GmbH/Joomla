<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

require_once __DIR__ . '/load.php';

class JFormFieldAdvanssystemcheck extends \Joomla\CMS\Form\FormField
{
    protected $type = 'Advanssystemcheck';

    protected function getInput()
    {
        $app = \Joomla\CMS\Factory::getApplication();

        if (!$app->isClient('administrator') || !$app->getIdentity()->authorise('core.manage', 'com_installer')) {
            return htmlspecialchars(\Joomla\CMS\Language\Text::_('JERROR_ALERTNOAUTHOR'), ENT_QUOTES, 'UTF-8');
        }

        $id = (string) $this->element['extension'];

        if ((string) $this->element['installedonly'] === '1' && \Advans\Installer\SystemCheck::extension($id) === null) {
            return '';
        }

        return \Advans\Installer\SystemCheck::render($id);
    }
}
