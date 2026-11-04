<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\DeviceSession;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;

defined('_JEXEC') or die;

/**
 * Blocking with clean-up (work package F): active → empty → hidden → revoked.
 *
 * - Every state change of a device goes through {@see DeviceServiceInterface::changeState()}
 *   (compare-and-set, new row MAC, `usk_wrapped` cleared on leaving active) and the final step
 *   through {@see DeviceServiceInterface::revoke()}. Nothing here moves a device backwards: a device
 *   that left the active state never receives keys again; the user adds a new device.
 * - `#__appointments_devices.cleanup_started_utc` holds the start of the current phase; a phase
 *   ends when the device fetched it ({@see self::markDelivered()}, {@see self::markHomeSeen()}) or
 *   after the option `team_cleanup_days` (1 to 90, default 14), by {@see self::advance()} or, when
 *   the Scheduler does not run, on the device's next request ({@see self::viewFor()} already
 *   answers with the next phase).
 * - The empty phase is complete when every calendar the device knows
 *   (`#__appointments_device_calendars`, {@see self::calendarsInCleanup()}) was delivered.
 * - Triggers: the user's «remove device» and an administrator's «block device» call
 *   {@see self::startForDevice()}; an administrator's «block calendar access», a blocked Joomla
 *   account ({@see self::onUserBlocked()}) and a password reset (work package B) call
 *   {@see self::startForUser()}, which also suspends the user's grants (the grant service logs
 *   each `grant.suspended` itself). Only {@see self::onUserBlocked()} mails the user; the password
 *   reset mails through work package B.
 *
 * @since  1.4.0
 */
final class CleanupService implements CleanupServiceInterface
{
    public const DEFAULT_DAYS = 14;

    private const BATCH = 500;

    public function __construct(private readonly TeamServices $services)
    {
    }

    public function startForUser(int $userId, string $reason, ?int $actorUserId): int
    {
        return $this->blockUser($userId, $reason, $actorUserId)['devices'];
    }

    public function startForDevice(int $deviceId, string $reason, ?int $actorUserId): void
    {
        $device = $this->device($deviceId);

        if ($device === null || (int) $device['state'] !== TeamStates::DEVICE_ACTIVE) {
            return;
        }

        // Earlier deliveries are cleared only while the device is still active,
        // checked in the same statement (nothing marks a delivery then). A
        // second, concurrent block that comes after the state change clears
        // nothing, so an acknowledgement is never erased.
        $db = $this->db();
        $db->setQuery('UPDATE ' . $db->quoteName('#__appointments_device_calendars', 'dc')
            . ' INNER JOIN ' . $db->quoteName('#__appointments_devices', 'd') . ' ON ' . $db->quoteName('d.id') . ' = ' . $db->quoteName('dc.device_id')
            . ' SET ' . $db->quoteName('dc.cleanup_delivered_utc') . ' = NULL'
            . ' WHERE ' . $db->quoteName('dc.device_id') . ' = ' . $deviceId
            . ' AND ' . $db->quoteName('d.state') . ' = ' . TeamStates::DEVICE_ACTIVE)->execute();

        if (!$this->services->devices()->changeState($deviceId, TeamStates::DEVICE_ACTIVE, TeamStates::DEVICE_CLEANUP_EMPTY)) {
            return;
        }

        $this->startPhase($deviceId, TeamStates::DEVICE_CLEANUP_EMPTY, true);
        $this->services->audit()->log(AuditEvent::DEVICE_CLEANUP_STARTED, [
            'actor_user_id'   => $actorUserId,
            'subject_user_id' => (int) $device['user_id'],
            'object_ref'      => 'device:' . $deviceId,
            'details'         => ['device_id' => $deviceId, 'reason' => self::reason($reason)],
        ]);
    }

    public function viewFor(DeviceSession $session, int $calendarId): string
    {
        return match ($this->effectiveState($session)) {
            TeamStates::DEVICE_ACTIVE => self::VIEW_NORMAL,
            // Empty only for a calendar the device synchronised before: a
            // blocked device cannot probe calendars it never had.
            TeamStates::DEVICE_CLEANUP_EMPTY => isset($this->calendarsInCleanup($session)[$calendarId])
                ? self::VIEW_EMPTY : self::VIEW_HIDDEN,
            default => self::VIEW_HIDDEN,
        };
    }

    /**
     * The calendars a device in the empty phase still gets listed (with no
     * objects), with the role it had at its first synchronisation: those it
     * synchronised and that still exist. Its grants are usually suspended by
     * then, so the grant service would list none of them.
     *
     * @return  array<int, array{role: int, synctoken: int}>  Calendar id => role and last delivered sync token.
     */
    public function calendarsInCleanup(DeviceSession $session): array
    {
        if ($this->effectiveState($session) !== TeamStates::DEVICE_CLEANUP_EMPTY) {
            return [];
        }

        $db = $this->db();
        $rows = $db->setQuery($this->query()->select($db->quoteName(['dc.calendar_id', 'dc.role', 'dc.synctoken']))
            ->from($db->quoteName('#__appointments_device_calendars', 'dc'))
            ->join('INNER', $db->quoteName('#__appointments_calendars', 'c') . ' ON ' . $db->quoteName('c.id')
                . ' = ' . $db->quoteName('dc.calendar_id'))
            ->where($db->quoteName('dc.device_id') . ' = ' . $session->deviceId)
            ->order($db->quoteName('dc.calendar_id') . ' ASC'))->loadAssocList() ?: [];
        $calendars = [];

        foreach ($rows as $row) {
            $calendars[(int) $row['calendar_id']] = ['role' => (int) $row['role'], 'synctoken' => (int) $row['synctoken']];
        }

        return $calendars;
    }

    public function markDelivered(DeviceSession $session, int $calendarId): void
    {
        if ($calendarId <= 0 || $this->effectiveState($session) !== TeamStates::DEVICE_CLEANUP_EMPTY) {
            return;
        }

        // Only a pair the device already has: it cannot add calendars to its
        // clean-up view by asking for them.
        $db = $this->db();
        $db->setQuery($this->query()->update($db->quoteName('#__appointments_device_calendars'))
            ->set($db->quoteName('cleanup_delivered_utc') . ' = ' . $db->quote($this->time()))
            ->where($db->quoteName('device_id') . ' = ' . $session->deviceId)
            ->where($db->quoteName('calendar_id') . ' = ' . $calendarId)
            ->where($db->quoteName('cleanup_delivered_utc') . ' IS NULL'))->execute();

        if ($this->undelivered($session->deviceId) === 0) {
            $this->toHidden($session->deviceId, $session->userId, 'delivered');
        }
    }

    public function markHomeSeen(DeviceSession $session): void
    {
        $state = $this->effectiveState($session);

        if ($state !== TeamStates::DEVICE_CLEANUP_HIDDEN) {
            return;
        }

        $device = $this->device($session->deviceId);

        // The empty phase ran out without the Scheduler: the device was just
        // shown the hidden home, so both steps are done.
        if ($device !== null && (int) $device['state'] === TeamStates::DEVICE_CLEANUP_EMPTY) {
            $this->toHidden($session->deviceId, $session->userId, 'timeout');
        }

        $this->toRevoked($session->deviceId, $session->userId, 'delivered');
    }

    public function advance(\DateTimeImmutable $nowUtc): int
    {
        $db = $this->db();
        $limit = $nowUtc->setTimezone(new \DateTimeZone('UTC'))->modify('-' . $this->days() . ' days')->format('Y-m-d H:i:s');
        $advanced = 0;
        $started = $db->quoteName('d.cleanup_started_utc');
        $state = $db->quoteName('d.state');
        // Only devices that can move now, so devices still waiting for their
        // timeout never keep ready ones out of the batch.
        $pending = 'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__appointments_device_calendars', 'dc')
            . ' WHERE ' . $db->quoteName('dc.device_id') . ' = ' . $db->quoteName('d.id')
            . ' AND ' . $db->quoteName('dc.cleanup_delivered_utc') . ' IS NULL)';
        $devices = $db->setQuery($this->query()->select($db->quoteName(['d.id', 'd.user_id', 'd.state', 'd.cleanup_started_utc']))
            ->from($db->quoteName('#__appointments_devices', 'd'))
            ->where('((' . $state . ' = ' . TeamStates::DEVICE_CLEANUP_EMPTY . ' AND (' . $started . ' IS NULL OR ' . $started . ' <= '
                . $db->quote($limit) . ' OR ' . $pending . ')) OR (' . $state . ' = ' . TeamStates::DEVICE_CLEANUP_HIDDEN . ' AND ('
                . $started . ' IS NULL OR ' . $started . ' <= ' . $db->quote($limit) . ')))')
            ->order($started . ' ASC')->order($db->quoteName('d.id') . ' ASC')->setLimit(self::BATCH))->loadAssocList();

        foreach ($devices as $device) {
            $id = (int) $device['id'];
            $userId = (int) $device['user_id'];

            if ($device['cleanup_started_utc'] === null) {
                // A state set without a phase start: the phase starts now.
                $this->startPhase($id, (int) $device['state'], true);

                continue;
            }

            $timedOut = (string) $device['cleanup_started_utc'] <= $limit;

            if ((int) $device['state'] === TeamStates::DEVICE_CLEANUP_EMPTY && ($timedOut || $this->undelivered($id) === 0)) {
                $advanced += $this->toHidden($id, $userId, $timedOut ? 'timeout' : 'delivered') ? 1 : 0;
            } elseif ((int) $device['state'] === TeamStates::DEVICE_CLEANUP_HIDDEN && $timedOut) {
                $advanced += $this->toRevoked($id, $userId, 'timeout') ? 1 : 0;
            }
        }

        return $advanced;
    }

    public function onUserBlocked(int $userId, ?int $actorUserId): void
    {
        // The mail's reasons: `blocked` (the Joomla account) or `admin` (calendar access blocked in the backend).
        $reason = $actorUserId !== null ? 'admin' : 'blocked';
        $result = $this->blockUser($userId, $reason, $actorUserId);

        if ($result['devices'] + $result['grants'] === 0) {
            return;
        }

        try {
            $this->services->mailer()->accessSuspended($userId, $reason);
        } catch (\Throwable $e) {
            Log::add('Team calendars: the suspension mail could not be sent (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
        }
    }

    public function onUserDeleted(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $db = $this->db();
        $in = static fn (array $ids): string => implode(',', array_map('intval', $ids));
        $released = 0;
        $failure = null;

        // First through the owning services, which raise the revocation
        // epochs (device_epoch, grant_epoch) and the anchor: a database writer
        // who plays the deleted rows back afterwards gets nothing that verifies.
        foreach ($db->setQuery($this->query()->select($db->quoteName('id'))->from($db->quoteName('#__appointments_devices'))
            ->where($db->quoteName('user_id') . ' = ' . $userId)
            ->where($db->quoteName('state') . ' <> ' . TeamStates::DEVICE_REVOKED))->loadColumn() ?: [] as $deviceId) {
            try {
                $this->services->devices()->revoke(null, (int) $deviceId, 'account_deleted');
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        try {
            $this->services->grants()->suspendAllForUser($userId, 'account_deleted');
        } catch (\Throwable $e) {
            $failure ??= $e;
        }

        $db->transactionStart(true);

        try {
            $devices = (int) $this->count('#__appointments_devices', 'user_id', $userId);
            $grants = (int) $this->count('#__appointments_calendar_grants', 'user_id', $userId);
            $calendars = $db->setQuery($this->query()->select('DISTINCT ' . $db->quoteName('calendar_id'))
                ->from($db->quoteName('#__appointments_calendar_key_wraps'))
                ->where($db->quoteName('user_id') . ' = ' . $userId))->loadColumn() ?: [];

            // Rows go; the device-calendar pairs follow by their foreign key.
            foreach (['#__appointments_devices', '#__appointments_calendar_key_wraps', '#__appointments_calendar_grants',
                '#__appointments_user_keys', '#__appointments_invitations'] as $table) {
                $db->setQuery($this->query()->delete($db->quoteName($table))
                    ->where($db->quoteName('user_id') . ' = ' . $userId))->execute();
            }

            if ($calendars !== []) {
                // The user held a calendar key: the next key holder rotates it.
                // `rotation_due` is outside the calendar's row MAC on purpose.
                $db->setQuery($this->query()->update($db->quoteName('#__appointments_calendars'))
                    ->set($db->quoteName('rotation_due') . ' = 1')
                    ->where($db->quoteName('id') . ' IN (' . $in($calendars) . ')'))->execute();
            }

            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $db->transactionRollback(true);

            throw $e;
        }

        // Access is gone at this point. Releasing the ownership changes the
        // calendars' row MAC and belongs to the calendar service; a failure
        // there is reported, but never brings a device or a grant back.
        try {
            $released = $this->services->calendars()->releaseOwner($userId);
        } catch (\Throwable $e) {
            $failure ??= $e;
        }

        $this->services->audit()->log(AuditEvent::USER_CLEANUP_STARTED, [
            'subject_user_id' => $userId,
            'details'         => [
                'reason'    => 'account_deleted',
                'devices'   => $devices,
                'grants'    => $grants,
                'calendars' => $released,
                'complete'  => $failure === null,
            ],
        ]);

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @return  array{devices: int, grants: int}
     */
    private function blockUser(int $userId, string $reason, ?int $actorUserId): array
    {
        if ($userId <= 0) {
            return ['devices' => 0, 'grants' => 0];
        }

        $db = $this->db();
        $ids = $db->setQuery($this->query()->select($db->quoteName('id'))->from($db->quoteName('#__appointments_devices'))
            ->where($db->quoteName('user_id') . ' = ' . $userId)
            ->where($db->quoteName('state') . ' = ' . TeamStates::DEVICE_ACTIVE)
            ->order($db->quoteName('id') . ' ASC'))->loadColumn() ?: [];
        $devices = 0;
        $failure = null;

        // Devices first: they are what reaches data. One failing device, or a
        // failing grant service, must not leave the others active.
        foreach ($ids as $id) {
            try {
                $this->startForDevice((int) $id, $reason, $actorUserId);
                $after = $this->device((int) $id);
                $devices += $after !== null && (int) $after['state'] !== TeamStates::DEVICE_ACTIVE ? 1 : 0;
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        try {
            $grants = $this->services->grants()->suspendAllForUser($userId, self::reason($reason));
        } catch (\Throwable $e) {
            $grants = 0;
            $failure ??= $e;
        }

        if ($devices + $grants > 0) {
            $this->services->audit()->log(AuditEvent::USER_CLEANUP_STARTED, [
                'actor_user_id'   => $actorUserId,
                'subject_user_id' => $userId,
                'details'         => ['reason' => self::reason($reason), 'devices' => $devices, 'grants' => $grants,
                    'complete' => $failure === null],
            ]);
        }

        if ($failure !== null) {
            throw $failure;
        }

        return ['devices' => $devices, 'grants' => $grants];
    }

    /**
     * The state the device is treated as now: the stored one, moved one phase
     * on when the current phase ran out. Never better than the session says.
     */
    private function effectiveState(DeviceSession $session): int
    {
        $device = $this->device($session->deviceId);

        if ($device === null || (int) $device['user_id'] !== $session->userId) {
            return TeamStates::DEVICE_REVOKED;
        }

        $state = (int) $device['state'];

        if ($state === TeamStates::DEVICE_ACTIVE) {
            return $session->isActive() ? TeamStates::DEVICE_ACTIVE : TeamStates::DEVICE_CLEANUP_EMPTY;
        }

        // Phases that ran out while the Scheduler did not run move on here, on
        // the device's own request: a device can never stay usable because
        // nobody advanced it. The empty phase becomes hidden (in the view), a
        // timed-out hidden phase is revoked at once.
        if ($state === TeamStates::DEVICE_CLEANUP_EMPTY && $this->timedOut($device)) {
            $state = TeamStates::DEVICE_CLEANUP_HIDDEN;
            $phaseEnd = (new \DateTimeImmutable((string) $device['cleanup_started_utc'], new \DateTimeZone('UTC')))
                ->modify('+' . (2 * $this->days()) . ' days');

            if ($phaseEnd > $this->services->now()) {
                return $state;
            }

            $this->toHidden($session->deviceId, $session->userId, 'timeout');
            $device = $this->device($session->deviceId) ?? $device;
            // Its hidden phase ran out as well.
            $device['cleanup_started_utc'] = $phaseEnd->modify('-' . $this->days() . ' days')->format('Y-m-d H:i:s');
        }

        if ($state === TeamStates::DEVICE_CLEANUP_HIDDEN && $this->timedOut($device)) {
            $this->toRevoked($session->deviceId, $session->userId, 'timeout');

            return TeamStates::DEVICE_REVOKED;
        }

        return \in_array($state, [TeamStates::DEVICE_CLEANUP_EMPTY, TeamStates::DEVICE_CLEANUP_HIDDEN], true)
            ? $state : TeamStates::DEVICE_REVOKED;
    }

    private function toHidden(int $deviceId, int $userId, string $cause): bool
    {
        // The new phase start is written first, so nobody can see the hidden
        // state with the old (expired) start and revoke at once. If the state
        // change does not happen, the old start is put back.
        $before = $this->device($deviceId);

        if ($before === null || (int) $before['state'] !== TeamStates::DEVICE_CLEANUP_EMPTY) {
            return false;
        }

        // Written only while the device is still in the empty phase, so a stale
        // run never moves a hidden device's start (and its revocation) further
        // out. An unchanged row (same second) is no failure: the device
        // service's compare-and-set below decides.
        $this->startPhase($deviceId, TeamStates::DEVICE_CLEANUP_EMPTY);

        try {
            $changed = $this->services->devices()->changeState($deviceId, TeamStates::DEVICE_CLEANUP_EMPTY, TeamStates::DEVICE_CLEANUP_HIDDEN);
        } catch (\Throwable $e) {
            $changed = false;
            $failure = $e;
        }

        if (!$changed) {
            if ($before !== null && (int) $before['state'] === TeamStates::DEVICE_CLEANUP_EMPTY) {
                $db = $this->db();
                $db->setQuery($this->query()->update($db->quoteName('#__appointments_devices'))
                    ->set($db->quoteName('cleanup_started_utc') . ' = ' . ($before['cleanup_started_utc'] === null ? 'NULL' : $db->quote((string) $before['cleanup_started_utc'])))
                    ->where($db->quoteName('id') . ' = ' . $deviceId)
                    ->where($db->quoteName('state') . ' = ' . TeamStates::DEVICE_CLEANUP_EMPTY))->execute();
            }

            if (isset($failure)) {
                throw $failure;
            }

            return false;
        }

        $this->logAdvanced($deviceId, $userId, 'hidden', $cause);

        return true;
    }

    private function toRevoked(int $deviceId, int $userId, string $cause): bool
    {
        $device = $this->device($deviceId);

        if ($device === null || (int) $device['state'] !== TeamStates::DEVICE_CLEANUP_HIDDEN) {
            return false;
        }

        $this->services->devices()->revoke(null, $deviceId, 'cleanup_' . $cause);
        $this->logAdvanced($deviceId, $userId, 'revoked', $cause);

        return true;
    }

    private function logAdvanced(int $deviceId, int $userId, string $phase, string $cause): void
    {
        $this->services->audit()->log(AuditEvent::DEVICE_CLEANUP_ADVANCED, [
            'subject_user_id' => $userId,
            'object_ref'      => 'device:' . $deviceId,
            'details'         => ['device_id' => $deviceId, 'phase' => $phase, 'cause' => $cause],
        ]);
    }

    /**
     * `cleanup_started_utc` is outside the device's row MAC: it only decides
     * when a phase may end, and a phase only ever moves towards revoked. Set
     * only while the device is in the given state (and, with $onlyIfEmpty,
     * only when no start is set), so a stale caller cannot postpone a phase.
     *
     * @return  bool  Whether the start was written.
     */
    private function startPhase(int $deviceId, int $state, bool $onlyIfEmpty = false): bool
    {
        $db = $this->db();
        $query = $this->query()->update($db->quoteName('#__appointments_devices'))
            ->set($db->quoteName('cleanup_started_utc') . ' = ' . $db->quote($this->time()))
            ->where($db->quoteName('id') . ' = ' . $deviceId)
            ->where($db->quoteName('state') . ' = ' . $state);

        if ($onlyIfEmpty) {
            $query->where($db->quoteName('cleanup_started_utc') . ' IS NULL');
        }

        $db->setQuery($query)->execute();

        return (int) $db->getAffectedRows() === 1;
    }

    private function undelivered(int $deviceId): int
    {
        $db = $this->db();

        return (int) $db->setQuery($this->query()->select('COUNT(*)')
            ->from($db->quoteName('#__appointments_device_calendars'))
            ->where($db->quoteName('device_id') . ' = ' . $deviceId)
            ->where($db->quoteName('cleanup_delivered_utc') . ' IS NULL'))->loadResult();
    }

    /**
     * @param   array<string, mixed>  $device
     */
    private function timedOut(array $device): bool
    {
        if (empty($device['cleanup_started_utc'])) {
            return false;
        }

        $started = new \DateTimeImmutable((string) $device['cleanup_started_utc'], new \DateTimeZone('UTC'));

        return $started->modify('+' . $this->days() . ' days') <= $this->services->now();
    }

    private function days(): int
    {
        $days = filter_var($this->services->params()->get('team_cleanup_days', self::DEFAULT_DAYS), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 90]]);

        return $days === false ? self::DEFAULT_DAYS : $days;
    }

    /**
     * @return  array<string, mixed>|null
     */
    private function device(int $deviceId): ?array
    {
        $db = $this->db();

        return $db->setQuery($this->query()->select($db->quoteName(['id', 'user_id', 'state', 'cleanup_started_utc']))
            ->from($db->quoteName('#__appointments_devices'))
            ->where($db->quoteName('id') . ' = ' . $deviceId))->loadAssoc() ?: null;
    }

    private function count(string $table, string $column, int $value): int
    {
        $db = $this->db();

        return (int) $db->setQuery($this->query()->select('COUNT(*)')->from($db->quoteName($table))
            ->where($db->quoteName($column) . ' = ' . $value))->loadResult();
    }

    /**
     * A reason code for the log: lower-case letters, digits and underscores, at most 32.
     */
    private static function reason(string $reason): string
    {
        $reason = substr((string) preg_replace('/[^a-z0-9_]/', '', strtolower($reason)), 0, 32);

        return $reason === '' ? 'unspecified' : $reason;
    }

    private function time(): string
    {
        return $this->services->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function db(): DatabaseInterface
    {
        return $this->services->db();
    }

    private function query(): QueryInterface
    {
        $db = $this->db();

        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
