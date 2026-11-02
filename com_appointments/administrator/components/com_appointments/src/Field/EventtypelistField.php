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
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $current = $this->storedEventtype($db);
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

    /**
     * The event type the saved menu item points at, read from its stored row, 0 for a new item.
     *
     * The submitted value cannot be trusted for this: Joomla binds the request values of a new
     * item from the posted link, so a forged link plus request id would otherwise add an
     * unpublished event type to the options and pass validation. The menu item id itself is held
     * by the menu manager's edit context (only an item opened for editing can be saved).
     */
    private function storedEventtype(DatabaseInterface $db): int
    {
        $itemId = $this->form ? (int) $this->form->getValue('id') : 0;

        if ($itemId <= 0) {
            return 0;
        }

        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName('link'))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('id') . ' = :item')
            ->where($db->quoteName('client_id') . ' = 0')
            ->bind(':item', $itemId, ParameterType::INTEGER);
        $link = (string) $db->setQuery($query)->loadResult();
        parse_str((string) parse_url($link, PHP_URL_QUERY), $vars);

        if (($vars['option'] ?? '') !== 'com_appointments' || ($vars['view'] ?? '') !== 'eventtype') {
            return 0;
        }

        return max(0, (int) ($vars['id'] ?? 0));
    }
}
