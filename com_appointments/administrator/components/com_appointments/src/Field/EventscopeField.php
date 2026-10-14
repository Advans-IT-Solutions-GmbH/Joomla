<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Field;

use Advans\Component\Appointments\Administrator\Helper\EventScope;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

class EventscopeField extends ListField
{
    protected $type = 'Eventscope';

    protected function getOptions()
    {
        $user = Factory::getApplication()->getIdentity();
        $options = parent::getOptions();
        if ((string) $this->element['sitewide'] === 'true'
            && ($user->authorise('core.options', 'com_appointments') || $user->authorise('core.admin', 'com_appointments'))) {
            $options[] = HTMLHelper::_('select.option', 0, Text::_('COM_APPOINTMENTS_TEMPLATE_SITE_WIDE'));
        }
        foreach (EventScope::events(Factory::getContainer()->get(DatabaseInterface::class), $user) as $event) {
            $options[] = HTMLHelper::_('select.option', $event['id'], $event['title']);
        }

        return $options;
    }
}
