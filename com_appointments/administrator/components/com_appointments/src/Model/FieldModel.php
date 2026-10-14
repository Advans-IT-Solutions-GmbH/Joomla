<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;

defined('_JEXEC') or die;

class FieldModel extends AdminModel
{
    public function getTable($name = 'Field', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if ($item) {
            $item->options = implode("\n", json_decode((string) $item->options, true) ?: []);
        }

        return $item;
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_appointments.field', 'field', ['control' => 'jform', 'load_data' => $loadData]);

        if ($form && !$this->canEditState((object) $data)) {
            foreach (['published', 'ordering'] as $field) {
                $form->setFieldAttribute($field, 'disabled', 'true');
                $form->setFieldAttribute($field, 'filter', 'unset');
            }
        }

        return $form;
    }

    protected function loadFormData()
    {
        return Factory::getApplication()->getUserState('com_appointments.edit.field.data', []) ?: $this->getItem();
    }

    protected function canEditState($record)
    {
        return Factory::getApplication()->getIdentity()->authorise('core.edit.state', $this->asset($record));
    }

    protected function canDelete($record)
    {
        return Factory::getApplication()->getIdentity()->authorise('core.delete', $this->asset($record));
    }

    private function asset($record): string
    {
        $eventId = (int) ($record->eventtype_id ?? 0);

        if (!$eventId && !empty($record->id)) {
            $row = $this->getTable();
            $row->load((int) $record->id);
            $eventId = (int) $row->eventtype_id;
        }

        return $eventId > 0 ? 'com_appointments.eventtype.' . $eventId : 'com_appointments';
    }

    public function save($data)
    {
        $user = Factory::getApplication()->getIdentity();
        $table = $this->getTable();

        if (!empty($data['id']) && !$table->load((int) $data['id'])) {
            $this->setError(Text::_('JERROR_PAGE_NOT_FOUND'));

            return false;
        }

        if ((!empty($data['id']) && !$user->authorise('core.edit', $this->asset($table)))
            || !$user->authorise('core.edit', 'com_appointments.eventtype.' . (int) ($data['eventtype_id'] ?? 0))) {
            $this->setError(Text::_('JERROR_ALERTNOAUTHOR'));

            return false;
        }

        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName('id'))->from($db->quoteName('#__appointments_eventtypes'))
            ->where($db->quoteName('id') . ' = ' . (int) ($data['eventtype_id'] ?? 0));

        if (!$db->setQuery($query)->loadResult()) {
            $this->setError(Text::_('COM_APPOINTMENTS_FIELD_ERROR_REQUIRED'));

            return false;
        }

        $data['options'] = json_encode(
            array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($data['options'] ?? ''))), static fn ($v) => $v !== '')),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );

        if (!$this->canEditState((object) $data) || (!empty($data['id']) && !$this->canEditState($table))) {
            unset($data['published'], $data['ordering']);

            if (empty($data['id'])) {
                $data['published'] = 0;
            }
        }

        return parent::save($data);
    }
}
