<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\FeedToken;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\GrantService;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * The data of a staff account outside the bookings and the internet calendars, for Joomla's
 * privacy requests: absences, the host feed record, the event types the account hosts and the
 * schedules it owns. Bound to the account Joomla found for the request, never to an address,
 * and independent of whether the internet calendars are on.
 *
 * Joomla's user plugin pseudonymises the account during the first removal, so a retry of the same
 * request no longer finds it. The removal check (which Joomla runs before every removal) therefore
 * captures the verified account and whether it is a team member in `#__appointments_privacy_scope`,
 * keyed by the request, and every removal of that request uses this scope. A captured scope is
 * never replaced.
 *
 * Removal follows Joomla's core privacy plugins: references to the account id stay. What the
 * component stores about the person goes: absences that are over are deleted; current and future
 * absences keep their period, which the slot calculation of the event types still needs, and lose
 * their reason (the title, which may hold health data). The host feed credential is replaced by a
 * random value no address carries, so the feed is revoked; the host row itself stays, because
 * existing bookings lock it. Event types and schedules are team configuration: they are never
 * changed automatically, their titles are returned so the administrator can review them.
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
     * Captures the verified account of a privacy request once. The first captured scope wins; a
     * later call never replaces it (a pseudonymised or another account cannot take it over).
     *
     * @param   int[]  $teamGroups     The option `team_groups`.
     * @param   int    $externalGroup  The option `external_group`.
     */
    public function capture(int $requestId, int $userId, array $teamGroups, int $externalGroup, \DateTimeImmutable $now): void
    {
        if ($requestId <= 0 || $userId < 0) {
            return;
        }

        $db = $this->db;
        // User id 0: the request has no account, recorded explicitly so that a retry can tell it
        // from a request whose scope is unknown.
        $team = $userId > 0 && self::isTeamMember($this->groupsOf($userId), $teamGroups, $externalGroup) ? 1 : 0;
        $db->setQuery('INSERT IGNORE INTO ' . $db->quoteName('#__appointments_privacy_scope')
            . ' (' . implode(', ', $db->quoteName(['request_id', 'user_id', 'team_member', 'created_utc'])) . ') VALUES ('
            . $requestId . ', ' . $userId . ', ' . $team . ', '
            . $db->quote($now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')) . ')')->execute();
    }

    /**
     * The captured scope of a privacy request (user id 0: the request has no account), or null when
     * none was captured (a request checked before this version).
     *
     * @return  array{user_id: int, team_member: bool}|null
     */
    public function scope(int $requestId): ?array
    {
        if ($requestId <= 0) {
            return null;
        }

        $db = $this->db;
        $row = $db->setQuery($this->query()->select($db->quoteName(['user_id', 'team_member']))
            ->from($db->quoteName('#__appointments_privacy_scope'))
            ->where($db->quoteName('request_id') . ' = ' . $requestId))->loadAssoc();

        return $row ? ['user_id' => (int) $row['user_id'], 'team_member' => (int) $row['team_member'] === 1] : null;
    }

    /**
     * Whether an account in these groups is a team member, with the audience rule of the internet
     * calendars (a member of the external group is external). Pure.
     *
     * @param   int[]  $groups
     * @param   int[]  $teamGroups
     */
    public static function isTeamMember(array $groups, array $teamGroups, int $externalGroup): bool
    {
        return GrantService::audienceFromGroups($groups, $teamGroups, $externalGroup) === TeamStates::AUDIENCE_TEAM;
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
     * @return  array{absences_deleted: int, absences_cleared: int, host_revoked: int, event_types: list<string>, schedules: list<string>}
     */
    public function remove(int $userId, \DateTimeImmutable $now): array
    {
        $result = ['absences_deleted' => 0, 'absences_cleared' => 0, 'host_revoked' => 0, 'event_types' => [], 'schedules' => []];

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
            // The host row is the lock target of the host's bookings and stays; its feed credential
            // is replaced by an unissued digest (FeedToken) that no address matches, which revokes the feed.
            $db->setQuery($this->query()->update($db->quoteName('#__appointments_hosts'))
                ->set($db->quoteName('feed_token') . ' = ' . $db->quote(FeedToken::unissued()))
                ->set($db->quoteName('modified_utc') . ' = ' . $time)
                ->where($db->quoteName('user_id') . ' = ' . $userId))->execute();
            $result['host_revoked'] = (int) $db->getAffectedRows();
            // Read before the commit: a failing lookup rolls everything back, so the message
            // "nothing changed" stays true and the review lists never go missing after a change.
            $result['event_types'] = array_map(static fn (array $row): string => (string) $row['title'],
                $this->rows('#__appointments_eventtypes', ['id', 'title'], 'host_user_id', $userId, 'title'));
            $result['schedules'] = array_map(static fn (array $row): string => (string) $row['title'],
                $this->rows('#__appointments_schedules', ['id', 'title'], 'owner_user_id', $userId, 'title'));
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }

        return $result;
    }

    /**
     * @return  int[]
     */
    private function groupsOf(int $userId): array
    {
        $db = $this->db;

        return array_map('intval', $db->setQuery($this->query()->select($db->quoteName('group_id'))
            ->from($db->quoteName('#__user_usergroup_map'))
            ->where($db->quoteName('user_id') . ' = ' . $userId))->loadColumn() ?: []);
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
