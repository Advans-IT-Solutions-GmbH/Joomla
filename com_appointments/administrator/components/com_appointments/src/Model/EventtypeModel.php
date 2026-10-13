<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Advans\Component\Appointments\Administrator\Service\HostStore;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;

defined('_JEXEC') or die;

class EventtypeModel extends AdminModel
{
    public function getTable($name = 'Eventtype', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_appointments.eventtype', 'eventtype', ['control' => 'jform', 'load_data' => $loadData]);

        if ($form && !$this->canEditState((object) $data)) {
            $form->setFieldAttribute('published', 'disabled', 'true');
            $form->setFieldAttribute('published', 'filter', 'unset');
            $form->setFieldAttribute('ordering', 'disabled', 'true');
            $form->setFieldAttribute('ordering', 'filter', 'unset');
        }

        if ($form && !Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_appointments')) {
            $form->setFieldAttribute('rules', 'filter', 'unset');
        }

        return $form;
    }

    protected function loadFormData()
    {
        return Factory::getApplication()->getUserState('com_appointments.edit.eventtype.data', []) ?: $this->getItem();
    }

    protected function canEditState($record)
    {
        $id = (int) ($record->id ?? $this->getState('eventtype.id'));

        return Factory::getApplication()->getIdentity()->authorise(
            'core.edit.state', $id > 0 ? 'com_appointments.eventtype.' . $id : 'com_appointments'
        );
    }

    protected function canDelete($record)
    {
        return Factory::getApplication()->getIdentity()->authorise('core.delete', 'com_appointments.eventtype.' . (int) $record->id);
    }

    public function save($data)
    {
        unset($data['feed_token'], $data['created_utc'], $data['modified_utc'], $data['asset_id'], $data['conference_params']);

        if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_appointments')) {
            unset($data['rules']);
        }

        if (!$this->canEditState((object) $data)) {
            unset($data['published'], $data['ordering']);

            if (empty($data['id'])) {
                $data['published'] = 0;
            }
        }

        $data['retention_days'] = ($data['retention_days'] ?? '') === '' ? null : (int) $data['retention_days'];
        $db = $this->getDatabase();
        $db->transactionStart();

        try {
            (new HostStore($db))->ensure((int) ($data['host_user_id'] ?? 0), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            if (!parent::save($data)) {
                $db->transactionRollback();

                return false;
            }

            $db->transactionCommit();
        } catch (\InvalidArgumentException $e) {
            $db->transactionRollback();
            $this->setError(Text::_('COM_APPOINTMENTS_EVENTTYPE_ERROR_REQUIRED'));

            return false;
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }

        return true;
    }

    public function delete(&$pks)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $pks), static fn ($id) => $id > 0));

        if ($ids === []) {
            return parent::delete($pks);
        }

        $db = $this->getDatabase();
        $db->transactionStart();

        try {
            sort($ids);
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('id'))->from($db->quoteName('#__appointments_eventtypes'))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')')->order($db->quoteName('id'));
            $db->setQuery((string) $query . ' FOR UPDATE')->loadColumn();
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('id'))->from($db->quoteName('#__appointments_bookings'))
                ->where($db->quoteName('eventtype_id') . ' IN (' . implode(',', $ids) . ')');

            if ($db->setQuery($query)->loadResult()) {
                $db->transactionRollback();
                $this->setError(Text::_('COM_APPOINTMENTS_EVENTTYPE_ERROR_IN_USE'));

                return false;
            }

            if (!parent::delete($pks)) {
                $db->transactionRollback();

                return false;
            }

            // Only delete children of event types that the ACL permitted deleting.
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->delete($db->quoteName('#__appointments_fields'))
                ->where($db->quoteName('eventtype_id') . ' IN (' . implode(',', array_map('intval', $pks)) . ')');
            $db->setQuery($query)->execute();
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }

        return true;
    }
}
