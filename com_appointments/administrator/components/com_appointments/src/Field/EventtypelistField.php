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
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Event type picker of the "Book an appointment" menu item type.
 *
 * Lists the published event types, labelled with their title and, unless it applies to all
 * languages, their content language. The event type a menu item already points at stays in the
 * list after it has been unpublished, marked as such, so editing that menu item neither loses
 * nor silently changes its target. The stored request parameter remains `id`.
 */
class EventtypelistField extends ListField
{
    protected $type = 'Eventtypelist';

    protected function getOptions()
    {
        $options = parent::getOptions();
        $current = (int) $this->value;
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['id', 'title', 'language', 'published']))
            ->from($db->quoteName('#__appointments_eventtypes'))
            ->where('(' . $db->quoteName('published') . ' = 1 OR ' . $db->quoteName('id') . ' = :current)')
            ->bind(':current', $current, ParameterType::INTEGER)
            ->order($db->quoteName('title') . ' ASC')
            ->order($db->quoteName('id') . ' ASC');

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $label = (string) $row->title;

            if ((string) $row->language !== '*' && (string) $row->language !== '') {
                $label .= ' (' . $row->language . ')';
            }

            if ((int) $row->published !== 1) {
                $label .= ' [' . Text::_('JUNPUBLISHED') . ']';
            }

            $options[] = HTMLHelper::_('select.option', (int) $row->id, $label);
        }

        return $options;
    }
}
