<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Field;

use Advans\Component\Appointments\Administrator\Model\EventtypeModel;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * Internet calendar picker of the event type form. Lists only calendars that
 * both the event type's host and the editing user have an effective access to
 * (EventtypeModel::reachable()): with `mode="write"` those both can write
 * (target calendar), otherwise every one (further busy calendars). A stored
 * value outside that set stays visible, marked with its id only, so nothing is
 * dropped without notice; the model accepts it only unchanged, and the booking
 * page ignores it while the host's access is missing.
 */
class TeamcalendarField extends ListField
{
    protected $type = 'Teamcalendar';

    protected function getOptions()
    {
        $options = parent::getOptions();
        $seen = [];

        try {
            if (TeamServices::fromApplication()->enabled()) {
                [$visible, $writable] = EventtypeModel::reachable((int) $this->form->getValue('host_user_id'),
                    (int) Factory::getApplication()->getIdentity()->id);

                foreach ((string) $this->element['mode'] === 'write' ? $writable : $visible as $id => $title) {
                    $options[] = HTMLHelper::_('select.option', (string) $id, $title);
                    $seen[$id] = true;
                }
            }
        } catch (\Throwable $e) {
            // No calendar service: only the stored values below.
        }

        foreach ((array) $this->value as $value) {
            $id = (int) $value;

            if ($id > 0 && !isset($seen[$id])) {
                $options[] = HTMLHelper::_('select.option', (string) $id, Text::sprintf('COM_APPOINTMENTS_TEAM_E_EVENTTYPE_NO_ACCESS', $id));
                $seen[$id] = true;
            }
        }

        return $options;
    }
}
