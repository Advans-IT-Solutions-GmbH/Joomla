<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;

defined('_JEXEC') or die;

class ConferenceproviderField extends ListField
{
    protected $type = 'Conferenceprovider';

    protected function getOptions()
    {
        $options = parent::getOptions();
        $existing = array_map(static fn ($option) => (string) $option->value, $options);
        $names = Factory::getApplication()->bootComponent('com_appointments')->getConferenceManager()->getProviderNames();
        foreach ($names as $name) {
            if (!in_array($name, $existing, true)) {
                $options[] = HTMLHelper::_('select.option', $name, $name);
            }
        }

        return $options;
    }
}
