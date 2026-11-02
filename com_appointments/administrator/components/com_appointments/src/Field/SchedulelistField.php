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
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Schedule picker of the event type form. Lists every schedule by title, an unpublished one
 * marked as such, because an event type may keep using a schedule that is unpublished.
 */
class SchedulelistField extends ListField
{
    protected $type = 'Schedulelist';

    protected function getOptions()
    {
        $options = parent::getOptions();
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['id', 'title', 'published']))
            ->from($db->quoteName('#__appointments_schedules'))
            ->order($db->quoteName('title') . ' ASC')
            ->order($db->quoteName('id') . ' ASC');

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $label = (string) $row->title;

            if ((int) $row->published !== 1) {
                $label .= ' [' . Text::_('JUNPUBLISHED') . ']';
            }

            $options[] = HTMLHelper::_('select.option', (int) $row->id, $label);
        }

        return $options;
    }
}
