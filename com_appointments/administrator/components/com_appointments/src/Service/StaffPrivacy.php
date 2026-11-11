<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * The data of a staff account outside the bookings and the internet calendars, for Joomla's
 * privacy requests: absences, the host feed record, the event types the account hosts and the
 * schedules it owns. Bound to the account Joomla found for the request, never to an address,
 * and independent of whether the internet calendars are on.
 *
 * Removal follows Joomla's core privacy plugins: the account itself is pseudonymised by the user
 * plugin, so references to its id stay. What the component stores about the person goes:
 * absences that are over are deleted; current and future absences keep their period, which the
 * slot calculation of the event types still needs, and lose their reason (the title, which may
 * hold health data). The host record with the feed credential is deleted. Event types and
 * schedules are team configuration: they are never changed automatically, their titles are
 * returned so the administrator can review them (host, name, photo, role on the booking page).
 */
final class StaffPrivacy
{
    public const ABSENCE_FIELDS = ['id', 'title', 'start_utc', 'end_utc', 'created_utc'];
    public const HOST_FIELDS = ['user_id', 'created_utc', 'modified_utc'];
    public const EVENTTYPE_FIELDS = ['id', 'title', 'info_layout', 'info_name', 'info_role', 'info_photo'];
    public const SCHEDULE_FIELDS = ['id', 'title', 'timezone', 'created_utc'];

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * The rows per export domain, without any credential (the host feed credential is never read).
     *
     * @return  array<string, list<array<string, mixed>>>  Domain name => rows.
     */
    public function export(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return [
            'appointments_staff_absences'   => $this->rows('#__appointments_blocked', self::ABSENCE_FIELDS, 'user_id', $userId, 'start_utc'),
            'appointments_staff_host'       => $this->rows('#__appointments_hosts', self::HOST_FIELDS, 'user_id', $userId, 'user_id'),
            'appointments_staff_event_types' => $this->rows('#__appointments_eventtypes', self::EVENTTYPE_FIELDS, 'host_user_id', $userId, 'id'),
            'appointments_staff_schedules'  => $this->rows('#__appointments_schedules', self::SCHEDULE_FIELDS, 'owner_user_id', $userId, 'id'),
        ];
    }

    /**
     * @return  array{absences_deleted: int, absences_cleared: int, host_removed: int, event_types: list<string>, schedules: list<string>}
     */
    public function remove(int $userId, \DateTimeImmutable $now): array
    {
        $result = ['absences_deleted' => 0, 'absences_cleared' => 0, 'host_removed' => 0, 'event_types' => [], 'schedules' => []];

        if ($userId <= 0) {
            return $result;
        }

        $db = $this->db;
        $time = $db->quote($now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        $db->transactionStart();

        try {
            $db->setQuery($this->query()->delete($db->quoteName('#__appointments_blocked'))
                ->where($db->quoteName('user_id') . ' = ' . $userId)
                ->where($db->quoteName('end_utc') . ' <= ' . $time))->execute();
            $result['absences_deleted'] = (int) $db->getAffectedRows();
            $db->setQuery($this->query()->update($db->quoteName('#__appointments_blocked'))
                ->set($db->quoteName('title') . ' = ' . $db->quote(''))
                ->where($db->quoteName('user_id') . ' = ' . $userId)
                ->where($db->quoteName('title') . ' <> ' . $db->quote('')))->execute();
            $result['absences_cleared'] = (int) $db->getAffectedRows();
            $db->setQuery($this->query()->delete($db->quoteName('#__appointments_hosts'))
                ->where($db->quoteName('user_id') . ' = ' . $userId))->execute();
            $result['host_removed'] = (int) $db->getAffectedRows();
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }

        $result['event_types'] = array_map(static fn (array $row): string => (string) $row['title'],
            $this->rows('#__appointments_eventtypes', ['id', 'title'], 'host_user_id', $userId, 'title'));
        $result['schedules'] = array_map(static fn (array $row): string => (string) $row['title'],
            $this->rows('#__appointments_schedules', ['id', 'title'], 'owner_user_id', $userId, 'title'));

        return $result;
    }

    /**
     * @param   string[]  $fields
     *
     * @return  list<array<string, mixed>>
     */
    private function rows(string $table, array $fields, string $column, int $userId, string $order): array
    {
        $db = $this->db;

        return array_values($db->setQuery($this->query()->select($db->quoteName($fields))->from($db->quoteName($table))
            ->where($db->quoteName($column) . ' = ' . $userId)->order($db->quoteName($order) . ' ASC'))->loadAssocList() ?: []);
    }

    private function query()
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }
}
