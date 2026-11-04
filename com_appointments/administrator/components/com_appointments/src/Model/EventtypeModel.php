<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Advans\Component\Appointments\Administrator\Service\HostStore;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Advans\Component\Appointments\Administrator\Helper\Conference\FixedRoomProvider;
use Advans\Component\Appointments\Administrator\Helper\Conference\ConferenceException;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;

defined('_JEXEC') or die;

class EventtypeModel extends AdminModel
{
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);
        if ($item) {
            $item->conference_params = $item->conference_params === null ? []
                : json_decode((string) $item->conference_params, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($item->conference_params)) {
                throw new \RuntimeException('The event conferencing settings are invalid.');
            }
            $item->busy_calendars = (int) $item->id > 0 ? $this->busyCalendars((int) $item->id) : [];
        }

        return $item;
    }

    /** @return int[] Further busy calendars of an event type. */
    private function busyCalendars(int $id): array
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName('calendar_id'))->from($db->quoteName('#__appointments_eventtype_calendars'))
            ->where($db->quoteName('eventtype_id') . ' = ' . $id)->order($db->quoteName('calendar_id'));

        return array_map('intval', $db->setQuery($query)->loadColumn());
    }

    private function teamEnabled(): bool
    {
        try {
            return TeamServices::fromApplication()->enabled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Internet calendars of the event type, checked against the host's access:
     * the target calendar must be one the host can write, a further busy
     * calendar one the host has any effective access to. A stored value the host
     * no longer reaches may stay unchanged (the booking page ignores it while
     * the access is missing) but is never accepted as a new value or for a new
     * host. Calendar ids that are not offered are refused, so a forged form
     * cannot link a calendar the host may not see.
     *
     * @return int[]|null The further busy calendars to store, or null to leave them as they are.
     *
     * @throws \DomainException When a calendar is not allowed.
     */
    private function calendarLinks(array &$data, int $id, int $oldHost, int $newHost): ?array
    {
        if (!$this->teamEnabled()) {
            unset($data['calendar_id'], $data['busy_calendars']);

            return null;
        }

        $target = max(0, (int) ($data['calendar_id'] ?? 0));
        $busy = array_values(array_unique(array_filter(array_map('intval', (array) ($data['busy_calendars'] ?? [])),
            static fn (int $calendar): bool => $calendar > 0 && $calendar !== $target)));
        unset($data['busy_calendars']);

        if (count($busy) > 20) {
            throw new \DomainException('Too many busy calendars.');
        }

        $storedTarget = 0;
        $storedBusy = [];

        if ($id > 0) {
            $db = $this->getDatabase();
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('calendar_id'))->from($db->quoteName('#__appointments_eventtypes'))
                ->where($db->quoteName('id') . ' = ' . $id);
            $storedTarget = (int) $db->setQuery($query)->loadResult();
            $storedBusy = $this->busyCalendars($id);
        }

        $keep = $id > 0 && $oldHost === $newHost;
        $writable = [];
        $visible = [];

        try {
            foreach (TeamServices::fromApplication()->grants()->calendarsFor($newHost) as $access) {
                $visible[$access->calendarId] = true;

                if ($access->role->canWrite()) {
                    $writable[$access->calendarId] = true;
                }
            }
        } catch (\Throwable $e) {
            // Without the grant service no calendar is reachable; only unchanged values pass.
        }

        if ($target > 0 && !isset($writable[$target]) && !($keep && $target === $storedTarget)) {
            throw new \DomainException('The target calendar is not writable for the host.');
        }

        foreach ($busy as $calendar) {
            if (!isset($visible[$calendar]) && !($keep && in_array($calendar, $storedBusy, true))) {
                throw new \DomainException('A busy calendar is not visible to the host.');
            }
        }

        // 0 is stored as NULL by the table; null itself would not be bound.
        $data['calendar_id'] = $target;

        return $busy;
    }

    /** @param int[] $calendars */
    private function storeBusyCalendars(int $id, array $calendars): void
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $db->setQuery($query->delete($db->quoteName('#__appointments_eventtype_calendars'))
            ->where($db->quoteName('eventtype_id') . ' = ' . $id))->execute();

        foreach ($calendars as $calendar) {
            $row = (object) ['eventtype_id' => $id, 'calendar_id' => (int) $calendar];
            $db->insertObject('#__appointments_eventtype_calendars', $row);
        }
    }
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

        if ($form && !$this->teamEnabled()) {
            $form->removeField('calendar_id');
            $form->removeField('busy_calendars');
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
        unset($data['feed_token'], $data['created_utc'], $data['modified_utc'], $data['asset_id']);
        $settings = $data['conference_params'] ?? [];
        $providers = array_merge(['', 'inherit', 'none', 'jitsi', 'generic', 'fixed'],
            Factory::getApplication()->bootComponent('com_appointments')->getConferenceManager()->getProviderNames());
        if (!is_array($settings) || !in_array($settings['conference_provider'] ?? '', $providers, true)) {
            $this->setError(Text::_('COM_APPOINTMENTS_CONFERENCE_CONFIG_INVALID'));

            return false;
        }
        $settings = array_intersect_key($settings, array_flip(['conference_provider', 'conference_fixed_url', 'conference_room_prefix']));
        if (!preg_match('/^[A-Za-z0-9_-]{0,64}$/D', (string) ($settings['conference_room_prefix'] ?? ''))) {
            $this->setError(Text::_('COM_APPOINTMENTS_CONFERENCE_CONFIG_INVALID'));

            return false;
        }
        if (!empty($settings['conference_fixed_url'])) {
            if (!is_string($settings['conference_fixed_url'])) {
                $this->setError(Text::_('COM_APPOINTMENTS_CONFERENCE_CONFIG_INVALID'));

                return false;
            }
            try {
                new FixedRoomProvider($settings['conference_fixed_url']);
            } catch (ConferenceException $e) {
                $this->setError(Text::_('COM_APPOINTMENTS_CONFERENCE_CONFIG_INVALID'));

                return false;
            }
        }
        $data['conference_params'] = json_encode($settings, JSON_THROW_ON_ERROR);
        $provider = $settings['conference_provider'] ?? '';
        $data['conference_provider'] = in_array($provider, ['jitsi', 'generic', 'fixed'], true) ? $provider : null;

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
        $id = (int) ($data['id'] ?? 0);
        $newHost = (int) ($data['host_user_id'] ?? 0);
        $oldHost = 0;
        if ($id > 0) {
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('host_user_id'))->from($db->quoteName('#__appointments_eventtypes'))
                ->where($db->quoteName('id') . ' = ' . $id);
            $oldHost = (int) $db->setQuery($query)->loadResult();
        }
        $db->transactionStart();

        try {
            $hosts = array_unique(array_filter([$oldHost, $newHost], static fn ($host) => $host > 0));
            sort($hosts);
            foreach ($hosts as $host) {
                if ($host === $newHost) {
                    (new HostStore($db))->ensure($newHost, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                }
                $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
                $query->select($db->quoteName('user_id'))->from($db->quoteName('#__appointments_hosts'))
                    ->where($db->quoteName('user_id') . ' = ' . $host);
                $db->setQuery((string) $query . ' FOR UPDATE')->loadResult();
            }
            if ($newHost < 1) {
                throw new \InvalidArgumentException('A host must be a Joomla user.');
            }
            if ($id > 0) {
                $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
                $query->select($db->quoteName('host_user_id'))->from($db->quoteName('#__appointments_eventtypes'))
                    ->where($db->quoteName('id') . ' = ' . $id);
                $currentHost = (int) $db->setQuery((string) $query . ' FOR UPDATE')->loadResult();
                if ($currentHost !== $oldHost) {
                    $db->transactionRollback();
                    $this->setError(Text::_('COM_APPOINTMENTS_EVENTTYPE_ERROR_RELOAD'));

                    return false;
                }
                if ($currentHost !== $newHost) {
                    $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
                    $query->select($db->quoteName('id'))->from($db->quoteName('#__appointments_bookings'))
                        ->where($db->quoteName('eventtype_id') . ' = ' . $id)->setLimit(1);
                    if ($db->setQuery((string) $query . ' FOR UPDATE')->loadResult()) {
                        $db->transactionRollback();
                        $this->setError(Text::_('COM_APPOINTMENTS_EVENTTYPE_ERROR_HOST_BOOKINGS'));

                        return false;
                    }
                }
            }

            try {
                $busyCalendars = $this->calendarLinks($data, $id, $oldHost, $newHost);
            } catch (\DomainException $e) {
                $db->transactionRollback();
                $this->setError(Text::_('COM_APPOINTMENTS_TEAM_E_EVENTTYPE_ERROR_CALENDAR'));

                return false;
            }

            if (!parent::save($data)) {
                $db->transactionRollback();

                return false;
            }

            if ($busyCalendars !== null) {
                $this->storeBusyCalendars((int) $this->getState($this->getName() . '.id'), $busyCalendars);
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
