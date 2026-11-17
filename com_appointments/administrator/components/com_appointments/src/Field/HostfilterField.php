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
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * The hosts that occur in a list, for its host filter: only users who have a blocked period
 * (`source="blocked"`) or own a schedule (`source="schedules"`). These names are shown in the
 * list itself already, so the filter reveals nobody the list does not show.
 *
 * @since  1.6.0
 */
class HostfilterField extends ListField
{
    protected $type = 'Hostfilter';

    /** Source => [table, user column]. A fixed map, never a name taken from the form. */
    private const SOURCES = [
        'blocked'   => ['#__appointments_blocked', 'user_id'],
        'schedules' => ['#__appointments_schedules', 'owner_user_id'],
    ];

    protected function getOptions()
    {
        $options = parent::getOptions();
        $source = self::SOURCES[(string) $this->element['source']] ?? null;

        if ($source === null) {
            return $options;
        }

        [$table, $column] = $source;
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select('DISTINCT ' . $db->quoteName('u.id', 'value') . ', ' . $db->quoteName('u.name', 'text'))
            ->from($db->quoteName($table, 'x'))
            ->join('INNER', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('x.' . $column))
            ->order($db->quoteName('text') . ' ASC')
            ->order($db->quoteName('value') . ' ASC');

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $options[] = HTMLHelper::_('select.option', (int) $row->value, (string) $row->text);
        }

        return $options;
    }
}
