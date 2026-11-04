<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\AppPassword;
use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\DeviceSession;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

defined('_JEXEC') or die;

/**
 * Devices and their app passwords. Contract: {@see DeviceServiceInterface}.
 *
 * Row MAC of `#__appointments_devices` (table `appointments_devices`), in this
 * order: `id`, `user_id`, `secret_hash`, `kek_salt`, `usk_wrapped`,
 * `user_key_version`, `state` and the user's `device_epoch` from
 * `#__appointments_user_keys` ({@see macFields()}). Integers as integers, binary columns as raw
 * bytes, `usk_wrapped` null when cleared. A row whose MAC does not verify never
 * authenticates, and no method of this class ever writes a new MAC over a row
 * whose old MAC did not verify, so a tampered row stays detectable.
 *
 * Name, client, times and the revocation columns are not covered: they carry
 * no access. A device that leaves the active state loses `usk_wrapped`, so a
 * changed state or a restored key alone does not make it active again. An older
 * row played back as a whole is caught by the user's `device_epoch`, which is
 * part of the MAC, rises with every revocation and clean-up step and is anchored
 * outside the database (specification 5.11).
 *
 * @since  1.4.0
 */
final class DeviceService implements DeviceServiceInterface
{
    /** The clients «Add device» offers, in the order of the form. */
    public const CLIENTS = ['outlook-caldavsynchronizer', 'apple', 'davx5', 'thunderbird', 'emclient', 'other'];

    /** Name of each client in the interface and in the mails. */
    public const CLIENT_LABELS = [
        'outlook-caldavsynchronizer' => 'COM_APPOINTMENTS_TEAM_D_CLIENT_OUTLOOK',
        'apple' => 'COM_APPOINTMENTS_TEAM_D_CLIENT_APPLE',
        'davx5' => 'COM_APPOINTMENTS_TEAM_D_CLIENT_DAVX5',
        'thunderbird' => 'COM_APPOINTMENTS_TEAM_D_CLIENT_THUNDERBIRD',
        'emclient' => 'COM_APPOINTMENTS_TEAM_D_CLIENT_EMCLIENT',
        'other' => 'COM_APPOINTMENTS_TEAM_D_CLIENT_OTHER',
    ];

    /** Longest device name, as the column allows. */
    public const NAME_MAX = 100;

    /** Seconds between two writes of «last synchronised» for one device or one device and calendar pair. */
    public const TOUCH_INTERVAL = 60;

    private const TABLE = 'appointments_devices';

    /**
     * Devices whose time this request has written already, so a sync over several calendars
     * costs one statement for the device row.
     *
     * @var  array<int, true>
     */
    private $touched = [];

    public function __construct(private readonly TeamServices $services)
    {
    }

    /**
     * The device name as stored, or null when it is not acceptable: 1 to 100
     * characters, letters, digits, spaces and common punctuation, starting with
     * a letter or digit. Pure, for the form and the tests.
     */
    public static function normaliseName(string $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX
            || preg_match('/^[\p{L}\p{N}][\p{L}\p{M}\p{N} \-_.,\'’()&+\/#:]*$/u', $name) !== 1
        ) {
            return null;
        }

        return $name;
    }

    public static function isClient(string $client): bool
    {
        return \in_array($client, self::CLIENTS, true);
    }

    public function create(KeyContext $keys, string $name, string $client, bool $ruleAccepted): array
    {
        $this->requireEnabled();
        $userId = $keys->userId();

        if (!$ruleAccepted) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_RULE', 403);
        }

        $name = self::normaliseName($name);

        if ($name === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_NAME', 400);
        }

        if (!self::isClient($client)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_CLIENT', 400);
        }

        $this->requireEligible($userId);
        $db = $this->services->db();
        $lock = $db->getPrefix() . 'com_appointments.device.' . $userId;

        if ((int) $db->setQuery('SELECT GET_LOCK(' . $db->quote($lock) . ', 10)')->loadResult() !== 1) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_BUSY', 409);
        }

        $password = AppPassword::generate();

        try {
            // One name per device and user: two devices with the same name could not be told
            // apart in the list, and a form sent twice must not create a second device.
            $now = $this->services->now();
            $nowSql = $now->format('Y-m-d H:i:s');
            $salt = random_bytes(AppPassword::SALT_BYTES);
            $secretHash = AppPassword::hash($password['raw'], $this->services->keyProvider());
            $wrapped = $this->services->keys()->wrapForDevice($keys, $password['raw'], $salt);
            $keyVersion = $keys->userKeyVersion();
            $state = TeamStates::DEVICE_ACTIVE;
            $rule = TeamStates::DEVICE_RULE_VERSION;
            // A placeholder that can never verify, replaced in the same transaction once the id is known.
            $placeholder = random_bytes(32);

            // A savepoint when a caller already runs a transaction, never an implicit commit of its work.
            $db->transactionStart(true);

            try {
                // The user key row is locked, so no revocation raises the epoch in between, and
                // only a verified, anchored epoch is ever signed into a device row.
                $epoch = $this->trustedEpoch($this->lockUserKey($userId), $userId);

                if ($epoch === null) {
                    throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_ACCOUNT', 403);
                }

                // Again under the locks: the account, its second factor or its last grant may have
                // gone while this request waited. (A grant revoked after this point still leaves the
                // device without data: every CalDAV request checks the effective grants anew.)
                $this->requireEligible($userId);

                // One name per device and user: two devices with the same name could not be told
                // apart in the list, and a form sent twice must not create a second device.
                if ($this->nameTaken($userId, $name, 0)) {
                    throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_NAME_TAKEN', 409);
                }

                $query = $this->query()
                    ->insert($db->quoteName('#__' . self::TABLE))
                    ->columns($db->quoteName([
                        'user_id', 'name', 'client', 'secret_hash', 'kek_salt', 'usk_wrapped', 'user_key_version',
                        'state', 'rule_version', 'rule_accepted_utc', 'created_utc', 'row_mac',
                    ]))
                    ->values(':user, :name, :client, :hash, :salt, :wrapped, :version, :state, :rule, :accepted, :created, :mac')
                    ->bind(':user', $userId, ParameterType::INTEGER)
                    ->bind(':name', $name)
                    ->bind(':client', $client)
                    ->bind(':hash', $secretHash, ParameterType::LARGE_OBJECT)
                    ->bind(':salt', $salt, ParameterType::LARGE_OBJECT)
                    ->bind(':wrapped', $wrapped, ParameterType::LARGE_OBJECT)
                    ->bind(':version', $keyVersion, ParameterType::INTEGER)
                    ->bind(':state', $state, ParameterType::INTEGER)
                    ->bind(':rule', $rule)
                    ->bind(':accepted', $nowSql)
                    ->bind(':created', $nowSql)
                    ->bind(':mac', $placeholder, ParameterType::LARGE_OBJECT);
                $db->setQuery($query)->execute();
                $deviceId = (int) $db->insertid();

                $mac = $this->mac([
                    'id' => $deviceId, 'user_id' => $userId, 'secret_hash' => $secretHash, 'kek_salt' => $salt,
                    'usk_wrapped' => $wrapped, 'user_key_version' => $keyVersion, 'state' => $state, 'device_epoch' => $epoch,
                ]);
                $query = $this->query()
                    ->update($db->quoteName('#__' . self::TABLE))
                    ->set($db->quoteName('row_mac') . ' = :mac')
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':mac', $mac, ParameterType::LARGE_OBJECT)
                    ->bind(':id', $deviceId, ParameterType::INTEGER);
                $db->setQuery($query)->execute();

                // The confirmation of the usage rule is part of the record: version in the
                // row and in the log, time in both. No name, it is the user's own text.
                $this->services->audit()->log(AuditEvent::DEVICE_CREATED, [
                    'actor_user_id' => $userId,
                    'subject_user_id' => $userId,
                    'details' => ['device_id' => $deviceId, 'client' => $client, 'rule_version' => $rule, 'rule_accepted_utc' => $nowSql],
                    'ip' => $this->ip(),
                ]);

                $db->transactionCommit(true);
            } catch (\Throwable $e) {
                $db->transactionRollback(true);

                throw $e;
            }
        } finally {
            $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')')->execute();

            if (\function_exists('sodium_memzero')) {
                sodium_memzero($password['raw']);
            }
        }

        $mailed = true;

        try {
            $this->services->mailer()->deviceAdded($userId, $name, $client, $now);
        } catch (\Throwable $e) {
            // The device exists; the page tells the user that the notice could not be sent.
            // Only the device id is logged: a transport error can carry addresses.
            Log::add('Internet calendar device notice failed: device ' . $deviceId, Log::WARNING, 'com_appointments');
            $mailed = false;
        }

        return ['device_id' => $deviceId, 'app_password' => $password['display'], 'mail_sent' => $mailed];
    }

    /**
     * The account may add a device now: not blocked, an active second factor, an effective grant.
     *
     * @throws  TeamException  403 otherwise.
     */
    private function requireEligible(int $userId): void
    {
        $account = $this->account($userId);

        if ($account === null || (int) $account->block !== 0) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_ACCOUNT', 403);
        }

        $grants = $this->services->grants();

        if (!$grants->hasSecondFactor($userId)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MFA', 403);
        }

        if ($grants->calendarsFor($userId) === []) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_NO_GRANT', 403);
        }
    }

    public function authenticate(string $username, #[\SensitiveParameter] string $appPassword): ?DeviceSession
    {
        // Anything that is not an app password, a Joomla password above all, is refused here,
        // before any database access.
        $raw = AppPassword::parse($appPassword);

        if ($raw === null || $username === '' || !$this->services->enabled()) {
            return null;
        }

        try {
            return $this->deviceSession($username, $raw);
        } catch (TeamException | \InvalidArgumentException | \LogicException $e) {
            // Whatever depends on the data ends in null, like every other refusal; only an
            // infrastructure failure (the database is gone) reaches the caller as an exception,
            // so the answer is no oracle for the state of a row.
            return null;
        } finally {
            if (\function_exists('sodium_memzero')) {
                sodium_memzero($raw);
            }
        }
    }

    /**
     * The checks of authenticate() for a well-formed app password.
     */
    private function deviceSession(string $username, #[\SensitiveParameter] string $raw): ?DeviceSession
    {
        $db = $this->services->db();
        $hash = AppPassword::hash($raw, $this->services->keyProvider());
        $query = $this->query()
            ->select($db->quoteName(['d.id', 'd.user_id', 'd.secret_hash', 'd.kek_salt', 'd.usk_wrapped', 'd.user_key_version', 'd.state', 'd.row_mac']))
            ->select($db->quoteName(['u.username', 'u.block']))
            ->from($db->quoteName('#__' . self::TABLE, 'd'))
            ->join('INNER', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('d.user_id'))
            ->where($db->quoteName('d.secret_hash') . ' = :hash')
            ->bind(':hash', $hash, ParameterType::LARGE_OBJECT);
        $row = $db->setQuery($query)->loadObject();

        if ($row === null || !hash_equals(mb_strtolower((string) $row->username), mb_strtolower(trim($username)))) {
            return null;
        }

        $state = (int) $row->state;

        if ($state === TeamStates::DEVICE_REVOKED) {
            return null;
        }

        // A valid MAC proves the server wrote the row. The epoch comes from the verified user
        // key row and must reach the anchor, so an older row played back together with an older
        // epoch fails too (5.11). Without a trustworthy key row no device of the user signs in.
        $epoch = $this->trustedEpoch($this->userKeyRow((int) $row->user_id), (int) $row->user_id);
        $row->device_epoch = $epoch ?? 0;
        $reason = $epoch === null ? 'user_key' : (!$this->verifies($row) ? 'device_auth' : '');

        if ($reason !== '') {
            try {
                $this->services->audit()->log(AuditEvent::ROW_MAC_FAILURE, [
                    'subject_user_id' => (int) $row->user_id,
                    'actor_device_id' => (int) $row->id,
                    'details' => ['table' => self::TABLE, 'reason' => $reason],
                ]);
            } catch (\Throwable $e) {
                Log::add('Internet calendar MAC failure not logged: device ' . (int) $row->id, Log::WARNING, 'com_appointments');
            }

            return null;
        }

        if ($state === TeamStates::DEVICE_CLEANUP_EMPTY || $state === TeamStates::DEVICE_CLEANUP_HIDDEN) {
            // The clean-up needs no key and also reaches a blocked account.
            return new DeviceSession((int) $row->id, (int) $row->user_id, (string) $row->username, $state, null);
        }

        if ($state !== TeamStates::DEVICE_ACTIVE || (int) $row->block !== 0 || $row->usk_wrapped === null) {
            return null;
        }

        $keys = $this->services->keys()->unlockWithDevice((int) $row->user_id, $raw, (string) $row->kek_salt, (string) $row->usk_wrapped);

        if ($keys === null || $keys->userKeyVersion() !== (int) $row->user_key_version) {
            return null;
        }

        return new DeviceSession((int) $row->id, (int) $row->user_id, (string) $row->username, $state, $keys);
    }

    /**
     * «Last synchronised» is information and best effort: the device time and the time of a
     * pair are throttled and a failure there is only logged. The pair row itself, with the
     * latest role, and the highest delivered sync token are what the clean-up relies on: they
     * are written on every call that carries them, and a failure there reaches the caller, so
     * it can refuse to deliver data it could not track.
     */
    public function touch(DeviceSession $session, ?int $calendarId, ?int $role = null, ?int $syncToken = null): void
    {
        $db = $this->services->db();
        $now = $this->services->now();
        $nowSql = $now->format('Y-m-d H:i:s');
        $threshold = $now->modify('-' . self::TOUCH_INTERVAL . ' seconds')->format('Y-m-d H:i:s');
        $deviceId = $session->deviceId;

        if (!isset($this->touched[$deviceId])) {
            $this->touched[$deviceId] = true;

            try {
                $query = $this->query()
                    ->update($db->quoteName('#__' . self::TABLE))
                    ->set($db->quoteName('last_sync_utc') . ' = :now')
                    ->where($db->quoteName('id') . ' = :id')
                    ->where('(' . $db->quoteName('last_sync_utc') . ' IS NULL OR ' . $db->quoteName('last_sync_utc') . ' <= :threshold)')
                    ->bind(':now', $nowSql)
                    ->bind(':id', $deviceId, ParameterType::INTEGER)
                    ->bind(':threshold', $threshold);
                $db->setQuery($query)->execute();
            } catch (\RuntimeException $e) {
                Log::add('Internet calendar sync time not recorded: device ' . $deviceId, Log::WARNING, 'com_appointments');
            }
        }

        if ($calendarId === null || $calendarId <= 0) {
            return;
        }

        $calendarId = (int) $calendarId;
        $column = $db->quoteName('last_sync_utc');
        $later = ' = IF(' . $column . ' <= ' . $db->quote($threshold) . ', ' . $db->quote($nowSql) . ', ' . $column . ')';
        $pair = ' WHERE ' . $db->quoteName('device_id') . ' = ' . $deviceId . ' AND ' . $db->quoteName('calendar_id') . ' = ' . $calendarId;

        if ($role !== null && CalendarRole::tryFrom($role) !== null) {
            // Required: the pair exists and carries the role of this synchronisation, written at
            // once on every call; only its time stays throttled (in SQL). No catch: see above.
            $db->setQuery(
                'INSERT INTO ' . $db->quoteName('#__appointments_device_calendars')
                . ' (' . implode(', ', $db->quoteName(['device_id', 'calendar_id', 'role', 'last_sync_utc'])) . ')'
                . ' VALUES (' . $deviceId . ', ' . $calendarId . ', ' . $role . ', ' . $db->quote($nowSql) . ')'
                . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('role') . ' = ' . $role . ', ' . $column . $later
            )->execute();
        } else {
            // Without a role no pair is created (the column has no default); the time is best effort.
            try {
                $db->setQuery('UPDATE ' . $db->quoteName('#__appointments_device_calendars') . ' SET ' . $column . $later . $pair)->execute();
            } catch (\RuntimeException $e) {
                Log::add('Internet calendar sync time not recorded: device ' . $deviceId, Log::WARNING, 'com_appointments');
            }
        }

        // Required too: the highest sync token delivered to an active device, never lowered.
        if ($syncToken !== null && $syncToken > 0 && $session->isActive()) {
            $token = $db->quoteName('synctoken');
            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__appointments_device_calendars') . ' SET ' . $token . ' = ' . $syncToken
                . $pair . ' AND ' . $token . ' < ' . $syncToken
            )->execute();
        }
    }
    public function listForUser(int $userId): array
    {
        $db = $this->services->db();
        $revoked = TeamStates::DEVICE_REVOKED;
        $query = $this->query()
            ->select($db->quoteName(['id', 'name', 'client', 'state', 'created_utc', 'last_sync_utc']))
            ->from($db->quoteName('#__' . self::TABLE))
            ->where($db->quoteName('user_id') . ' = :user')
            ->where($db->quoteName('state') . ' <> :revoked')
            ->order($db->quoteName('created_utc') . ' ASC, ' . $db->quoteName('id') . ' ASC')
            ->bind(':user', $userId, ParameterType::INTEGER)
            ->bind(':revoked', $revoked, ParameterType::INTEGER);

        return array_map([$this, 'publicRow'], $db->setQuery($query)->loadObjectList());
    }

    public function listAll(array $filters): array
    {
        $db = $this->services->db();
        $query = $this->query()
            ->select($db->quoteName(['d.id', 'd.user_id', 'd.name', 'd.client', 'd.state', 'd.created_utc', 'd.last_sync_utc']))
            ->select($db->quoteName('u.username', 'username'))
            ->select($db->quoteName('u.name', 'user_name'))
            ->from($db->quoteName('#__' . self::TABLE, 'd'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('d.user_id'))
            ->order($db->quoteName('d.created_utc') . ' DESC, ' . $db->quoteName('d.id') . ' DESC');

        if (isset($filters['user_id'])) {
            $user = (int) $filters['user_id'];
            $query->where($db->quoteName('d.user_id') . ' = :user')->bind(':user', $user, ParameterType::INTEGER);
        }

        if (isset($filters['state']) && \is_numeric($filters['state'])) {
            $state = (int) $filters['state'];
            $query->where($db->quoteName('d.state') . ' = :state')->bind(':state', $state, ParameterType::INTEGER);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($search, 0, 100)) . '%';
            $query->where('(' . $db->quoteName('d.name') . ' LIKE :s1 OR ' . $db->quoteName('u.username') . ' LIKE :s2 OR '
                . $db->quoteName('u.name') . ' LIKE :s3)')
                ->bind(':s1', $like)->bind(':s2', $like)->bind(':s3', $like);
        }

        $rows = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $public = $this->publicRow($row);
            $public->user_id = (int) $row->user_id;
            $public->username = (string) ($row->username ?? '');
            $public->user_name = (string) ($row->user_name ?? '');
            $rows[] = $public;
        }

        return $rows;
    }

    public function rename(int $actorUserId, int $deviceId, string $name): void
    {
        $this->requireEnabled();
        $device = $this->device($deviceId);

        // Only the owner renames; anything else is «not found», so ids of other accounts stay unseen.
        if ($device === null || (int) $device->user_id !== $actorUserId || (int) $device->state === TeamStates::DEVICE_REVOKED) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_DEVICE', 404);
        }

        $name = self::normaliseName($name);

        if ($name === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_NAME', 400);
        }

        if ($name === (string) $device->name) {
            return;
        }

        $db = $this->services->db();
        $lock = $db->getPrefix() . 'com_appointments.device.' . $actorUserId;

        if ((int) $db->setQuery('SELECT GET_LOCK(' . $db->quote($lock) . ', 10)')->loadResult() !== 1) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_BUSY', 409);
        }

        try {
            $db->transactionStart(true);

            try {
                // Same lock order as create(): the user key row, then the device rows.
                $this->lockUserKey($actorUserId);

                if ($this->nameTaken($actorUserId, $name, $deviceId)) {
                    throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_NAME_TAKEN', 409);
                }

                $query = $this->query()
                    ->update($db->quoteName('#__' . self::TABLE))
                    ->set($db->quoteName('name') . ' = :name')
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':name', $name)
                    ->bind(':id', $deviceId, ParameterType::INTEGER);
                $db->setQuery($query)->execute();
                $db->transactionCommit(true);
            } catch (\Throwable $e) {
                $db->transactionRollback(true);

                throw $e;
            }
        } finally {
            $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')')->execute();
        }

        $this->services->audit()->log(AuditEvent::DEVICE_RENAMED, [
            'actor_user_id' => $actorUserId,
            'subject_user_id' => $actorUserId,
            'details' => ['device_id' => $deviceId],
            'ip' => $this->ip(),
        ]);
    }

    public function revoke(?int $actorUserId, int $deviceId, string $reason): void
    {
        $device = $this->device($deviceId);

        if ($device === null || ($actorUserId !== null && !$this->mayAdminister($actorUserId, (int) $device->user_id))) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_DEVICE', 404);
        }

        $reason = preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $reason) === 1 ? $reason : 'other';
        $now = $this->services->now()->format('Y-m-d H:i:s');

        $state = (int) $device->state;

        // A revocation always runs the full state change, also over a row that already says
        // «revoked»: the state column is not proof (a database writer can set it and later play
        // back the active row), the new secret hash and the raised epoch are. The clean-up may
        // move the device between the read and the locked write: read again and retry, so an
        // emergency stop never fails silently. The first revocation keeps its time and actor.
        for ($attempt = 0; !$this->transition($deviceId, $state, TeamStates::DEVICE_REVOKED, $state === TeamStates::DEVICE_REVOKED ? [] : ['revoked_utc' => $now, 'revoked_by' => $actorUserId, 'revoke_reason' => $reason]); $attempt++) {
            $current = $this->device($deviceId);

            if ($current === null) {
                return;
            }

            if ($attempt >= 3) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_BUSY', 409);
            }

            $state = (int) $current->state;
        }

        $this->services->audit()->log(AuditEvent::DEVICE_REVOKED, [
            'actor_user_id' => $actorUserId,
            'subject_user_id' => (int) $device->user_id,
            'details' => ['device_id' => $deviceId, 'reason' => $reason],
            'ip' => $actorUserId !== null ? $this->ip() : null,
        ]);
    }

    /**
     * Compare-and-set of the clean-up state (work package F): active → clean-up
     * empty → clean-up hidden. Recomputes the row MAC; leaving the active state
     * clears the wrapped key, so a device in clean-up never decrypts again.
     * Revocation is {@see revoke()}.
     *
     * @return  bool  False when the device is not in `$fromState`.
     */
    public function changeState(int $deviceId, int $fromState, int $toState): bool
    {
        $allowed = [
            TeamStates::DEVICE_ACTIVE => [TeamStates::DEVICE_CLEANUP_EMPTY, TeamStates::DEVICE_CLEANUP_HIDDEN],
            TeamStates::DEVICE_CLEANUP_EMPTY => [TeamStates::DEVICE_CLEANUP_HIDDEN],
        ];

        if (!\in_array($toState, $allowed[$fromState] ?? [], true)) {
            throw new \InvalidArgumentException('Unsupported device state change.');
        }

        $extra = $fromState === TeamStates::DEVICE_ACTIVE
            ? ['cleanup_started_utc' => $this->services->now()->format('Y-m-d H:i:s')]
            : [];

        return $this->transition($deviceId, $fromState, $toState, $extra);
    }

    /**
     * One state change under row locks. The wrapped key goes with the active state;
     * a revocation also replaces the secret hash, so the app password no longer even
     * finds the row. Every change raises the user's `device_epoch` (5.11) and rewrites
     * the MACs of the user's other devices with it, so an older device row played back
     * into the database no longer verifies. A MAC is rewritten only over a row whose
     * MAC verified before. Lock order: user key row, then device rows, as in create().
     *
     * @param   array<string, string|int|null>  $extra  Further columns (times, actor, reason).
     */
    private function transition(int $deviceId, int $fromState, int $toState, array $extra): bool
    {
        $db = $this->services->db();
        $query = $this->query()
            ->select($db->quoteName('user_id'))
            ->from($db->quoteName('#__' . self::TABLE))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $deviceId, ParameterType::INTEGER);
        $owner = (int) $db->setQuery($query)->loadResult();

        if ($owner <= 0) {
            return false;
        }

        $db->transactionStart(true);

        try {
            // Only a verified and anchored epoch is carried forward. Otherwise (no key row, a
            // tampered key row, an epoch below the anchor) the target device is changed and left
            // with its old MAC, so it is dead, and nothing else is signed: fail closed.
            $keyRow = $this->lockUserKey($owner);
            $old = $this->trustedEpoch($keyRow, $owner);
            $new = $old !== null ? $old + 1 : null;
            $revoked = TeamStates::DEVICE_REVOKED;
            $query = $this->query()
                ->select($db->quoteName(['id', 'user_id', 'secret_hash', 'kek_salt', 'usk_wrapped', 'user_key_version', 'state', 'row_mac']))
                ->from($db->quoteName('#__' . self::TABLE))
                // Integers inline: a bound query loses its bindings when turned into a string for FOR UPDATE.
                ->where($db->quoteName('user_id') . ' = ' . (int) $owner)
                ->where('(' . $db->quoteName('state') . ' <> ' . (int) $revoked . ' OR ' . $db->quoteName('id') . ' = ' . (int) $deviceId . ')');
            $rows = $db->setQuery((string) $query . ' FOR UPDATE')->loadAssocList('id');
            $target = $rows[$deviceId] ?? null;

            if ($target === null || (int) $target['state'] !== $fromState) {
                $db->transactionRollback(true);

                return false;
            }

            if ($new !== null) {
                $this->raiseEpoch($keyRow, $new);
            } else {
                $rows = [$deviceId => $target];
                $this->services->audit()->log(AuditEvent::ROW_MAC_FAILURE, [
                    'subject_user_id' => $owner,
                    'details' => ['table' => 'appointments_user_keys', 'reason' => 'device_epoch'],
                ]);
            }

            foreach ($rows as $id => $row) {
                $row['device_epoch'] = $old ?? 0;
                $intact = $old !== null && $this->verifies((object) $row);
                $row['device_epoch'] = $new ?? 0;
                $update = $this->query()->update($db->quoteName('#__' . self::TABLE))->where($db->quoteName('id') . ' = ' . (int) $id);

                if ((int) $id === $deviceId) {
                    $row['state'] = $toState;
                    $row['usk_wrapped'] = null;

                    if ($toState === TeamStates::DEVICE_REVOKED) {
                        $row['secret_hash'] = random_bytes(32);
                    }

                    $update->set($db->quoteName('state') . ' = ' . $toState)
                        ->set($db->quoteName('usk_wrapped') . ' = NULL')
                        ->set($db->quoteName('secret_hash') . ' = ' . self::hex((string) $row['secret_hash']));

                    foreach ($extra as $column => $value) {
                        $update->set($db->quoteName($column) . ' = ' . ($value === null ? 'NULL' : (\is_int($value) ? (string) $value : $db->quote($value))));
                    }
                } elseif ((int) $row['state'] === $revoked || !$intact) {
                    // A revoked or tampered row keeps its MAC and so stays invalid.
                    continue;
                }

                $mac = $intact ? $this->mac($row) : (string) $row['row_mac'];
                $update->set($db->quoteName('row_mac') . ' = ' . self::hex($mac));
                $db->setQuery($update)->execute();
            }

            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $db->transactionRollback(true);

            throw $e;
        }

        if ($new !== null) {
            $this->anchorEpoch($owner);
        }

        return true;
    }

    /**
     * The user key row, locked for the rest of the transaction; null when the user has none.
     *
     * @return  array<string, mixed>|null
     */
    private function lockUserKey(int $userId): ?array
    {
        $db = $this->services->db();
        $query = $this->query()
            ->select('*')
            ->from($db->quoteName('#__appointments_user_keys'))
            ->where($db->quoteName('user_id') . ' = ' . (int) $userId);

        return $db->setQuery((string) $query . ' FOR UPDATE')->loadAssoc() ?: null;
    }

    /**
     * The user key row without a lock, for a read-only check.
     *
     * @return  array<string, mixed>|null
     */
    private function userKeyRow(int $userId): ?array
    {
        $db = $this->services->db();
        $query = $this->query()
            ->select('*')
            ->from($db->quoteName('#__appointments_user_keys'))
            ->where($db->quoteName('user_id') . ' = :user')
            ->bind(':user', $userId, ParameterType::INTEGER);

        return $db->setQuery($query)->loadAssoc() ?: null;
    }

    /**
     * The user's `device_epoch` when it can be trusted: the key row exists, its MAC
     * (field list of the key service, which must cover the epoch) verifies, and the
     * epoch reaches the anchor. Null otherwise. A later epoch than the anchored one is
     * anchored on the way, so a failed anchoring catches up with the next request.
     *
     * @param   array<string, mixed>|null  $keyRow
     */
    private function trustedEpoch(?array $keyRow, int $userId): ?int
    {
        if ($keyRow === null) {
            return null;
        }

        $mac = (string) ($keyRow['row_mac'] ?? '');

        if (\strlen($mac) !== 32 || !$this->services->keys()->verifyRowMac('user_keys', self::userKeyFields($keyRow), $mac)) {
            return null;
        }

        $epoch = (int) $keyRow['device_epoch'];
        $anchor = $this->services->anchor();

        if (!$anchor->accepts(EpochAnchorInterface::KIND_USER, $userId, $epoch)) {
            return null;
        }

        if ($anchor->minimum(EpochAnchorInterface::KIND_USER, $userId) < $epoch) {
            $this->anchorEpoch($userId);
        }

        return $epoch;
    }

    /**
     * The MAC fields of a user key row as the key service (work package B) lists them.
     * The list must contain `device_epoch` (5.11), otherwise nothing is trusted.
     *
     * @param   array<string, mixed>  $keyRow
     *
     * @return  array<string, mixed>
     */
    private static function userKeyFields(array $keyRow): array
    {
        $names = method_exists(KeyService::class, 'macFields') ? KeyService::macFields('user_keys') : [];

        if (!\in_array('device_epoch', $names, true)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
        }

        $fields = [];

        foreach ($names as $name) {
            $fields[$name] = $keyRow[$name] ?? null;
        }

        return $fields;
    }

    /**
     * Raise the epoch of a locked, verified user key row and rewrite its MAC.
     *
     * @param   array<string, mixed>  $keyRow
     */
    private function raiseEpoch(array $keyRow, int $epoch): void
    {
        $keyRow['device_epoch'] = $epoch;
        $db = $this->services->db();
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__appointments_user_keys')
            . ' SET ' . $db->quoteName('device_epoch') . ' = ' . $epoch
            . ', ' . $db->quoteName('row_mac') . ' = ' . self::hex($this->services->keys()->rowMac('user_keys', self::userKeyFields($keyRow)))
            . ' WHERE ' . $db->quoteName('user_id') . ' = ' . (int) $keyRow['user_id']
        )->execute();
    }

    /**
     * Anchor the committed epoch of the user. Inside somebody else's transaction the anchor
     * waits until that commits (recordAfterCommit), so it never stands above a database that
     * rolled back.
     */
    private function anchorEpoch(int $userId): void
    {
        try {
            $this->services->anchor()->recordAfterCommit(EpochAnchorInterface::KIND_USER, $userId);
        } catch (\Throwable $e) {
            // The change is committed; until the next successful anchoring the rollback
            // protection lags one step behind. The system check reports an unwritable anchor.
            Log::add('Internet calendar device epoch not anchored: user ' . $userId, Log::ERROR, 'com_appointments');
        }
    }
    private static function hex(string $bytes): string
    {
        return "X'" . bin2hex($bytes) . "'";
    }
    /**
     * @param   array<string, mixed>|object  $row
     */
    private function mac(array|object $row): string
    {
        return $this->services->keys()->rowMac(self::TABLE, self::macFields((array) $row));
    }

    private function verifies(object $row): bool
    {
        $mac = (string) ($row->row_mac ?? '');

        return \strlen($mac) === 32 && $this->services->keys()->verifyRowMac(self::TABLE, self::macFields((array) $row), $mac);
    }

    /**
     * The covered columns in their fixed order and types, the user's device_epoch included.
     *
     * @param   array<string, mixed>  $row
     *
     * @return  array<string, int|string|null>
     */
    public static function macFields(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'secret_hash' => (string) $row['secret_hash'],
            'kek_salt' => (string) $row['kek_salt'],
            'usk_wrapped' => $row['usk_wrapped'] === null ? null : (string) $row['usk_wrapped'],
            'user_key_version' => (int) $row['user_key_version'],
            'state' => (int) $row['state'],
            // The user's device epoch (5.11): an older row played back no longer verifies.
            'device_epoch' => (int) ($row['device_epoch'] ?? throw new \InvalidArgumentException('A device MAC needs the device epoch.')),
        ];
    }

    private function publicRow(object $row): object
    {
        return (object) [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'client' => (string) $row->client,
            'state' => (int) $row->state,
            'created_utc' => (string) $row->created_utc,
            'last_sync_utc' => $row->last_sync_utc === null ? null : (string) $row->last_sync_utc,
        ];
    }

    private function device(int $deviceId): ?object
    {
        if ($deviceId <= 0) {
            return null;
        }

        $db = $this->services->db();
        $query = $this->query()
            ->select($db->quoteName(['id', 'user_id', 'name', 'state']))
            ->from($db->quoteName('#__' . self::TABLE))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $deviceId, ParameterType::INTEGER);

        return $db->setQuery($query)->loadObject() ?: null;
    }

    private function nameTaken(int $userId, string $name, int $exceptId): bool
    {
        $db = $this->services->db();
        $revoked = TeamStates::DEVICE_REVOKED;
        $lower = mb_strtolower($name);
        // A locking read inside the caller's transaction: it sees every committed device, also
        // one committed after this transaction's snapshot, and the user's rows stay locked until
        // the commit, so two requests can never both find the name free. Values inline, because
        // a bound query loses its bindings when turned into a string for FOR UPDATE.
        $query = $this->query()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__' . self::TABLE))
            ->where($db->quoteName('user_id') . ' = ' . (int) $userId)
            ->where($db->quoteName('state') . ' <> ' . (int) $revoked)
            ->where('LOWER(' . $db->quoteName('name') . ') = ' . $db->quote($lower))
            ->where($db->quoteName('id') . ' <> ' . (int) $exceptId);

        return $db->setQuery((string) $query . ' FOR UPDATE')->loadColumn() !== [];
    }

    private function account(int $userId): ?object
    {
        $db = $this->services->db();
        $query = $this->query()
            ->select($db->quoteName(['id', 'block']))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $userId, ParameterType::INTEGER);

        return $db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * The owner of a device, or a user with `core.admin` on the component.
     */
    private function mayAdminister(int $actorUserId, int $ownerUserId): bool
    {
        if ($actorUserId === $ownerUserId) {
            return true;
        }

        $actor = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($actorUserId);

        return (int) $actor->id === $actorUserId && (int) $actor->block === 0 && $actor->authorise('core.admin', 'com_appointments');
    }

    private function requireEnabled(): void
    {
        if (!$this->services->enabled()) {
            throw new TeamException('JERROR_PAGE_NOT_FOUND', 404);
        }
    }

    private function ip(): ?string
    {
        try {
            $ip = (string) Factory::getApplication()->getInput()->server->getString('REMOTE_ADDR', '');
        } catch (\Throwable $e) {
            return null;
        }

        return $ip !== '' ? $ip : null;
    }

    private function query(): QueryInterface
    {
        $db = $this->services->db();

        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
