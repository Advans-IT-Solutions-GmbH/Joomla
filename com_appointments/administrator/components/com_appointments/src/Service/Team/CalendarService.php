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
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;

defined('_JEXEC') or die;

/**
 * Internet calendars as records (work package C); the contract is
 * {@see CalendarServiceInterface}.
 *
 * Row MAC of `#__appointments_calendars` ({@see self::macFields()}): id, uri,
 * owner_user_id, allow_external, state, grant_epoch. A row also has to carry at
 * least the epoch the anchor outside the database knows (spec 5.11), so a
 * calendar row played back together with its grants is refused. Not key_version,
 * rotation_due and synctoken: the key service (rotation) and the object store
 * (every write) change them without a new MAC, and none of them grants
 * anything. Title, description and colour are display data.
 *
 * Who may do what:
 * - create: `core.create` on the component; a recovery key exists; the owner is
 *   an unblocked team member with a key pair. Allowing externals at creation
 *   needs the right to share externally.
 * - update: an effective manager of the calendar; switching «allow external
 *   users» needs the right to share externally as well, and switching it off
 *   removes every external grant at once.
 * - delete: `core.delete` and an effective manager. A calendar whose row no
 *   longer verifies (for example after the site secret changed) may be deleted
 *   by a user with `core.admin` and `core.delete`: deleting never grants.
 *
 * @since  1.4.0
 */
final class CalendarService implements CalendarServiceInterface
{
    public const TABLE = 'appointments_calendars';

    /** The only stored calendar state. */
    public const STATE_ACTIVE = 1;

    public function __construct(private readonly TeamServices $services)
    {
    }

    // ------------------------------------------------------------------
    // Pure functions
    // ------------------------------------------------------------------

    /**
     * @return  array{id: int, uri: string, owner_user_id: int, allow_external: int, state: int, grant_epoch: int}
     */
    public static function macFields(object|array $row): array
    {
        $row = (array) $row;

        return [
            'id'             => (int) ($row['id'] ?? 0),
            'uri'            => (string) ($row['uri'] ?? ''),
            'owner_user_id'  => (int) ($row['owner_user_id'] ?? 0),
            'allow_external' => (int) ($row['allow_external'] ?? 0) === 1 ? 1 : 0,
            'state'          => (int) ($row['state'] ?? 0),
            'grant_epoch'    => (int) ($row['grant_epoch'] ?? 1),
        ];
    }

    /**
     * Whether a calendar row verifies: its MAC, and an epoch no older than the
     * one anchored outside the database.
     */
    public static function rowValid(TeamServices $services, object $row): bool
    {
        $mac = (string) ($row->row_mac ?? '');

        return \strlen($mac) === 32
            && $services->keys()->verifyRowMac(self::TABLE, self::macFields($row), $mac)
            && $services->anchor()->accepts(EpochAnchorInterface::KIND_CALENDAR, (int) $row->id, (int) ($row->grant_epoch ?? 1));
    }

    /**
     * Raise the grant epoch of a calendar (spec 5.11): every grant row written
     * before stops verifying, so none can be played back; the verified grants
     * get a new MAC with the new epoch. Runs inside the caller's transaction and
     * locks the calendar row itself. The caller records the returned epoch in the
     * anchor after the commit ({@see self::anchor()}). The key service calls it
     * at every rotation, under the same lock.
     *
     * @return  int  The new epoch.
     */
    public static function bumpEpoch(TeamServices $services, int $calendarId): int
    {
        $db = $services->db();
        $row = $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE id = ' . $calendarId . ' FOR UPDATE'
        )->loadObject();

        if (!$row || !self::rowValid($services, $row)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
        }

        $old = (int) $row->grant_epoch;
        $new = $old + 1;
        GrantService::remacGrants($services, $calendarId, $old, $new);
        $row->grant_epoch = $new;
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__' . self::TABLE) . ' SET grant_epoch = ' . $new . ' WHERE id = ' . $calendarId
        )->execute();
        self::storeMac($services, $row);

        return $new;
    }

    /**
     * After the commit: let the anchor remember the committed epochs outside
     * the database. `recordAfterCommit()` reads the committed value itself
     * (at once, or at the end of the request when a transaction is still
     * open), so the epoch given here is only informative. A failure of the
     * anchor never undoes the change (the system check reports it).
     *
     * @param   array<int, int>  $epochs  Calendar id => epoch.
     */
    public static function anchor(TeamServices $services, array $epochs): void
    {
        foreach (array_keys($epochs) as $calendarId) {
            try {
                $services->anchor()->recordAfterCommit(EpochAnchorInterface::KIND_CALENDAR, (int) $calendarId);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Validate and normalise calendar input. Only the listed keys pass; every
     * other key (state, uri, key_version, synctoken, row_mac …) is dropped, so
     * a form can never assign a column it does not own. `owner_user_id` passes
     * only when $partial is false (creation).
     *
     * @return  array{title?: string, description?: string, colour?: string, owner_user_id?: int, allow_external?: bool, blocks_availability?: bool, retention_days?: int}
     *
     * @throws  \InvalidArgumentException
     */
    public static function normalise(array $data, bool $partial): array
    {
        $clean = [];

        if (!$partial || \array_key_exists('title', $data)) {
            $title = self::text($data['title'] ?? '', false);

            if ($title === '' || mb_strlen($title) > 255) {
                throw new \InvalidArgumentException('title');
            }

            $clean['title'] = $title;
        }

        if (\array_key_exists('description', $data)) {
            $description = self::text($data['description'] ?? '', true);

            if (mb_strlen($description) > 2000) {
                throw new \InvalidArgumentException('description');
            }

            $clean['description'] = $description;
        }

        if (\array_key_exists('colour', $data)) {
            $colour = trim((string) $data['colour']);

            if ($colour !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/D', $colour)) {
                throw new \InvalidArgumentException('colour');
            }

            $clean['colour'] = strtoupper($colour);
        }

        foreach (['allow_external', 'blocks_availability'] as $flag) {
            if (\array_key_exists($flag, $data)) {
                $value = $data[$flag];

                if (!\in_array($value, [0, 1, '0', '1', true, false], true)) {
                    throw new \InvalidArgumentException($flag);
                }

                $clean[$flag] = (int) $value === 1;
            }
        }

        if (\array_key_exists('retention_days', $data)) {
            $days = $data['retention_days'];

            if (!((\is_int($days) && $days >= 0) || (\is_string($days) && preg_match('/^\d{1,5}$/D', $days))) || (int) $days > 65535) {
                throw new \InvalidArgumentException('retention_days');
            }

            $clean['retention_days'] = (int) $days;
        }

        if (!$partial) {
            $owner = $data['owner_user_id'] ?? 0;

            if (!((\is_int($owner) && $owner > 0) || (\is_string($owner) && preg_match('/^\d{1,10}$/D', $owner))) || (int) $owner <= 0) {
                throw new \InvalidArgumentException('owner_user_id');
            }

            $clean['owner_user_id'] = (int) $owner;
        }

        return $clean;
    }

    public static function validUri(string $uri): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/D', $uri);
    }

    /**
     * Set a new owner, with a new MAC over the verified row. Only for the
     * recovery path, inside its transaction.
     */
    public static function setOwner(TeamServices $services, int $calendarId, int $ownerUserId): void
    {
        $db = $services->db();
        $row = $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE id = ' . $calendarId . ' FOR UPDATE'
        )->loadObject();

        if (!$row || !self::rowValid($services, $row)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
        }

        $row->owner_user_id = $ownerUserId;
        self::storeMac($services, $row);
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__' . self::TABLE) . ' SET owner_user_id = ' . $ownerUserId . ' WHERE id = ' . $calendarId
        )->execute();
    }

    /**
     * Every calendar for the administrator list, also those whose row does not
     * verify (shown as such, never usable). No key material.
     *
     * @return  object[]
     */
    public static function listing(TeamServices $services): array
    {
        $db = $services->db();
        $rows = $db->setQuery(
            'SELECT c.*, u.name AS owner_name, u.username AS owner_username,'
            . ' (SELECT COUNT(*) FROM ' . $db->quoteName('#__' . GrantService::TABLE) . ' AS g WHERE g.calendar_id = c.id) AS grant_count'
            . ' FROM ' . $db->quoteName('#__' . self::TABLE) . ' AS c'
            . ' LEFT JOIN ' . $db->quoteName('#__users') . ' AS u ON u.id = c.owner_user_id'
            . ' ORDER BY c.title, c.id'
        )->loadObjectList() ?: [];

        foreach ($rows as $row) {
            $row->mac_valid = self::rowValid($services, $row);
            unset($row->row_mac);
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // CalendarServiceInterface
    // ------------------------------------------------------------------

    public function create(int $actorUserId, array $data): int
    {
        $this->assertEnabled();
        $actor = GrantService::user($actorUserId);

        if ($actor === null || $actor->block || !$actor->authorise('core.create', 'com_appointments')) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
        }

        $clean = $this->validated($data, false);
        $ownerId = $clean['owner_user_id'];

        if (!empty($clean['allow_external']) && !GrantService::sharesExternally($this->services, $actorUserId)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
        }

        $owner = GrantService::user($ownerId);

        if ($owner === null || $owner->block || $this->services->grants()->audienceOf($ownerId) !== TeamStates::AUDIENCE_TEAM) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_OWNER', 409);
        }

        $keys = $this->services->keys();

        if ($keys->recoveryFingerprint() === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NO_RECOVERY', 409);
        }

        if (!$keys->hasUserKey($ownerId) || $keys->userPublicKey($ownerId) === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_OWNER_KEY', 409);
        }

        $id = $this->atomic(function () use ($actorUserId, $clean, $ownerId, $keys): int {
            $db = $this->services->db();
            $now = $db->quote($this->services->now()->format('Y-m-d H:i:s'));
            $uri = bin2hex(random_bytes(16));
            // A random placeholder never verifies; the real MAC needs the id.
            $db->setQuery(
                'INSERT INTO ' . $db->quoteName('#__' . self::TABLE)
                . ' (uri, title, description, colour, owner_user_id, allow_external, blocks_availability, retention_days,'
                . ' synctoken, key_version, rotation_due, state, row_mac, created_utc, modified_utc) VALUES ('
                . $db->quote($uri) . ', ' . $db->quote($clean['title']) . ', ' . $db->quote($clean['description'] ?? '') . ', '
                . $db->quote($clean['colour'] ?? '') . ', ' . $ownerId . ', ' . (int) !empty($clean['allow_external']) . ', '
                . (int) ($clean['blocks_availability'] ?? true) . ', ' . (int) ($clean['retention_days'] ?? 0) . ', 1, 1, 0, '
                . self::STATE_ACTIVE . ', UNHEX(' . $db->quote(bin2hex(random_bytes(32))) . '), ' . $now . ', ' . $now . ')'
            )->execute();
            $id = (int) $db->insertid();
            self::storeMac($this->services, (object) [
                'id' => $id, 'uri' => $uri, 'owner_user_id' => $ownerId,
                'allow_external' => (int) !empty($clean['allow_external']), 'state' => self::STATE_ACTIVE, 'grant_epoch' => 1,
            ]);
            $keys->createCalendarKeys($id, $ownerId);
            GrantService::write($this->services, $id, $ownerId, CalendarRole::Manage, TeamStates::GRANT_ACTIVE, TeamStates::AUDIENCE_TEAM, $actorUserId);

            GrantService::queueEpoch($id, 1);

            return $id;
        });

        $this->log(AuditEvent::CALENDAR_CREATED, [
            'actor_user_id' => $actorUserId, 'subject_user_id' => $ownerId, 'calendar_id' => $id,
            'details' => ['allow_external' => (int) !empty($clean['allow_external'])],
        ]);
        $this->log(AuditEvent::GRANT_CREATED, [
            'actor_user_id' => $actorUserId, 'subject_user_id' => $ownerId, 'calendar_id' => $id,
            'details' => ['role' => CalendarRole::Manage->name, 'audience' => TeamStates::AUDIENCE_TEAM, 'owner' => 1],
        ]);

        try {
            $this->services->mailer()->grantChanged($id, $ownerId, GrantService::roleLabelKey(CalendarRole::Manage), $actorUserId);
        } catch (\Throwable) {
        }

        return $id;
    }

    public function update(int $actorUserId, int $calendarId, array $data): void
    {
        $this->assertEnabled();
        $clean = $this->validated($data, true);
        $externalAfter = null;
        $removed = $this->atomic(function () use ($actorUserId, $calendarId, $clean, &$externalAfter): array {
            $db = $this->services->db();
            $row = $db->setQuery(
                'SELECT * FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE id = ' . $calendarId . ' FOR UPDATE'
            )->loadObject();

            if (!$row || !$this->macValid($row)) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
            }

            // Decided under the row lock, against the verified row.
            if (GrantService::accessUnderLock($this->services, $actorUserId, $row)?->role !== CalendarRole::Manage) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
            }

            $externalBefore = (int) $row->allow_external === 1;
            $externalAfter = $clean['allow_external'] ?? $externalBefore;

            if ($externalAfter !== $externalBefore && !GrantService::sharesExternally($this->services, $actorUserId)) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
            }

            $sets = [];

            foreach ($clean as $column => $value) {
                $sets[] = $db->quoteName($column) . ' = ' . (\is_bool($value) ? (int) $value : (\is_int($value) ? $value : $db->quote($value)));
            }

            $sets[] = 'modified_utc = ' . $db->quote($this->services->now()->format('Y-m-d H:i:s'));
            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__' . self::TABLE) . ' SET ' . implode(', ', $sets) . ' WHERE id = ' . $calendarId
            )->execute();
            $row->allow_external = (int) $externalAfter;
            self::storeMac($this->services, $row);

            if (!$externalBefore || $externalAfter) {
                return [];
            }

            $removed = $this->removeExternalGrants($calendarId);
            GrantService::queueEpoch($calendarId, CalendarService::bumpEpoch($this->services, $calendarId));

            return $removed;
        });


        $this->log(AuditEvent::CALENDAR_UPDATED, [
            'actor_user_id' => $actorUserId, 'calendar_id' => $calendarId,
            'details' => ['fields' => implode(',', array_keys($clean)), 'allow_external' => (int) $externalAfter],
        ]);

        foreach ($removed as $userId) {
            $this->log(AuditEvent::GRANT_REVOKED, [
                'actor_user_id' => $actorUserId, 'subject_user_id' => $userId, 'calendar_id' => $calendarId,
                'details' => ['reason' => 'external_disallowed'],
            ]);

            try {
                $this->services->mailer()->grantChanged($calendarId, $userId, GrantService::roleLabelKey(null), $actorUserId);
            } catch (\Throwable) {
            }
        }
    }

    public function delete(int $actorUserId, int $calendarId): void
    {
        $this->assertEnabled();
        $actor = GrantService::user($actorUserId);

        if ($actor === null || $actor->block || !$actor->authorise('core.delete', 'com_appointments')) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
        }

        $deleted = $this->atomic(function () use ($actor, $actorUserId, $calendarId): array {
            $db = $this->services->db();
            $row = $db->setQuery(
                'SELECT * FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE id = ' . $calendarId . ' FOR UPDATE'
            )->loadObject();

            if (!$row) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
            }

            // Decided under the row lock. A verified calendar: its manager. A
            // calendar that no longer verifies: an administrator (deleting
            // never grants).
            $valid = $this->macValid($row);
            $allowed = $valid
                ? GrantService::accessUnderLock($this->services, $actorUserId, $row)?->role === CalendarRole::Manage
                : $actor->authorise('core.admin', 'com_appointments');

            if (!$allowed) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
            }

            $recipients = [];

            if ($valid) {
                // Owner and effective managers, collected before the rows are gone.
                foreach ($this->services->grants()->grantsOf($calendarId) as $grant) {
                    if ($grant->is_owner || ($grant->effective && $grant->role === CalendarRole::Manage->value)) {
                        $recipients[] = (int) $grant->user_id;
                    }
                }

                if ((int) $row->owner_user_id > 0) {
                    $recipients[] = (int) $row->owner_user_id;
                }
            }

            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__appointments_eventtypes') . ' SET calendar_id = NULL WHERE calendar_id = ' . $calendarId
            )->execute();
            // Objects, grants, wraps, keys, changes, the busy cache, device
            // rows, invitations and event type links go with the foreign keys.
            $db->setQuery('DELETE FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE id = ' . $calendarId)->execute();

            // A tombstone in the anchor: the calendar restored from an older
            // copy of the database carries a lower epoch and is refused.
            $minimum = 0;

            try {
                $minimum = $this->services->anchor()->minimum(EpochAnchorInterface::KIND_CALENDAR, $calendarId);
            } catch (\Throwable) {
            }

            // The epoch of a row that does not verify is not trusted (it could
            // be set to anything); then only the anchored value counts.
            $epoch = $valid ? min((int) $row->grant_epoch, PHP_INT_MAX - 1) : 0;
            GrantService::queueTombstone($calendarId, min(max($epoch, $minimum), PHP_INT_MAX - 1) + 1);

            return ['title' => $valid ? (string) $row->title : null, 'recipients' => array_values(array_unique($recipients))];
        });

        $this->log(AuditEvent::CALENDAR_DELETED, ['actor_user_id' => $actorUserId, 'calendar_id' => $calendarId]);

        if ($deleted['title'] !== null && $deleted['recipients'] !== []) {
            try {
                $this->services->mailer()->calendarDeleted($deleted['title'], $deleted['recipients'], $actorUserId, $this->services->now());
            } catch (\Throwable) {
                // A mail failure never undoes the deletion; the log holds it.
            }
        }
    }

    public function get(int $calendarId): ?object
    {
        if ($calendarId <= 0) {
            return null;
        }

        $db = $this->services->db();

        return $this->verified($db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE id = ' . $calendarId
        )->loadObject() ?: null);
    }

    public function getByUri(string $uri): ?object
    {
        if (!self::validUri($uri)) {
            return null;
        }

        $db = $this->services->db();

        return $this->verified($db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE uri = ' . $db->quote($uri)
        )->loadObject() ?: null);
    }

    public function releaseOwner(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $released = $this->atomic(function () use ($userId): array {
            $db = $this->services->db();
            $rows = $db->setQuery(
                'SELECT * FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE owner_user_id = ' . $userId . ' FOR UPDATE'
            )->loadObjectList() ?: [];
            $ids = [];

            foreach ($rows as $row) {
                // A row that does not verify keeps its (dead) owner: it grants
                // nothing, and a new MAC would legitimise whatever it holds.
                if (!$this->macValid($row)) {
                    continue;
                }

                $row->owner_user_id = 0;
                self::storeMac($this->services, $row);
                $db->setQuery(
                    'UPDATE ' . $db->quoteName('#__' . self::TABLE) . ' SET owner_user_id = 0, modified_utc = '
                    . $db->quote($this->services->now()->format('Y-m-d H:i:s')) . ' WHERE id = ' . (int) $row->id
                )->execute();
                // A new epoch, so the row with the former owner cannot be played back.
                GrantService::queueEpoch((int) $row->id, self::bumpEpoch($this->services, (int) $row->id));
                $ids[] = (int) $row->id;
            }

            return $ids;
        });

        foreach ($released as $calendarId) {
            $this->log(AuditEvent::CALENDAR_UPDATED, [
                'subject_user_id' => $userId, 'calendar_id' => $calendarId, 'details' => ['reason' => 'owner_deleted'],
            ]);
        }

        return \count($released);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function verified(?object $row): ?object
    {
        if ($row === null || !$this->macValid($row)) {
            return null;
        }

        unset($row->row_mac);

        return $row;
    }

    private function macValid(object $row): bool
    {
        return self::rowValid($this->services, $row);
    }

    private static function storeMac(TeamServices $services, object $row): void
    {
        $db = $services->db();
        $mac = $services->keys()->rowMac(self::TABLE, self::macFields($row));
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__' . self::TABLE) . ' SET row_mac = UNHEX(' . $db->quote(bin2hex($mac)) . ')'
            . ' WHERE id = ' . (int) $row->id
        )->execute();
    }

    /**
     * Remove every external grant of a calendar that no longer allows externals.
     *
     * @return  int[]  The users whose grant was removed.
     */
    private function removeExternalGrants(int $calendarId): array
    {
        $db = $this->services->db();
        $users = array_map('intval', $db->setQuery(
            'SELECT user_id FROM ' . $db->quoteName('#__' . GrantService::TABLE) . ' WHERE calendar_id = ' . $calendarId
            . ' AND audience = ' . $db->quote(TeamStates::AUDIENCE_EXTERNAL) . ' FOR UPDATE'
        )->loadColumn() ?: []);

        if ($users === []) {
            return [];
        }

        $db->setQuery(
            'DELETE FROM ' . $db->quoteName('#__' . GrantService::TABLE) . ' WHERE calendar_id = ' . $calendarId
            . ' AND user_id IN (' . implode(',', $users) . ')'
        )->execute();

        foreach ($users as $userId) {
            $this->services->keys()->deleteUserWraps($calendarId, $userId);
        }

        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__' . self::TABLE) . ' SET rotation_due = 1 WHERE id = ' . $calendarId
        )->execute();

        return $users;
    }

    private function validated(array $data, bool $partial): array
    {
        try {
            return self::normalise($data, $partial);
        } catch (\InvalidArgumentException $e) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_INVALID', 400, $e);
        }
    }

    private static function text(mixed $value, bool $multiline): string
    {
        if (!\is_string($value) && !\is_int($value)) {
            throw new \InvalidArgumentException('text');
        }

        $value = (string) $value;

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('text');
        }

        $pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';

        return trim((string) preg_replace($pattern, '', $value));
    }

    private function assertEnabled(): void
    {
        if (!$this->services->enabled()) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_DISABLED', 404);
        }
    }

    /**
     * @template T
     * @param   callable():T  $work
     * @return  T
     */
    private function atomic(callable $work): mixed
    {
        return GrantService::transaction($this->services, $work);
    }

    private function log(string $event, array $context): void
    {
        try {
            $this->services->audit()->log($event, $context);
        } catch (\Throwable) {
        }
    }
}
