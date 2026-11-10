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
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarAccess;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;

defined('_JEXEC') or die;

/**
 * Default-deny grants of the internet calendars (work package C); the contract
 * is {@see GrantServiceInterface}.
 *
 * Row MAC of `#__appointments_calendar_grants` ({@see self::macFields()}):
 * calendar_id, user_id, role, state, audience, granted_by, plus the calendar's
 * `grant_epoch` and the grantee's `user_keys.key_version` (spec 5.11). Every
 * change of the grants of a calendar raises its epoch and gives the verified
 * grants a new MAC, so an older grant row played back from a copy no longer
 * verifies; a new user key of the grantee does the same for the user's grants
 * ({@see self::rebindUserKey()}).
 * `client_props`, `created_utc` and `modified_utc` are display data and not
 * covered. A row whose MAC does not verify grants nothing, and no method of
 * this class ever computes a new MAC from the values of such a row: it is
 * either overwritten completely by a decision of an authorised actor, or left
 * alone (and stays ineffective).
 *
 * The decisions themselves are pure static functions ({@see self::isEffective()},
 * {@see self::mayGrant()}, {@see self::mayRevoke()}), so the grant matrix is
 * tested without a database.
 *
 * @since  1.4.0
 */
final class GrantService implements GrantServiceInterface
{
    public const TABLE = 'appointments_calendar_grants';

    public const AUDIENCE_NONE = 'none';

    /** Joomla MFA methods that count as a second factor for the internet calendars. */
    public const SECOND_FACTOR_METHODS = ['totp', 'webauthn', 'yubikey'];

    /** The states in which a grant can be effective (the rest of the checks permitting). */
    private const EFFECTIVE_STATES = [TeamStates::GRANT_ACTIVE, TeamStates::GRANT_PENDING_KEY];

    /**
     * Depth of the team transactions of this request ({@see self::transaction()}).
     *
     * @var  int
     */
    private static $depth = 0;

    /**
     * Epochs raised inside the open team transaction, written to the anchor after
     * the outermost commit and dropped with a rollback.
     *
     * @var  array<int, array{0: int, 1: int}>
     */
    private static $pendingEpochs = [];

    public function __construct(private readonly TeamServices $services)
    {
    }

    // ------------------------------------------------------------------
    // Pure decisions (no database, no Joomla)
    // ------------------------------------------------------------------

    /**
     * The fields the row MAC of a grant covers, normalised so that a row read
     * from the database and a row about to be written produce the same bytes.
     *
     * @param   int  $grantEpoch      `#__appointments_calendars.grant_epoch` of the grant's calendar.
     * @param   int  $userKeyVersion  `#__appointments_user_keys.key_version` of the grantee, 0 without a key pair.
     *
     * @return  array{calendar_id: int, user_id: int, role: int, state: int, audience: string, granted_by: int, grant_epoch: int, user_key_version: int}
     */
    public static function macFields(object|array $row, int $grantEpoch, int $userKeyVersion): array
    {
        $row = (array) $row;

        return [
            'calendar_id'      => (int) ($row['calendar_id'] ?? 0),
            'user_id'          => (int) ($row['user_id'] ?? 0),
            'role'             => (int) ($row['role'] ?? 0),
            'state'            => (int) ($row['state'] ?? 0),
            'audience'         => (string) ($row['audience'] ?? ''),
            'granted_by'       => (int) ($row['granted_by'] ?? 0),
            'grant_epoch'      => $grantEpoch,
            'user_key_version' => $userKeyVersion,
        ];
    }

    /**
     * The audience of an account from its direct group memberships. A member of
     * the external group is external even when also in a team group.
     *
     * @param   int[]  $groups     The account's groups.
     * @param   int[]  $teamGroups The option `team_groups`.
     */
    public static function audienceFromGroups(array $groups, array $teamGroups, int $externalGroup): string
    {
        $groups = array_map('intval', $groups);
        $teamGroups = array_values(array_filter(array_map('intval', $teamGroups), static fn (int $id): bool => $id > 0));

        if ($externalGroup > 0 && \in_array($externalGroup, $groups, true)) {
            return TeamStates::AUDIENCE_EXTERNAL;
        }

        if (array_intersect($teamGroups, $groups) !== []) {
            return TeamStates::AUDIENCE_TEAM;
        }

        return self::AUDIENCE_NONE;
    }

    /**
     * Whether a grant is effective. Every fact may be given as a value or as a
     * callable that returns it, so the expensive ones (second factor, key wrap)
     * are only looked up when everything before them holds.
     *
     * Facts: role (?CalendarRole), grant_mac (bool), grant_state (int),
     * grant_audience (string), calendar_mac (bool), calendar_state (int),
     * calendar_allows_external (bool), account_exists (bool),
     * account_blocked (bool), audience (string), second_factor (bool),
     * wrap (bool: a wrap of the calendar's current key version exists).
     *
     * @param   array<string, mixed>  $facts
     */
    public static function isEffective(array $facts): bool
    {
        $fact = static function (string $name) use ($facts) {
            $value = $facts[$name] ?? null;

            return $value instanceof \Closure ? $value() : $value;
        };

        $role = $fact('role');

        if (!$role instanceof CalendarRole || $fact('grant_mac') !== true || $fact('calendar_mac') !== true) {
            return false;
        }

        if (!\in_array($fact('grant_state'), self::EFFECTIVE_STATES, true) || $fact('calendar_state') !== 1) {
            return false;
        }

        if ($fact('account_exists') !== true || $fact('account_blocked') !== false) {
            return false;
        }

        $audience = $fact('audience');

        if (!\in_array($audience, [TeamStates::AUDIENCE_TEAM, TeamStates::AUDIENCE_EXTERNAL], true) || $audience !== $fact('grant_audience')) {
            return false;
        }

        if ($audience === TeamStates::AUDIENCE_EXTERNAL
            && ($fact('calendar_allows_external') !== true || !CalendarRole::maximumForExternal()->includes($role))) {
            return false;
        }

        if ($fact('second_factor') !== true) {
            return false;
        }

        return !$role->holdsKey() || $fact('wrap') === true;
    }

    /**
     * Who may give whom which role on a calendar.
     *
     * - Team members: an effective manager or MFA-checked administrator, up to manage.
     * - External users: only an actor with `appointments.team.external`, only on
     *   a calendar that allows externals, at most write. Being a manager does
     *   not suffice.
     * - Only administrators change their own grant; the owner's grant can only
     *   ever be (re)set to manage.
     */
    public static function mayGrant(
        ?CalendarRole $actorRole,
        bool $actorSharesExternally,
        string $granteeAudience,
        CalendarRole $role,
        bool $calendarAllowsExternal,
        bool $granteeIsOwner,
        bool $self,
        bool $administrator = false
    ): bool {
        if ($self && !$administrator) {
            return false;
        }

        if ($granteeAudience === TeamStates::AUDIENCE_TEAM) {
            return ($administrator || $actorRole === CalendarRole::Manage) && (!$granteeIsOwner || $role === CalendarRole::Manage);
        }

        if ($granteeAudience === TeamStates::AUDIENCE_EXTERNAL) {
            return $actorSharesExternally && $calendarAllowsExternal && !$granteeIsOwner
                && CalendarRole::maximumForExternal()->includes($role);
        }

        return false;
    }

    /**
     * Who may remove a grant: a manager any grant but the owner's and their own;
     * an actor with `appointments.team.external` the grants of external users.
     * Administrators can revoke any grant, clearing ownership when necessary.
     */
    public static function mayRevoke(
        ?CalendarRole $actorRole,
        bool $actorSharesExternally,
        string $grantAudience,
        string $granteeAudience,
        bool $granteeIsOwner,
        bool $self,
        bool $administrator = false
    ): bool {
        if ($administrator) {
            return true;
        }

        if ($self || $granteeIsOwner) {
            return false;
        }

        if ($actorRole === CalendarRole::Manage) {
            return true;
        }

        return $actorSharesExternally
            && ($grantAudience === TeamStates::AUDIENCE_EXTERNAL || $granteeAudience === TeamStates::AUDIENCE_EXTERNAL);
    }

    /**
     * What the recovery key may grant: the audience rules apply (team up to
     * manage, external only on a calendar that allows it, at most write, and only
     * by an actor who shares externally); the manager rule does not.
     */
    public static function mayGrantWithRecovery(
        bool $actorIsAdmin,
        bool $actorSharesExternally,
        string $granteeAudience,
        CalendarRole $role,
        bool $calendarAllowsExternal,
        bool $granteeIsOwner
    ): bool {
        if (!$actorIsAdmin || ($granteeIsOwner && $role !== CalendarRole::Manage)) {
            return false;
        }

        if ($granteeAudience === TeamStates::AUDIENCE_TEAM) {
            return true;
        }

        return $granteeAudience === TeamStates::AUDIENCE_EXTERNAL && $actorSharesExternally && $calendarAllowsExternal
            && CalendarRole::maximumForExternal()->includes($role);
    }

    /**
     * The language key of a role, written out per case so that every key is
     * visible to the language lint. Null is «no access».
     */
    public static function roleLabelKey(?CalendarRole $role): string
    {
        return match ($role) {
            CalendarRole::FreeBusy => 'COM_APPOINTMENTS_TEAM_C_ROLE_FREEBUSY',
            CalendarRole::Read     => 'COM_APPOINTMENTS_TEAM_C_ROLE_READ',
            CalendarRole::Write    => 'COM_APPOINTMENTS_TEAM_C_ROLE_WRITE',
            CalendarRole::Manage   => 'COM_APPOINTMENTS_TEAM_C_ROLE_MANAGE',
            null                   => 'COM_APPOINTMENTS_TEAM_C_ROLE_NONE',
        };
    }

    /**
     * The display properties a client may store, validated. Unknown keys and
     * invalid values are refused; null removes a property.
     *
     * @param   array<string, mixed>  $current  Stored properties.
     * @param   array<string, mixed>  $changes  Requested changes.
     *
     * @return  array{colour?: string, order?: int}
     *
     * @throws  \InvalidArgumentException
     */
    public static function mergeClientProps(array $current, array $changes): array
    {
        $props = self::cleanClientProps($current);

        foreach ($changes as $key => $value) {
            if ($key !== 'colour' && $key !== 'order') {
                throw new \InvalidArgumentException('Unknown display property.');
            }

            if ($value === null) {
                unset($props[$key]);
            } elseif ($key === 'colour' && \is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/D', $value)) {
                $props['colour'] = strtoupper($value);
            } elseif ($key === 'order' && \is_int($value) && $value >= 0 && $value <= 9999) {
                $props['order'] = $value;
            } else {
                throw new \InvalidArgumentException('Invalid display property.');
            }
        }

        ksort($props);

        return $props;
    }

    /**
     * @return  array{colour?: string, order?: int}  Only the valid stored properties.
     */
    public static function cleanClientProps(array $props): array
    {
        $clean = [];

        if (isset($props['colour']) && \is_string($props['colour']) && preg_match('/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/D', $props['colour'])) {
            $clean['colour'] = $props['colour'];
        }

        if (isset($props['order']) && \is_int($props['order']) && $props['order'] >= 0 && $props['order'] <= 9999) {
            $clean['order'] = $props['order'];
        }

        return $clean;
    }

    // ------------------------------------------------------------------
    // The one transaction helper of the internet calendar services
    // ------------------------------------------------------------------

    /**
     * Run work in a transaction that nests: inside another transaction (ours or
     * a caller's) it becomes a savepoint, never a second START TRANSACTION,
     * which in MySQL would silently commit the outer one and drop its locks.
     * Calendars whose epoch changed inside ({@see self::queueEpoch()}) are
     * handed to the anchor after our outermost commit with
     * `recordAfterCommit()`: the anchor reads the committed value itself, at
     * once or at the end of the request when a foreign transaction is still
     * open, so it never stands above a database that rolled back. A rollback
     * here drops the queue. Tombstones of deleted calendars
     * ({@see self::queueTombstone()}) have no row left to read; they are written
     * with their value, and only when no transaction is open at all.
     *
     * @template T
     * @param   callable():T  $work
     * @return  T
     */
    public static function transaction(TeamServices $services, callable $work): mixed
    {
        $db = $services->db();
        $mark = \count(self::$pendingEpochs);
        $db->transactionStart(true);
        self::$depth++;

        try {
            $result = $work();
        } catch (\Throwable $e) {
            self::$depth--;
            array_splice(self::$pendingEpochs, $mark);
            $db->transactionRollback(true);

            throw $e;
        }

        self::$depth--;
        $db->transactionCommit(true);

        if (self::$depth === 0) {
            $pending = self::$pendingEpochs;
            self::$pendingEpochs = [];
            $changed = [];
            $tombstones = [];

            foreach ($pending as [$calendarId, $epoch, $tombstone]) {
                if ($tombstone) {
                    $tombstones[$calendarId] = max($tombstones[$calendarId] ?? 0, $epoch);
                } else {
                    $changed[$calendarId] = $epoch;
                }
            }

            CalendarService::anchor($services, array_diff_key($changed, $tombstones));

            if ($tombstones !== [] && self::driverDepth($db) === 0) {
                foreach ($tombstones as $calendarId => $epoch) {
                    try {
                        $services->anchor()->record(EpochAnchorInterface::KIND_CALENDAR, $calendarId, $epoch);
                    } catch (\Throwable) {
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Queue the anchor value of a deleted calendar inside {@see self::transaction()}.
     */
    public static function queueTombstone(int $calendarId, int $epoch): void
    {
        if (self::$depth > 0 && $calendarId > 0 && $epoch > 0) {
            self::$pendingEpochs[] = [$calendarId, $epoch, true];
        }
    }

    /**
     * Queue an epoch raised inside {@see self::transaction()} for the anchor.
     * Outside one it is not recorded: the commit cannot be seen from here.
     */
    public static function queueEpoch(int $calendarId, int $epoch): void
    {
        if (self::$depth > 0 && $calendarId > 0 && $epoch > 0) {
            self::$pendingEpochs[] = [$calendarId, $epoch, false];
        }
    }

    /**
     * The transaction depth of the database driver; unknown counts as open.
     */
    private static function driverDepth(object $db): int
    {
        if (!$db instanceof \Joomla\Database\DatabaseDriver || !property_exists(\Joomla\Database\DatabaseDriver::class, 'transactionDepth')) {
            return 1;
        }

        try {
            $value = (new \ReflectionProperty(\Joomla\Database\DatabaseDriver::class, 'transactionDepth'))->getValue($db);
        } catch (\Throwable) {
            return 1;
        }

        return \is_int($value) ? $value : 1;
    }

    // ------------------------------------------------------------------
    // Shared writes, also used by the calendar and invitation services
    // ------------------------------------------------------------------

    /**
     * Insert or replace the grant of one user on one calendar, with a fresh MAC
     * over the given values only. Keeps `client_props` and `created_utc` of an
     * existing row. The caller has decided that the actor may do this.
     */
    public static function write(TeamServices $services, int $calendarId, int $userId, CalendarRole $role, int $state, string $audience, int $grantedBy): void
    {
        $db = $services->db();
        $fields = self::macFields([
            'calendar_id' => $calendarId, 'user_id' => $userId, 'role' => $role->value, 'state' => $state,
            'audience' => $audience, 'granted_by' => $grantedBy,
        ], self::calendarEpoch($services, $calendarId, true), self::userKeyVersion($services, $userId));
        $mac = $services->keys()->rowMac(self::TABLE, $fields);
        $now = $db->quote($services->now()->format('Y-m-d H:i:s'));
        $macSql = 'UNHEX(' . $db->quote(bin2hex($mac)) . ')';

        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__' . self::TABLE)
            . ' (calendar_id, user_id, role, state, audience, granted_by, row_mac, created_utc, modified_utc) VALUES ('
            . $calendarId . ', ' . $userId . ', ' . $role->value . ', ' . $state . ', ' . $db->quote($audience) . ', '
            . $grantedBy . ', ' . $macSql . ', ' . $now . ', ' . $now . ')'
            . ' ON DUPLICATE KEY UPDATE role = VALUES(role), state = VALUES(state), audience = VALUES(audience),'
            . ' granted_by = VALUES(granted_by), row_mac = VALUES(row_mac), modified_utc = VALUES(modified_utc)'
        )->execute();
    }

    /**
     * Remove every grant of an account and every key wrap it held, and mark
     * those calendars due for rotation (the one-step revoke of an activation).
     * Rows that do not verify go too: removing never grants.
     *
     * Raises the epoch of every calendar concerned; the caller records the
     * returned epochs in the anchor after the commit.
     *
     * @return  array<int, int>  Calendar id => new epoch, for every calendar the account had a grant on.
     */
    public static function removeAllForUser(TeamServices $services, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db = $services->db();
        $calendars = self::lockCalendarsOf($services, $userId);
        $wrapped = array_map('intval', $db->setQuery(
            'SELECT DISTINCT calendar_id FROM ' . $db->quoteName('#__appointments_calendar_key_wraps') . ' WHERE user_id = ' . $userId . ' FOR UPDATE'
        )->loadColumn() ?: []);
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE user_id = ' . $userId)->execute();

        foreach ($wrapped as $calendarId) {
            $services->keys()->deleteUserWraps($calendarId, $userId);
            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__appointments_calendars') . ' SET rotation_due = 1 WHERE id = ' . $calendarId
            )->execute();
        }

        $epochs = [];

        foreach ($calendars as $calendarId) {
            try {
                $epochs[$calendarId] = CalendarService::bumpEpoch($services, $calendarId);
                self::queueEpoch($calendarId, $epochs[$calendarId]);
            } catch (TeamException) {
                // A calendar row that does not verify grants nothing anyway.
                $epochs[$calendarId] = 0;
            }
        }

        return $epochs;
    }

    /**
     * The grant epoch of a calendar as stored (the calendar MAC is checked
     * separately), 0 for an unknown calendar.
     */
    public static function calendarEpoch(TeamServices $services, int $calendarId, bool $lock = false): int
    {
        $db = $services->db();

        // Inside a transaction that holds the calendar lock, a plain read could
        // return an older snapshot than the locked row; read it locking then.
        return (int) $db->setQuery(
            'SELECT grant_epoch FROM ' . $db->quoteName('#__appointments_calendars') . ' WHERE id = ' . $calendarId . ($lock ? ' FOR UPDATE' : '')
        )->loadResult();
    }

    /**
     * Lock every calendar the user has a grant row on, in id order (the same
     * order as every other path: calendar rows before grant rows), and return
     * their ids. Called inside a transaction.
     *
     * @return  int[]
     */
    public static function lockCalendarsOf(TeamServices $services, int $userId): array
    {
        $db = $services->db();
        // Calendars first (the order of every other path) ...
        $locked = array_map('intval', $db->setQuery(
            'SELECT id FROM ' . $db->quoteName('#__appointments_calendars') . ' WHERE id IN (SELECT calendar_id FROM '
            . $db->quoteName('#__' . self::TABLE) . ' WHERE user_id = ' . $userId . ') ORDER BY id FOR UPDATE'
        )->loadColumn() ?: []);

        // ... then the user's grant rows with a locking (current) read: the
        // list above may come from an older snapshot, so calendars of rows that
        // appeared meanwhile are locked as well.
        for ($round = 0; $round < 5; $round++) {
            $ids = array_map('intval', $db->setQuery(
                'SELECT calendar_id FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE user_id = ' . $userId . ' ORDER BY calendar_id FOR UPDATE'
            )->loadColumn() ?: []);
            $missing = array_values(array_diff($ids, $locked));

            if ($missing === []) {
                break;
            }

            sort($missing);
            $locked = array_merge($locked, array_map('intval', $db->setQuery(
                'SELECT id FROM ' . $db->quoteName('#__appointments_calendars') . ' WHERE id IN (' . implode(',', $missing) . ') ORDER BY id FOR UPDATE'
            )->loadColumn() ?: []));

            if (array_diff($missing, $locked) !== []) {
                // A grant row without a calendar cannot exist (foreign key); stop.
                break;
            }
        }

        sort($locked);

        return $locked;
    }

    /**
     * The key version of a user's key pair as stored, 0 without a key pair.
     */
    public static function userKeyVersion(TeamServices $services, int $userId): int
    {
        $db = $services->db();

        return (int) $db->setQuery(
            'SELECT key_version FROM ' . $db->quoteName('#__appointments_user_keys') . ' WHERE user_id = ' . $userId
        )->loadResult();
    }

    /**
     * Whether a grant row verifies against the current epoch of its calendar
     * and the current key version of its grantee. Without an epoch the calendar
     * row is loaded and verified first ({@see CalendarService::rowValid()}, MAC and
     * anchor), so an epoch set back in the database alone never makes an old
     * grant pass. For other packages: the key service completes wraps of
     * grants that pass this check only.
     */
    public static function rowValid(TeamServices $services, object $grant, ?int $grantEpoch = null, ?int $userKeyVersion = null): bool
    {
        $mac = (string) ($grant->row_mac ?? '');

        if (\strlen($mac) !== 32) {
            return false;
        }

        if ($grantEpoch === null) {
            $db = $services->db();
            $calendar = $db->setQuery(
                'SELECT * FROM ' . $db->quoteName('#__appointments_calendars') . ' WHERE id = ' . (int) $grant->calendar_id
            )->loadObject();

            if (!$calendar || !CalendarService::rowValid($services, $calendar)) {
                return false;
            }

            $grantEpoch = (int) $calendar->grant_epoch;
        }

        $fields = self::macFields(
            $grant,
            $grantEpoch,
            $userKeyVersion ?? self::userKeyVersion($services, (int) $grant->user_id)
        );

        return $services->keys()->verifyRowMac(self::TABLE, $fields, $mac);
    }

    /**
     * Inside {@see CalendarService::bumpEpoch()}: every grant of the calendar
     * that verifies under the old epoch gets a MAC under the new one. Rows that
     * do not verify keep their MAC and stay ineffective.
     */
    public static function remacGrants(TeamServices $services, int $calendarId, int $oldEpoch, int $newEpoch): void
    {
        $db = $services->db();
        $rows = $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE calendar_id = ' . $calendarId . ' FOR UPDATE'
        )->loadObjectList() ?: [];

        foreach ($rows as $row) {
            $version = self::userKeyVersion($services, (int) $row->user_id);

            if (self::rowValid($services, $row, $oldEpoch, $version)) {
                self::storeMac($services, $row, $newEpoch, $version);
            }
        }
    }

    /**
     * The key version of a user changed (first key pair, reset): every grant of
     * the user that verified under the previous version gets a MAC under the
     * current one. The key service calls this in the transaction that changed
     * `#__appointments_user_keys`, before it suspends anything.
     *
     * @return  int  Grants rebound.
     */
    public static function rebindUserKey(TeamServices $services, int $userId, int $previousKeyVersion): int
    {
        $db = $services->db();
        self::lockCalendarsOf($services, $userId);
        $rows = $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE user_id = ' . $userId . ' FOR UPDATE'
        )->loadObjectList() ?: [];
        $current = self::userKeyVersion($services, $userId);
        $rebound = 0;

        foreach ($rows as $row) {
            $epoch = self::calendarEpoch($services, (int) $row->calendar_id, true);

            if (self::rowValid($services, $row, $epoch, $previousKeyVersion)) {
                self::storeMac($services, $row, $epoch, $current);
                $rebound++;
            }
        }

        return $rebound;
    }

    private static function storeMac(TeamServices $services, object $row, int $grantEpoch, int $userKeyVersion): void
    {
        $db = $services->db();
        $mac = $services->keys()->rowMac(self::TABLE, self::macFields($row, $grantEpoch, $userKeyVersion));
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__' . self::TABLE) . ' SET row_mac = UNHEX(' . $db->quote(bin2hex($mac)) . ')'
            . ' WHERE id = ' . (int) $row->id
        )->execute();
    }

    /**
     * Whether an actor may share with external users now: the permission
     * `appointments.team.external` on the component, an unblocked account and an
     * active second factor.
     */
    public static function sharesExternally(TeamServices $services, int $actorUserId): bool
    {
        $actor = self::user($actorUserId);

        return $actor !== null && !$actor->block
            && $actor->authorise('appointments.team.external', 'com_appointments')
            && $services->grants()->hasSecondFactor($actorUserId);
    }

    /** Administrative authority is never a content grant or a decryption key. */
    public static function isAdministrator(int $actorUserId): bool
    {
        $actor = self::user($actorUserId);

        return $actor !== null && !$actor->block && $actor->authorise('core.admin', 'com_appointments');
    }

    public static function mayManageCalendar(TeamServices $services, int $actorUserId, object $calendar): bool
    {
        return self::isAdministrator($actorUserId)
            || self::accessUnderLock($services, $actorUserId, $calendar)?->role === CalendarRole::Manage;
    }

    /**
     * Unblocked accounts that may hold grants (team groups and the external
     * group), for the selection lists of the administrator.
     *
     * @return  object[]  id, name, username, audience.
     */
    public static function eligibleUsers(TeamServices $services): array
    {
        $db = $services->db();
        $team = array_values(array_filter(array_map('intval', (array) $services->params()->get('team_groups', [])), static fn (int $id): bool => $id > 0));
        $external = max(0, (int) $services->params()->get('external_group', 0));
        $groups = array_values(array_filter(array_merge($team, [$external])));

        if ($groups === []) {
            return [];
        }

        $rows = $db->setQuery(
            'SELECT u.id, u.name, u.username, GROUP_CONCAT(m.group_id) AS groups_csv FROM ' . $db->quoteName('#__users') . ' AS u'
            . ' INNER JOIN ' . $db->quoteName('#__user_usergroup_map') . ' AS m ON m.user_id = u.id'
            . ' WHERE u.block = 0 GROUP BY u.id, u.name, u.username'
            . ' HAVING SUM(m.group_id IN (' . implode(',', $groups) . ')) > 0'
            . ' ORDER BY u.name, u.id LIMIT 1000'
        )->loadObjectList() ?: [];

        foreach ($rows as $row) {
            $row->id = (int) $row->id;
            $row->audience = self::audienceFromGroups(explode(',', (string) $row->groups_csv), $team, $external);
            unset($row->groups_csv);
        }

        return $rows;
    }

    /**
     * The Joomla account, or null when it does not exist.
     */
    public static function user(int $userId): ?User
    {
        if ($userId <= 0) {
            return null;
        }

        // Joomla logs a warning for every id it cannot load, which the
        // administrator shows as a message; look the id up first.
        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

        if ((int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__users') . ' WHERE id = ' . $userId)->loadResult() !== 1) {
            return null;
        }

        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);

        return $user instanceof User && (int) $user->id === $userId ? $user : null;
    }

    // ------------------------------------------------------------------
    // GrantServiceInterface
    // ------------------------------------------------------------------

    public function accessFor(int $userId, int $calendarId): ?CalendarAccess
    {
        if ($userId <= 0 || $calendarId <= 0) {
            return null;
        }

        $calendar = $this->services->calendars()->get($calendarId);
        $grant = $calendar === null ? null : $this->grantRow($calendarId, $userId);

        return $grant === null ? null : $this->effective($calendar, $grant);
    }

    /**
     * Authorise a mutation against its already-locked calendar and current
     * grant/wrap rows, never a caller's older REPEATABLE READ snapshot.
     * The caller must hold the calendar row lock until its mutation commits.
     */
    public static function accessUnderLock(TeamServices $services, int $userId, object $calendar): ?CalendarAccess
    {
        if ($userId <= 0 || !CalendarService::rowValid($services, $calendar)) {
            return null;
        }

        $grants = new self($services);
        $grant = $grants->grantRow((int) $calendar->id, $userId, true);

        return $grant === null ? null : $grants->effective($calendar, $grant, true);
    }

    public function calendarsFor(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db = $this->services->db();
        $rows = $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE user_id = ' . $userId . ' ORDER BY calendar_id'
        )->loadObjectList() ?: [];
        $accesses = [];

        foreach ($rows as $grant) {
            $calendar = $this->services->calendars()->get((int) $grant->calendar_id);
            $access = $calendar === null ? null : $this->effective($calendar, $grant);

            if ($access !== null) {
                $accesses[] = $access;
            }
        }

        return $accesses;
    }

    public function grantsOf(int $calendarId): array
    {
        $db = $this->services->db();
        $calendar = $this->services->calendars()->get($calendarId);

        if ($calendar === null) {
            return [];
        }

        $rows = $db->setQuery(
            'SELECT g.*, u.name AS user_name, u.username AS user_username FROM ' . $db->quoteName('#__' . self::TABLE) . ' AS g'
            . ' LEFT JOIN ' . $db->quoteName('#__users') . ' AS u ON u.id = g.user_id'
            . ' WHERE g.calendar_id = ' . $calendarId . ' ORDER BY g.role DESC, u.name, g.user_id'
        )->loadObjectList() ?: [];
        $result = [];

        foreach ($rows as $row) {
            $result[] = (object) [
                'user_id'       => (int) $row->user_id,
                'user_name'     => (string) ($row->user_name ?? ''),
                'user_username' => (string) ($row->user_username ?? ''),
                'role'          => (int) $row->role,
                'state'         => (int) $row->state,
                'audience'      => (string) $row->audience,
                'current_audience' => $this->audienceOf((int) $row->user_id),
                'granted_by'    => (int) $row->granted_by,
                'created_utc'   => (string) $row->created_utc,
                'modified_utc'  => (string) $row->modified_utc,
                'mac_valid'     => $this->grantMacValid($row, (int) $calendar->grant_epoch),
                'effective'     => $this->effective($calendar, $row) !== null,
                'is_owner'      => (int) $row->user_id === (int) $calendar->owner_user_id,
            ];
        }

        return $result;
    }

    public function audienceOf(int $userId): string
    {
        if ($userId <= 0) {
            return self::AUDIENCE_NONE;
        }

        $db = $this->services->db();
        $groups = $db->setQuery(
            'SELECT group_id FROM ' . $db->quoteName('#__user_usergroup_map') . ' WHERE user_id = ' . $userId
        )->loadColumn() ?: [];

        return self::audienceFromGroups($groups, $this->teamGroups(), $this->externalGroup());
    }

    public function hasSecondFactor(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        // An account Joomla never asks for a second factor has none, whatever
        // it set up (com_users option «never enforce MFA»).
        $never = array_values(array_filter(array_map('intval', (array) ComponentHelper::getParams('com_users')->get('neverMFAUserGroups', []))));

        if ($never !== [] && array_intersect($never, array_map('intval', Access::getGroupsByUser($userId, true))) !== []) {
            return false;
        }

        $db = $this->services->db();

        // Only real second factors count (an allowlist: authenticator app,
        // security key, YubiKey), and only while their plugin is enabled. Not
        // backup codes, not the e-mail method (the same channel as a password
        // reset), not a fixed code (a second password), not unknown plugins.
        $methods = implode(', ', array_map([$db, 'quote'], self::SECOND_FACTOR_METHODS));

        return (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__user_mfa') . ' AS m'
            . ' INNER JOIN ' . $db->quoteName('#__extensions') . ' AS e ON e.type = ' . $db->quote('plugin')
            . ' AND e.folder = ' . $db->quote('multifactorauth') . ' AND e.element = m.method AND e.enabled = 1'
            . ' WHERE m.user_id = ' . $userId . ' AND m.method IN (' . $methods . ')'
        )->loadResult() > 0;
    }

    public function canGrant(int $actorUserId, int $calendarId, int $granteeUserId, CalendarRole $role): bool
    {
        $calendar = $this->services->calendars()->get($calendarId);

        return $calendar !== null && $this->canGrantOnCalendar($actorUserId, $calendar, $granteeUserId, $role);
    }

    private function canGrantOnCalendar(int $actorUserId, object $calendar, int $granteeUserId, CalendarRole $role, bool $locked = false): bool
    {
        $grantee = self::user($granteeUserId);

        if ((int) $calendar->state !== 1 || $grantee === null || $grantee->block || $actorUserId <= 0) {
            return false;
        }

        $audience = $this->audienceOf($granteeUserId);

        return self::mayGrant(
            ($locked ? self::accessUnderLock($this->services, $actorUserId, $calendar) : $this->accessFor($actorUserId, (int) $calendar->id))?->role,
            $audience === TeamStates::AUDIENCE_EXTERNAL && self::sharesExternally($this->services, $actorUserId),
            $audience,
            $role,
            (int) $calendar->allow_external === 1,
            $granteeUserId === (int) $calendar->owner_user_id,
            $granteeUserId === $actorUserId,
            self::isAdministrator($actorUserId) && $this->hasSecondFactor($actorUserId)
        );
    }

    public function grant(int $actorUserId, int $calendarId, int $granteeUserId, CalendarRole $role, ?KeyContext $actorKeys, bool $makeOwner = false): void
    {
        $this->assertEnabled();
        $keys = $actorKeys !== null && $actorKeys->userId() === $actorUserId ? $actorKeys : null;
        $result = $this->atomic(function () use ($actorUserId, $calendarId, $granteeUserId, $role, $keys, $makeOwner): array {
            // Decided under the calendar lock, so a concurrent revoke of the
            // actor's own grant cannot slip between the check and the write.
            $locked = $this->lockCalendar($calendarId);

            if ($locked === null || !CalendarService::rowValid($this->services, $locked)
                || !$this->canGrantOnCalendar($actorUserId, $locked, $granteeUserId, $role, true)) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
            }

            $previous = $this->grantRow($calendarId, $granteeUserId, true);
            $previousRole = $previous !== null && $this->grantMacValid($previous, (int) $locked->grant_epoch)
                ? CalendarRole::tryFrom((int) $previous->role) : null;
            $audience = $this->audienceOf($granteeUserId);
            if ($makeOwner && (!self::isAdministrator($actorUserId) || !$this->hasSecondFactor($actorUserId) || $role !== CalendarRole::Manage
                || $audience !== TeamStates::AUDIENCE_TEAM)) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
            }

            $previousOwner = (int) $locked->owner_user_id;
            $notificationRecipients = $this->notificationRecipients($locked);
            $state = $role->holdsKey() ? TeamStates::GRANT_PENDING_KEY : TeamStates::GRANT_ACTIVE;
            self::queueEpoch($calendarId, CalendarService::bumpEpoch($this->services, $calendarId));
            self::write($this->services, $calendarId, $granteeUserId, $role, $state, $audience, $actorUserId);

            if (!$role->holdsKey()) {
                // Free/busy holds no key: whatever the user held before goes.
                $this->dropKeyAccess($calendarId, $granteeUserId);
            } elseif ($keys !== null && $this->wrapNow($calendarId, $granteeUserId, $keys)) {
                self::write($this->services, $calendarId, $granteeUserId, $role, TeamStates::GRANT_ACTIVE, $audience, $actorUserId);
            }

            if ($makeOwner) {
                CalendarService::setOwner($this->services, $calendarId, $granteeUserId);
            }

            return ['previous' => $previous !== null, 'previous_role' => $previousRole, 'audience' => $audience,
                'previous_owner' => $previousOwner, 'notification_recipients' => $notificationRecipients];
        });

        if ($result['previous_role'] !== null && $result['previous_role']->holdsKey() && !$role->holdsKey() && $keys !== null) {
            $this->rotateNow($calendarId, $keys);
        }

        $this->log($result['previous'] ? AuditEvent::GRANT_CHANGED : AuditEvent::GRANT_CREATED, [
            'actor_user_id' => $actorUserId, 'subject_user_id' => $granteeUserId, 'calendar_id' => $calendarId,
            'details' => ['role' => $role->name, 'previous_role' => $result['previous_role']?->name, 'audience' => $result['audience']],
        ]);
        if ($makeOwner) {
            $this->log(AuditEvent::CALENDAR_UPDATED, [
                'actor_user_id' => $actorUserId, 'subject_user_id' => $granteeUserId, 'calendar_id' => $calendarId,
                'details' => ['reason' => 'owner_assigned', 'previous_owner' => $result['previous_owner']],
            ]);
        }
        $this->mailGrantChanged($calendarId, $granteeUserId, $role, $actorUserId, $result['notification_recipients']);
    }

    public function revoke(int $actorUserId, int $calendarId, int $granteeUserId, ?KeyContext $actorKeys): void
    {
        $this->assertEnabled();
        $grant = $this->atomic(function () use ($actorUserId, $calendarId, $granteeUserId): object {
            $locked = $this->lockCalendar($calendarId);
            $calendar = $locked !== null && CalendarService::rowValid($this->services, $locked) ? $locked : null;
            $grant = $calendar === null ? null : $this->grantRow($calendarId, $granteeUserId, true);

            if ($grant === null) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
            }

            // The stored audience counts only when the row verifies; otherwise
            // the account's groups alone decide.
            $granteeAudience = $this->audienceOf($granteeUserId);
            $allowed = self::mayRevoke(
                self::accessUnderLock($this->services, $actorUserId, $calendar)?->role,
                self::sharesExternally($this->services, $actorUserId),
                $this->grantMacValid($grant, (int) $calendar->grant_epoch) ? (string) $grant->audience : $granteeAudience,
                $granteeAudience,
                $granteeUserId === (int) $calendar->owner_user_id,
                $granteeUserId === $actorUserId,
                self::isAdministrator($actorUserId)
            );

            if (!$allowed) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
            }

            $grant->notification_recipient_ids = $this->notificationRecipients($calendar);

            $db = $this->services->db();
            $db->setQuery(
                'DELETE FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE calendar_id = ' . $calendarId . ' AND user_id = ' . $granteeUserId
            )->execute();
            $this->dropKeyAccess($calendarId, $granteeUserId);
            if ($granteeUserId === (int) $calendar->owner_user_id) {
                CalendarService::setOwner($this->services, $calendarId, 0);
            }
            $grant->owner_user_id = (int) $calendar->owner_user_id;
            self::queueEpoch($calendarId, CalendarService::bumpEpoch($this->services, $calendarId));

            return $grant;
        });

        $role = CalendarRole::tryFrom((int) $grant->role);
        $keys = $actorKeys !== null && $actorKeys->userId() === $actorUserId ? $actorKeys : null;

        if ($keys !== null) {
            $this->rotateNow($calendarId, $keys);
        }

        $this->log(AuditEvent::GRANT_REVOKED, [
            'actor_user_id' => $actorUserId, 'subject_user_id' => $granteeUserId, 'calendar_id' => $calendarId,
            'details' => ['role' => $role?->name, 'audience' => (string) $grant->audience],
        ]);
        if ($granteeUserId === (int) ($grant->owner_user_id ?? 0)) {
            $this->log(AuditEvent::CALENDAR_UPDATED, [
                'actor_user_id' => $actorUserId, 'subject_user_id' => $granteeUserId, 'calendar_id' => $calendarId,
                'details' => ['reason' => 'owner_revoked'],
            ]);
        }
        $this->mailGrantChanged($calendarId, $granteeUserId, null, $actorUserId, $grant->notification_recipient_ids);
    }

    public function suspendAllForUser(int $userId, string $reason): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $reason = preg_match('/^[a-z_]{1,32}$/D', $reason) ? $reason : 'other';
        $suspended = $this->atomic(function () use ($userId): array {
            $done = [];

            foreach (self::lockCalendarsOf($this->services, $userId) as $calendarId) {
                $locked = $this->lockCalendar($calendarId);
                $current = $this->grantRow($calendarId, $userId, true);
                $valid = $locked !== null && $current !== null && CalendarService::rowValid($this->services, $locked)
                    && $this->grantMacValid($current, (int) $locked->grant_epoch);
                $role = $current === null ? null : CalendarRole::tryFrom((int) $current->role);

                // Every calendar the user has any row on gets a new epoch, also
                // when the row does not verify (for example because the user's
                // key row changed): such a row can then never verify again.
                try {
                    self::queueEpoch($calendarId, CalendarService::bumpEpoch($this->services, $calendarId));
                } catch (TeamException) {
                    // The calendar row does not verify: nothing on it is effective.
                    continue;
                }

                // A forged row is never given a valid MAC, not even as suspended.
                if (!$valid || $role === null || (int) $current->state === TeamStates::GRANT_SUSPENDED) {
                    continue;
                }

                self::write($this->services, $calendarId, $userId, $role, TeamStates::GRANT_SUSPENDED, (string) $current->audience, (int) $current->granted_by);
                $done[] = $calendarId;
            }

            return $done;
        });

        foreach ($suspended as $calendarId) {
            $this->log(AuditEvent::GRANT_SUSPENDED, [
                'subject_user_id' => $userId, 'calendar_id' => $calendarId, 'details' => ['reason' => $reason],
            ]);
        }

        return \count($suspended);
    }

    public function setClientProps(int $userId, int $calendarId, array $props): void
    {
        $access = $this->accessFor($userId, $calendarId);

        if ($access === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
        }

        try {
            $merged = self::mergeClientProps($access->clientProps, $props);
        } catch (\InvalidArgumentException $e) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403, $e);
        }

        $db = $this->services->db();
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__' . self::TABLE) . ' SET client_props = '
            . ($merged === [] ? 'NULL' : $db->quote(json_encode($merged, JSON_THROW_ON_ERROR)))
            . ' WHERE calendar_id = ' . $calendarId . ' AND user_id = ' . $userId
        )->execute();
    }

    public function grantWithRecovery(int $actorUserId, CalendarKeyring $keyring, int $granteeUserId, CalendarRole $role, bool $makeOwner = false): void
    {
        $this->assertEnabled();
        $calendarId = $keyring->calendarId();
        $audience = null;
        $notificationRecipients = [];
        $previous = $this->atomic(function () use ($actorUserId, $calendarId, $granteeUserId, $role, $keyring, $makeOwner, &$audience, &$notificationRecipients): bool {
            // Decided under the calendar lock: a concurrent switch-off of
            // external users or a second owner change cannot slip in.
            $locked = $this->lockCalendar($calendarId);
            $calendar = $locked !== null && CalendarService::rowValid($this->services, $locked) ? $locked : null;
            $grantee = self::user($granteeUserId);
            $actor = self::user($actorUserId);

            if ($calendar === null || (int) $calendar->state !== 1 || $grantee === null || $grantee->block) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
            }

            $audience = $this->audienceOf($granteeUserId);
            $ownerId = (int) $calendar->owner_user_id;
            $allowed = self::mayGrantWithRecovery(
                $actor !== null && !$actor->block && $actor->authorise('core.admin', 'com_appointments') && $this->hasSecondFactor($actorUserId),
                $audience === TeamStates::AUDIENCE_EXTERNAL && self::sharesExternally($this->services, $actorUserId),
                $audience,
                $role,
                (int) $calendar->allow_external === 1,
                $granteeUserId === $ownerId
            );
            $ownerVacant = $ownerId === 0 || self::accessUnderLock($this->services, $ownerId, $calendar) === null;

            if (!$allowed || ($makeOwner && ($role !== CalendarRole::Manage || $audience !== TeamStates::AUDIENCE_TEAM || !$ownerVacant))) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
            }

            $notificationRecipients = $this->notificationRecipients($calendar);
            $previous = $this->grantRow($calendarId, $granteeUserId, true) !== null;
            self::queueEpoch($calendarId, CalendarService::bumpEpoch($this->services, $calendarId));

            if (!$role->holdsKey()) {
                self::write($this->services, $calendarId, $granteeUserId, $role, TeamStates::GRANT_ACTIVE, $audience, $actorUserId);
                $this->dropKeyAccess($calendarId, $granteeUserId);
            } else {
                // The keyring was opened before the lock: when the key rotated
                // meanwhile, its current version is not the calendar's any more,
                // and the grant waits for the next key holder instead.
                $wrapped = $keyring->current()->version() === (int) $calendar->key_version
                    && $this->services->keys()->wrapForUser($keyring->current(), $granteeUserId);
                $state = $wrapped ? TeamStates::GRANT_ACTIVE : TeamStates::GRANT_PENDING_KEY;
                self::write($this->services, $calendarId, $granteeUserId, $role, $state, $audience, $actorUserId);
            }

            if ($makeOwner) {
                CalendarService::setOwner($this->services, $calendarId, $granteeUserId);
            }

            return $previous;
        });

        try {
            $this->services->keys()->completePendingWraps($keyring);
        } catch (\Throwable) {
            // Completion is opportunistic; the next key holder's request repeats it.
        }

        $this->log(AuditEvent::RECOVERY_USED, [
            'actor_user_id' => $actorUserId, 'subject_user_id' => $granteeUserId, 'calendar_id' => $calendarId,
            'details' => ['role' => $role->name, 'owner' => $makeOwner ? 1 : 0],
        ]);
        $this->log($previous ? AuditEvent::GRANT_CHANGED : AuditEvent::GRANT_CREATED, [
            'actor_user_id' => $actorUserId, 'subject_user_id' => $granteeUserId, 'calendar_id' => $calendarId,
            'details' => ['role' => $role->name, 'audience' => $audience, 'recovery' => 1],
        ]);
        $this->mailGrantChanged($calendarId, $granteeUserId, $role, $actorUserId, $notificationRecipients);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function effective(object $calendar, object $grant, bool $locked = false): ?CalendarAccess
    {
        $calendarId = (int) $calendar->id;
        $userId = (int) $grant->user_id;
        $role = CalendarRole::tryFrom((int) $grant->role);
        $account = null;
        $loadAccount = function () use (&$account, $userId): ?User {
            return $account ??= self::user($userId);
        };
        $effective = self::isEffective([
            'role'                     => $role,
            'grant_mac'                => fn (): bool => $this->grantMacValid($grant, (int) $calendar->grant_epoch),
            'grant_state'              => (int) $grant->state,
            'grant_audience'           => (string) $grant->audience,
            // get() returns only rows whose MAC verified.
            'calendar_mac'             => (int) $calendar->id === (int) $grant->calendar_id,
            'calendar_state'           => (int) $calendar->state,
            'calendar_allows_external' => (int) $calendar->allow_external === 1,
            'account_exists'           => fn (): bool => $loadAccount() !== null,
            'account_blocked'          => fn (): bool => (bool) $loadAccount()?->block,
            'audience'                 => fn (): string => $this->audienceOf($userId),
            'second_factor'            => fn (): bool => $this->hasSecondFactor($userId),
            'wrap'                     => fn (): bool => $this->wrapExists($calendarId, (int) $calendar->key_version, $userId, $locked),
        ]);

        if (!$effective || $role === null) {
            return null;
        }

        $props = json_decode((string) ($grant->client_props ?? ''), true);

        return new CalendarAccess(
            $calendarId,
            (string) $calendar->uri,
            $userId,
            $role,
            (string) $grant->audience,
            (string) $calendar->title,
            (string) $calendar->colour,
            (int) $calendar->synctoken,
            self::cleanClientProps(\is_array($props) ? $props : [])
        );
    }

    /**
     * Serialise every grant decision of one calendar.
     */
    /**
     * Lock the calendar row and return it as read under the lock (a current
     * read, never an older snapshot), or null when it does not exist.
     */
    private function lockCalendar(int $calendarId): ?object
    {
        $db = $this->services->db();

        return $db->setQuery('SELECT * FROM ' . $db->quoteName('#__appointments_calendars') . ' WHERE id = ' . $calendarId . ' FOR UPDATE')->loadObject() ?: null;
    }

    private function grantRow(int $calendarId, int $userId, bool $forUpdate = false): ?object
    {
        if ($calendarId <= 0 || $userId <= 0) {
            return null;
        }

        $db = $this->services->db();

        return $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE calendar_id = ' . $calendarId
            . ' AND user_id = ' . $userId . ($forUpdate ? ' FOR UPDATE' : '')
        )->loadObject() ?: null;
    }

    private function grantMacValid(object $row, ?int $grantEpoch = null): bool
    {
        return self::rowValid($this->services, $row, $grantEpoch);
    }



    private function wrapExists(int $calendarId, int $keyVersion, int $userId, bool $locked = false): bool
    {
        $db = $this->services->db();

        return (int) $db->setQuery(
            'SELECT user_id FROM ' . $db->quoteName('#__appointments_calendar_key_wraps') . ' WHERE calendar_id = ' . $calendarId
            . ' AND key_version = ' . $keyVersion . ' AND user_id = ' . $userId
            . ($locked ? ' FOR UPDATE' : '')
        )->loadResult() > 0;
    }

    /**
     * The user no longer holds the calendar key: every wrap goes and the key is
     * due for rotation (only when the user held a wrap).
     */
    private function dropKeyAccess(int $calendarId, int $userId): void
    {
        $db = $this->services->db();
        $held = (int) $db->setQuery(
            'SELECT user_id FROM ' . $db->quoteName('#__appointments_calendar_key_wraps') . ' WHERE calendar_id = ' . $calendarId
            . ' AND user_id = ' . $userId . ' LIMIT 1 FOR UPDATE'
        )->loadResult() > 0;

        if (!$held) {
            return;
        }

        $this->services->keys()->deleteUserWraps($calendarId, $userId);
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__appointments_calendars') . ' SET rotation_due = 1 WHERE id = ' . $calendarId
        )->execute();
    }

    /**
     * Wrap the current key version for the grantee with the actor's keyring.
     * False when the actor holds no keyring or the grantee has no key pair yet;
     * the grant then waits for the next key holder's request.
     */
    private function wrapNow(int $calendarId, int $granteeUserId, KeyContext $keys): bool
    {
        $keyring = $this->services->keys()->openKeyring($calendarId, $keys);

        if ($keyring === null) {
            return false;
        }

        try {
            return $this->services->keys()->wrapForUser($keyring->current(), $granteeUserId);
        } finally {
            $keyring->wipe();
        }
    }

    private function rotateNow(int $calendarId, KeyContext $keys): void
    {
        try {
            $keyring = $this->services->keys()->openKeyring($calendarId, $keys);

            if ($keyring !== null) {
                $this->services->keys()->rotateCalendarKey($keyring)->wipe();
                $keyring->wipe();
            }
        } catch (TeamException) {
            // The rotation stays due (`rotation_due`) and the next key holder's request does it.
        }
    }

    /**
     * @return  int[]
     */
    private function teamGroups(): array
    {
        $groups = $this->services->params()->get('team_groups', []);

        return array_values(array_filter(array_map('intval', (array) $groups), static fn (int $id): bool => $id > 0));
    }

    private function externalGroup(): int
    {
        return max(0, (int) $this->services->params()->get('external_group', 0));
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
        return self::transaction($this->services, $work);
    }

    /**
     * The audit service never fails the operation it records (its contract);
     * this guard also keeps a broken log from undoing a committed change.
     */
    private function log(string $event, array $context): void
    {
        try {
            $this->services->audit()->log($event, $context);
        } catch (\Throwable) {
        }
    }

    /** Capture before changing grants, while the calendar lock is held. */
    private function notificationRecipients(object $calendar): array
    {
        $recipients = [(int) $calendar->owner_user_id];
        foreach ($this->grantsOf((int) $calendar->id) as $grant) {
            if ($grant->effective && $grant->role === CalendarRole::Manage->value) {
                $recipients[] = (int) $grant->user_id;
            }
        }

        return array_values(array_unique(array_filter($recipients)));
    }

    private function mailGrantChanged(int $calendarId, int $granteeUserId, ?CalendarRole $role, int $actorUserId, array $priorRecipientUserIds = []): void
    {
        try {
            $this->services->mailer()->grantChanged($calendarId, $granteeUserId, self::roleLabelKey($role), $actorUserId, $priorRecipientUserIds);
        } catch (\Throwable) {
            // A mail failure never undoes a grant decision; the log holds it.
        }
    }
}
