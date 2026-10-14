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

class TemplateModel extends AdminModel
{
    public function getTable($name = 'Template', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        // A new form must not select the sentinel scope just because of its SQL default.
        if ($item && empty($item->id)) {
            $item->eventtype_id = '';
        }

        return $item;
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_appointments.template', 'template', ['control' => 'jform', 'load_data' => $loadData]);

        $knownScope = isset($data['eventtype_id']) && $data['eventtype_id'] !== '';

        if ($form && (!empty($data['id']) || $knownScope) && !$this->canEditState((object) $data)) {
            $form->setFieldAttribute('published', 'disabled', 'true');
            $form->setFieldAttribute('published', 'filter', 'unset');
        }

        return $form;
    }

    protected function loadFormData()
    {
        return Factory::getApplication()->getUserState('com_appointments.edit.template.data', []) ?: $this->getItem();
    }

    public function validate($form, $data, $group = null)
    {
        // Check before the integer filter turns an absent/empty scope into site-wide.
        if (!$this->hasScope($data)) {
            $this->setError(Text::_('COM_APPOINTMENTS_TEMPLATE_ERROR_SCOPE'));

            return false;
        }

        return parent::validate($form, $data, $group);
    }

    protected function canEditState($record)
    {
        return $this->canAct($record, 'core.edit.state');
    }

    protected function canDelete($record)
    {
        return $this->canAct($record, 'core.delete');
    }

    private function canAct($record, string $action): bool
    {
        if (!isset($record->eventtype_id) && !empty($record->id)) {
            $table = $this->getTable();

            if (!$table->load((int) $record->id)) {
                return false;
            }

            $record = $table;
        }

        if (!isset($record->eventtype_id) || $record->eventtype_id === '') {
            return false;
        }

        $user = Factory::getApplication()->getIdentity();
        $eventId = (int) $record->eventtype_id;

        if ($eventId > 0) {
            return $user->authorise($action, 'com_appointments.eventtype.' . $eventId);
        }

        $options = $user->authorise('core.options', 'com_appointments') || $user->authorise('core.admin', 'com_appointments');

        return $options && ($action === 'core.edit' || $user->authorise($action, 'com_appointments'));
    }

    private function hasScope(array $data): bool
    {
        return array_key_exists('eventtype_id', $data)
            && (is_int($data['eventtype_id']) || is_string($data['eventtype_id']))
            && preg_match('/^(0|[1-9][0-9]*)$/D', (string) $data['eventtype_id']) === 1;
    }

    public function save($data)
    {
        if (!$this->hasScope($data)) {
            $this->setError(Text::_('COM_APPOINTMENTS_TEMPLATE_ERROR_SCOPE'));

            return false;
        }

        $user = Factory::getApplication()->getIdentity();
        $table = $this->getTable();
        $existing = !empty($data['id']);

        if ($existing && !$table->load((int) $data['id'])) {
            $this->setError(Text::_('JERROR_PAGE_NOT_FOUND'));

            return false;
        }

        if ((!$existing && !$user->authorise('core.create', 'com_appointments'))
            || ($existing && !$this->canAct($table, 'core.edit'))
            || !$this->canAct((object) $data, 'core.edit')) {
            $this->setError(Text::_('JERROR_ALERTNOAUTHOR'));

            return false;
        }

        $eventId = (int) $data['eventtype_id'];

        if ($eventId > 0) {
            $db = $this->getDatabase();
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('id'))->from($db->quoteName('#__appointments_eventtypes'))
                ->where($db->quoteName('id') . ' = ' . $eventId);

            if (!$db->setQuery($query)->loadResult()) {
                $this->setError(Text::_('COM_APPOINTMENTS_TEMPLATE_ERROR_SCOPE'));

                return false;
            }
        }

        if (!$this->canEditState((object) $data) || ($existing && !$this->canEditState($table))) {
            unset($data['published']);

            if (!$existing) {
                $data['published'] = 0;
            }
        }

        // Checkout is controlled only by Joomla's held edit session.
        unset($data['checked_out'], $data['checked_out_time']);

        return parent::save($data);
    }
}
