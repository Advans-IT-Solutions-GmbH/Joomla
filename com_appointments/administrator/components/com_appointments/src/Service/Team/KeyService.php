<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\AppPassword;
use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKey;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\StoredObject;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Joomla\CMS\Log\Log;

defined('_JEXEC') or die;

/**
 * The key hierarchy of the internet calendars (work package B), exactly as in
 * specification 5.2. Every crypto call goes through {@see CipherSuite}.
 *
 * Row MACs of the tables this service owns, over these columns:
 *
 * - `#__appointments_keyring`: purpose, public_key, fingerprint, created_by, created_utc
 * - `#__appointments_user_keys`: user_id, key_version, public_key, password_wrapped,
 *   password_salt, pwhash_opslimit, pwhash_memlimit
 * - `#__appointments_calendar_keys`: calendar_id, key_version, box_public_key,
 *   box_secret_wrapped, recovery_wrapped, recovery_fingerprint
 *
 * `#__appointments_calendar_key_wraps` carries no MAC. A calendar key taken
 * from a wrap is accepted only when it opens the sealing secret of its
 * MAC-protected `calendar_keys` row and that secret belongs to the row's public
 * key, so a wrap planted by somebody who can write the database alone is
 * refused (a sealed box to a public key can be made by anybody).
 *
 * A mismatch of any MAC means "no key"; it is logged without values. Key
 * material never leaves this class except inside {@see KeyContext} and
 * {@see CalendarKey}; local copies are wiped after use, and no exception or log
 * line carries a byte of it.
 *
 * @since  1.4.0
 */
final class KeyService implements KeyServiceInterface
{
    /** Audit event of a failed row MAC. */
    private const EVENT_MAC_FAILURE = 'key.mac_failure';

    /** `#__appointments_keyring.purpose` of the site recovery key. */
    private const RECOVERY = 'recovery';

    /** Base32 alphabet of the printed recovery secret (RFC 4648). */
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Bytes of the typing check appended to the printed recovery secret. */
    private const RECOVERY_CHECK_BYTES = 3;

    /** Prefix of the envelope sealed by the booking flow (scheme 2). */
    private const ENVELOPE = "ACv1\n";

    /** Objects re-encrypted per key holder's request. */
    private const REENCRYPT_BATCH = 25;

    /** Tables with a row MAC, without the `#__appointments_` prefix. */
    private const MAC_TABLES = ['keyring', 'user_keys', 'calendars', 'calendar_keys', 'calendar_grants', 'devices', 'invitations'];

    /**
     * Verified `calendar_keys` rows of this request (public data only).
     *
     * @var  array<string, array<string, mixed>|false>
     */
    private $calendarKeyRows = [];

    public function __construct(private readonly TeamServices $services)
    {
    }

    /**
     * The MAC columns of the tables this service owns. Everybody who writes
     * such a row uses this list together with {@see rowMac()}.
     *
     * @return  string[]
     */
    public static function macFields(string $table): array
    {
        return match (self::canonicalTable($table)) {
            'appointments_keyring' => ['purpose', 'public_key', 'fingerprint', 'created_by', 'created_utc'],
            'appointments_user_keys' => ['user_id', 'key_version', 'public_key', 'password_wrapped', 'password_salt', 'pwhash_opslimit', 'pwhash_memlimit'],
            'appointments_calendar_keys' => ['calendar_id', 'key_version', 'box_public_key', 'box_secret_wrapped', 'recovery_wrapped', 'recovery_fingerprint'],
            default => throw new \InvalidArgumentException('This service does not own that table.'),
        };
    }

    // ------------------------------------------------------------------
    // Recovery key
    // ------------------------------------------------------------------

    public function createRecoveryKey(int $actorUserId): string
    {
        if ($this->rawRecoveryRow() !== null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_RECOVERY_EXISTS', 409);
        }

        $pair = CipherSuite::keyPair();
        $fingerprint = self::fingerprintOf($pair['public']);
        $row = [
            'purpose'     => self::RECOVERY,
            'public_key'  => $pair['public'],
            'fingerprint' => $fingerprint,
            'created_by'  => $actorUserId,
            'created_utc' => $this->nowSql(),
        ];

        try {
            $this->insert('#__appointments_keyring', $row + ['row_mac' => $this->rowMac('keyring', $row)], ['public_key', 'row_mac']);
        } catch (\Throwable $e) {
            CipherSuite::wipe($pair['secret']);

            // A concurrent creation won the primary key.
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_RECOVERY_EXISTS', 409);
        }

        $printable = self::formatRecoverySecret($pair['secret']);
        CipherSuite::wipe($pair['secret']);
        $this->log(AuditEvent::RECOVERY_KEY_CREATED, ['actor_user_id' => $actorUserId, 'details' => ['fingerprint' => $fingerprint]]);

        return $printable;
    }

    public function rotateRecoveryKey(#[\SensitiveParameter] string $oldRecoverySecret, int $actorUserId): string
    {
        $oldSecret = self::parseRecoverySecret($oldRecoverySecret);
        $row = $this->recoveryRow();

        if ($row === null) {
            CipherSuite::wipe($oldSecret);

            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_NO_RECOVERY_KEY', 409);
        }

        if ($oldSecret === null || !hash_equals($row['public_key'], CipherSuite::publicKeyOf($oldSecret))) {
            CipherSuite::wipe($oldSecret);

            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_RECOVERY_INVALID', 403);
        }

        $oldPublic = $row['public_key'];
        $pair = CipherSuite::keyPair();
        $fingerprint = self::fingerprintOf($pair['public']);
        $db = $this->services->db();
        $db->transactionStart(true);

        try {
            $query = $this->query()
                ->select($db->quoteName(['calendar_id', 'key_version', 'box_public_key', 'box_secret_wrapped', 'recovery_wrapped', 'recovery_fingerprint', 'row_mac']))
                ->from($db->quoteName('#__appointments_calendar_keys'));
            $rows = $db->setQuery((string) $query . ' FOR UPDATE')->loadAssocList() ?: [];

            foreach ($rows as $keyRow) {
                if (!$this->verifyStoredRow('calendar_keys', $keyRow)) {
                    throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_TAMPERED', 409);
                }

                $calendarId = (int) $keyRow['calendar_id'];
                $version = (int) $keyRow['key_version'];
                $calendarKey = CipherSuite::open($oldSecret, $oldPublic, (string) $keyRow['recovery_wrapped']);

                if ($calendarKey === null || !$this->opensBox($calendarId, $version, $calendarKey, $keyRow)) {
                    CipherSuite::wipe($calendarKey);

                    throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_TAMPERED', 409);
                }

                $keyRow['recovery_wrapped'] = CipherSuite::seal($pair['public'], $calendarKey);
                $keyRow['recovery_fingerprint'] = $fingerprint;
                CipherSuite::wipe($calendarKey);
                $this->updateCalendarKeyRow($keyRow);
            }

            $newRow = [
                'purpose'     => self::RECOVERY,
                'public_key'  => $pair['public'],
                'fingerprint' => $fingerprint,
                'created_by'  => $actorUserId,
                'created_utc' => $this->nowSql(),
            ];
            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__appointments_keyring')
                . ' SET ' . $db->quoteName('public_key') . ' = ' . self::hex($pair['public'])
                . ', ' . $db->quoteName('fingerprint') . ' = ' . $db->quote($fingerprint)
                . ', ' . $db->quoteName('created_by') . ' = ' . (int) $actorUserId
                . ', ' . $db->quoteName('created_utc') . ' = ' . $db->quote($newRow['created_utc'])
                . ', ' . $db->quoteName('row_mac') . ' = ' . self::hex($this->rowMac('keyring', $newRow))
                . ' WHERE ' . $db->quoteName('purpose') . ' = ' . $db->quote(self::RECOVERY)
            )->execute();
            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $db->transactionRollback(true);
            CipherSuite::wipe($oldSecret);
            CipherSuite::wipe($pair['secret']);
            $this->calendarKeyRows = [];

            throw $e instanceof TeamException ? $e : new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_OPERATION', 500);
        }

        CipherSuite::wipe($oldSecret);
        $this->calendarKeyRows = [];
        $printable = self::formatRecoverySecret($pair['secret']);
        CipherSuite::wipe($pair['secret']);
        $this->log(AuditEvent::RECOVERY_KEY_ROTATED, ['actor_user_id' => $actorUserId, 'details' => ['fingerprint' => $fingerprint]]);

        return $printable;
    }

    /**
     * @return  string|null  `XXXX-XXXX-XXXX-XXXX` (hex), null when none exists or its MAC fails.
     */
    public function recoveryFingerprint(): ?string
    {
        $row = $this->recoveryRow();

        return $row === null ? null : implode('-', str_split((string) $row['fingerprint'], 4));
    }

    // ------------------------------------------------------------------
    // User keys
    // ------------------------------------------------------------------

    public function hasUserKey(int $userId): bool
    {
        return $this->usableUserKey($userId) !== null;
    }

    public function userPublicKey(int $userId): ?string
    {
        $row = $this->usableUserKey($userId);

        return $row === null ? null : (string) $row['public_key'];
    }

    public function createUserKey(int $userId, #[\SensitiveParameter] string $password): KeyContext
    {
        $salt = random_bytes(CipherSuite::PWHASH_SALT_BYTES);
        $kek = $this->passwordKek($password, $salt, CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT);

        try {
            return $this->createUserKeyWithKek($userId, $kek, $salt, CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT);
        } finally {
            CipherSuite::wipe($kek);
        }
    }

    public function unlockWithPassword(int $userId, #[\SensitiveParameter] string $password): ?KeyContext
    {
        $row = $this->usableUserKey($userId);

        if ($row === null) {
            return null;
        }

        $kek = $this->passwordKek($password, (string) $row['password_salt'], (int) $row['pwhash_opslimit'], (int) $row['pwhash_memlimit']);

        try {
            return $this->unlockWithKek($userId, $kek);
        } finally {
            CipherSuite::wipe($kek);
        }
    }

    public function rewrapForPassword(KeyContext $keys, #[\SensitiveParameter] string $newPassword): void
    {
        $row = $this->usableUserKey($keys->userId());

        if ($row === null || (int) $row['key_version'] !== $keys->userKeyVersion() || !hash_equals((string) $row['public_key'], $keys->publicKey())) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_CHANGED', 409);
        }

        $salt = random_bytes(CipherSuite::PWHASH_SALT_BYTES);
        // Never weaker than what the row had; never below the current default.
        $ops = max(CipherSuite::PWHASH_OPSLIMIT, (int) $row['pwhash_opslimit']);
        $mem = max(CipherSuite::PWHASH_MEMLIMIT, (int) $row['pwhash_memlimit']);
        $kek = $this->passwordKek($newPassword, $salt, $ops, $mem);
        $wrapped = CipherSuite::encrypt($kek, $keys->secretKey(), self::passwordAd($keys->userId(), $keys->userKeyVersion()));
        CipherSuite::wipe($kek);

        $fields = [
            'user_id'          => $keys->userId(),
            'key_version'      => $keys->userKeyVersion(),
            'public_key'       => $keys->publicKey(),
            'password_wrapped' => $wrapped,
            'password_salt'    => $salt,
            'pwhash_opslimit'  => $ops,
            'pwhash_memlimit'  => $mem,
        ];
        $db = $this->services->db();
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__appointments_user_keys')
            . ' SET ' . $db->quoteName('password_wrapped') . ' = ' . self::hex($wrapped)
            . ', ' . $db->quoteName('password_salt') . ' = ' . self::hex($salt)
            . ', ' . $db->quoteName('pwhash_opslimit') . ' = ' . $ops
            . ', ' . $db->quoteName('pwhash_memlimit') . ' = ' . $mem
            . ', ' . $db->quoteName('row_mac') . ' = ' . self::hex($this->rowMac('user_keys', $fields))
            . ', ' . $db->quoteName('modified_utc') . ' = ' . $db->quote($this->nowSql())
            . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $keys->userId()
            . ' AND ' . $db->quoteName('key_version') . ' = ' . $keys->userKeyVersion()
            . ' AND ' . $db->quoteName('public_key') . ' = ' . self::hex($keys->publicKey())
        )->execute();

        if ($db->getAffectedRows() !== 1) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_CHANGED', 409);
        }
    }

    public function resetUserKey(int $userId): void
    {
        $db = $this->services->db();
        $db->transactionStart(true);

        try {
            $query = $this->query()
                ->select($db->quoteName(['key_version']))
                ->from($db->quoteName('#__appointments_user_keys'))
                ->where($db->quoteName('user_id') . ' = ' . $userId);
            $current = $db->setQuery((string) $query . ' FOR UPDATE')->loadResult();

            if ($current !== null) {
                // A new key pair whose secret is discarded at once: nothing can be
                // sealed to the old key any more, and nothing to the new one is usable
                // until the next password sign-in creates a usable pair.
                $pair = CipherSuite::keyPair();
                CipherSuite::wipe($pair['secret']);
                $fields = [
                    'user_id'          => $userId,
                    'key_version'      => (int) $current + 1,
                    'public_key'       => $pair['public'],
                    'password_wrapped' => null,
                    'password_salt'    => random_bytes(CipherSuite::PWHASH_SALT_BYTES),
                    'pwhash_opslimit'  => CipherSuite::PWHASH_OPSLIMIT,
                    'pwhash_memlimit'  => CipherSuite::PWHASH_MEMLIMIT,
                ];
                $db->setQuery(
                    'UPDATE ' . $db->quoteName('#__appointments_user_keys')
                    . ' SET ' . $db->quoteName('key_version') . ' = ' . $fields['key_version']
                    . ', ' . $db->quoteName('public_key') . ' = ' . self::hex($fields['public_key'])
                    . ', ' . $db->quoteName('password_wrapped') . ' = NULL'
                    . ', ' . $db->quoteName('password_salt') . ' = ' . self::hex($fields['password_salt'])
                    . ', ' . $db->quoteName('pwhash_opslimit') . ' = ' . $fields['pwhash_opslimit']
                    . ', ' . $db->quoteName('pwhash_memlimit') . ' = ' . $fields['pwhash_memlimit']
                    . ', ' . $db->quoteName('row_mac') . ' = ' . self::hex($this->rowMac('user_keys', $fields))
                    . ', ' . $db->quoteName('modified_utc') . ' = ' . $db->quote($this->nowSql())
                    . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $userId
                )->execute();
            }

            $this->deleteAllWrapsOfUser($userId);
            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $db->transactionRollback(true);

            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_OPERATION', 500);
        }

        $this->log(AuditEvent::USER_KEY_RESET, ['subject_user_id' => $userId]);
    }

    public function discardUserKey(int $actorUserId, int $userId): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function wrapForDevice(KeyContext $keys, #[\SensitiveParameter] string $appPasswordRaw, string $kekSalt): string
    {
        $row = $this->usableUserKey($keys->userId());

        if ($row === null || (int) $row['key_version'] !== $keys->userKeyVersion() || !hash_equals((string) $row['public_key'], $keys->publicKey())) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_CHANGED', 409);
        }

        $kek = AppPassword::wrappingKey($appPasswordRaw, $kekSalt, $this->services->keyProvider());
        $wrapped = CipherSuite::encrypt($kek, $keys->secretKey(), self::deviceAd($keys->userId(), $keys->userKeyVersion(), $kekSalt));
        CipherSuite::wipe($kek);

        return $wrapped;
    }

    public function unlockWithDevice(int $userId, #[\SensitiveParameter] string $appPasswordRaw, string $kekSalt, string $uskWrapped): ?KeyContext
    {
        $row = $this->usableUserKey($userId);

        if ($row === null || \strlen($kekSalt) !== AppPassword::SALT_BYTES) {
            return null;
        }

        $version = (int) $row['key_version'];
        $kek = AppPassword::wrappingKey($appPasswordRaw, $kekSalt, $this->services->keyProvider());
        $secret = CipherSuite::decrypt($kek, $uskWrapped, self::deviceAd($userId, $version, $kekSalt));
        CipherSuite::wipe($kek);

        return $this->contextFrom($userId, $version, $secret, (string) $row['public_key']);
    }

    // ------------------------------------------------------------------
    // Calendar keys
    // ------------------------------------------------------------------

    public function createCalendarKeys(int $calendarId, int $ownerUserId): void
    {
        $recovery = $this->recoveryRow();

        if ($recovery === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_NO_RECOVERY_KEY', 409);
        }

        $owner = $this->usableUserKey($ownerUserId);

        if ($owner === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_NO_OWNER_KEY', 409);
        }

        $db = $this->services->db();
        $exists = $db->setQuery(
            $this->query()->select('COUNT(*)')->from($db->quoteName('#__appointments_calendar_keys'))
                ->where($db->quoteName('calendar_id') . ' = ' . $calendarId)
        )->loadResult();

        if ((int) $exists > 0) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_EXISTS', 409);
        }

        $calendarKey = CipherSuite::randomKey();
        $db->transactionStart(true);

        try {
            $this->insertKeyVersion($calendarId, 1, $calendarKey, $recovery);
            $this->writeWrap($calendarId, 1, $ownerUserId, (int) $owner['key_version'], (string) $owner['public_key'], $calendarKey);
            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__appointments_calendars')
                . ' SET ' . $db->quoteName('key_version') . ' = 1, ' . $db->quoteName('rotation_due') . ' = 0'
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $calendarId
            )->execute();
            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $db->transactionRollback(true);

            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_OPERATION', 500);
        } finally {
            CipherSuite::wipe($calendarKey);
            unset($this->calendarKeyRows[$calendarId . ':1']);
        }
    }

    /**
     * Also carries out a due rotation (`rotation_due`): the next key holder's
     * request creates the next version, so the returned keyring may be newer
     * than the stored one was.
     */
    public function openKeyring(int $calendarId, KeyContext $keys): ?CalendarKeyring
    {
        $calendar = $this->calendarState($calendarId);
        $row = $this->usableUserKey($keys->userId());

        if ($calendar === null || $row === null || (int) $row['key_version'] !== $keys->userKeyVersion()
            || !hash_equals((string) $row['public_key'], $keys->publicKey())) {
            return null;
        }

        $db = $this->services->db();
        $wraps = $db->setQuery(
            $this->query()
                ->select($db->quoteName(['key_version', 'wrapped']))
                ->from($db->quoteName('#__appointments_calendar_key_wraps'))
                ->where($db->quoteName('calendar_id') . ' = ' . $calendarId)
                ->where($db->quoteName('user_id') . ' = ' . $keys->userId())
                ->where($db->quoteName('user_key_version') . ' = ' . $keys->userKeyVersion())
        )->loadAssocList() ?: [];

        $calendarKeys = [];

        foreach ($wraps as $wrap) {
            $version = (int) $wrap['key_version'];
            $key = $this->openWrap($calendarId, $version, (string) $wrap['wrapped'], $keys);

            if ($key !== null) {
                $calendarKeys[$version] = $key;
            }
        }

        if (!isset($calendarKeys[$calendar['key_version']])) {
            foreach ($calendarKeys as $key) {
                $key->wipe();
            }

            return null;
        }

        $keyring = new CalendarKeyring($calendarId, $calendar['key_version'], array_values($calendarKeys));

        if ($calendar['rotation_due']) {
            try {
                $keyring = $this->rotateCalendarKey($keyring);
            } catch (\Throwable $e) {
                // Rotation waits for the next key holder; the keyring still works.
                $this->warn('a due key rotation was postponed', $e);
            }
        }

        return $keyring;
    }

    public function wrapForUser(CalendarKey $key, int $userId): bool
    {
        $row = $this->usableUserKey($userId);

        if ($row === null) {
            return false;
        }

        $this->writeWrap($key->calendarId(), $key->version(), $userId, (int) $row['key_version'], (string) $row['public_key'], $key->key());

        return true;
    }

    /**
     * Also re-encrypts a batch of objects of older versions and sealed objects
     * (`CalendarObjectStoreInterface::reencrypt()`), so a rotation completes
     * over the next key holders' requests.
     */
    public function completePendingWraps(CalendarKeyring $keyring): int
    {
        $calendarId = $keyring->calendarId();
        $current = $keyring->current()->version();
        $written = 0;

        foreach ($this->keyHolders($calendarId) as $holder) {
            $have = $this->wrappedVersions($calendarId, $holder['user_id'], $holder['key_version']);
            $completed = 0;

            for ($version = 1; $version <= $current; $version++) {
                $key = $keyring->version($version);

                if ($key === null || isset($have[$version])) {
                    continue;
                }

                $this->writeWrap($calendarId, $version, $holder['user_id'], $holder['key_version'], $holder['public_key'], $key->key());
                $completed++;
            }

            if ($completed > 0) {
                $written += $completed;
                $this->log(AuditEvent::GRANT_KEY_COMPLETED, ['subject_user_id' => $holder['user_id'], 'calendar_id' => $calendarId]);
            }
        }

        if ($this->hasStaleObjects($calendarId, $current)) {
            try {
                $this->services->objects()->reencrypt($keyring, self::REENCRYPT_BATCH);
            } catch (\Throwable $e) {
                $this->warn('a re-encryption batch was postponed', $e);
            }
        }

        return $written;
    }

    public function deleteUserWraps(int $calendarId, int $userId): void
    {
        $db = $this->services->db();
        $db->setQuery(
            'DELETE FROM ' . $db->quoteName('#__appointments_calendar_key_wraps')
            . ' WHERE ' . $db->quoteName('calendar_id') . ' = ' . $calendarId
            . ' AND ' . $db->quoteName('user_id') . ' = ' . $userId
        )->execute();
    }

    public function rotateCalendarKey(CalendarKeyring $keyring): CalendarKeyring
    {
        $calendarId = $keyring->calendarId();
        $recovery = $this->recoveryRow();

        if ($recovery === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_NO_RECOVERY_KEY', 409);
        }

        $db = $this->services->db();
        $newKey = CipherSuite::randomKey();
        $db->transactionStart(true);

        try {
            $query = $this->query()
                ->select($db->quoteName('key_version'))
                ->from($db->quoteName('#__appointments_calendars'))
                ->where($db->quoteName('id') . ' = ' . $calendarId);
            $stored = $db->setQuery((string) $query . ' FOR UPDATE')->loadResult();

            if ($stored === null || (int) $stored !== $keyring->current()->version()) {
                // Somebody rotated in the meantime; the caller keeps its keyring.
                $db->transactionCommit(true);
                CipherSuite::wipe($newKey);

                return $keyring;
            }

            $version = (int) $stored + 1;
            $this->insertKeyVersion($calendarId, $version, $newKey, $recovery);

            foreach ($this->keyHolders($calendarId) as $holder) {
                $this->writeWrap($calendarId, $version, $holder['user_id'], $holder['key_version'], $holder['public_key'], $newKey);
            }

            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__appointments_calendars')
                . ' SET ' . $db->quoteName('key_version') . ' = ' . $version . ', ' . $db->quoteName('rotation_due') . ' = 0'
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $calendarId
            )->execute();
            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $db->transactionRollback(true);
            CipherSuite::wipe($newKey);

            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_OPERATION', 500);
        }

        $keys = [CalendarKey::create($calendarId, $version, $newKey)];
        CipherSuite::wipe($newKey);

        for ($old = 1; $old < $version; $old++) {
            if ($keyring->version($old) !== null) {
                $keys[] = $keyring->version($old);
            }
        }

        $this->log(AuditEvent::CALENDAR_KEY_ROTATED, ['calendar_id' => $calendarId, 'details' => ['version' => $version]]);

        return new CalendarKeyring($calendarId, $version, $keys);
    }

    public function recoverKeyring(int $calendarId, #[\SensitiveParameter] string $recoverySecret): ?CalendarKeyring
    {
        $secret = self::parseRecoverySecret($recoverySecret);
        $row = $this->recoveryRow();
        $calendar = $this->calendarState($calendarId);

        if ($secret === null || $row === null || $calendar === null || !hash_equals((string) $row['public_key'], CipherSuite::publicKeyOf($secret))) {
            CipherSuite::wipe($secret);

            return null;
        }

        $db = $this->services->db();
        $versions = $db->setQuery(
            $this->query()->select($db->quoteName('key_version'))->from($db->quoteName('#__appointments_calendar_keys'))
                ->where($db->quoteName('calendar_id') . ' = ' . $calendarId)
        )->loadColumn() ?: [];
        $calendarKeys = [];

        foreach ($versions as $version) {
            $version = (int) $version;
            $keyRow = $this->calendarKeyRow($calendarId, $version);

            if ($keyRow === null) {
                continue;
            }

            $calendarKey = CipherSuite::open($secret, (string) $row['public_key'], (string) $keyRow['recovery_wrapped']);

            if ($calendarKey !== null && $this->opensBox($calendarId, $version, $calendarKey, $keyRow)) {
                $calendarKeys[$version] = CalendarKey::create($calendarId, $version, $calendarKey);
            }

            CipherSuite::wipe($calendarKey);
        }

        CipherSuite::wipe($secret);

        if (!isset($calendarKeys[$calendar['key_version']])) {
            foreach ($calendarKeys as $key) {
                $key->wipe();
            }

            return null;
        }

        $this->log(AuditEvent::RECOVERY_USED, ['calendar_id' => $calendarId]);

        return new CalendarKeyring($calendarId, $calendar['key_version'], array_values($calendarKeys));
    }

    // ------------------------------------------------------------------
    // Objects, timing, hashes
    // ------------------------------------------------------------------

    public function encryptObject(CalendarKey $key, string $uri, string $ics): string
    {
        return CipherSuite::encrypt($key->key(), $ics, CipherSuite::associatedData('object', $key->calendarId(), $uri, $key->version()));
    }

    public function sealObject(int $calendarId, string $uri, string $ics): array
    {
        $calendar = $this->calendarState($calendarId);
        $keyRow = $calendar === null ? null : $this->calendarKeyRow($calendarId, $calendar['key_version']);

        if ($keyRow === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_NO_CALENDAR_KEY', 409);
        }

        $envelope = self::ENVELOPE . json_encode(['c' => $calendarId, 'u' => $uri], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n" . $ics;

        return [
            'scheme'  => TeamStates::SCHEME_SEALED,
            'version' => $calendar['key_version'],
            'payload' => CipherSuite::seal((string) $keyRow['box_public_key'], $envelope),
        ];
    }

    public function decryptObject(CalendarKeyring $keyring, StoredObject $object): ?string
    {
        $key = $object->calendarId === $keyring->calendarId() ? $keyring->version($object->keyVersion) : null;

        if ($key === null) {
            return null;
        }

        if ($object->scheme === TeamStates::SCHEME_CALENDAR_KEY) {
            return CipherSuite::decrypt($key->key(), $object->payload, CipherSuite::associatedData('object', $object->calendarId, $object->uri, $object->keyVersion));
        }

        if ($object->scheme !== TeamStates::SCHEME_SEALED) {
            return null;
        }

        $keyRow = $this->calendarKeyRow($object->calendarId, $object->keyVersion);

        if ($keyRow === null) {
            return null;
        }

        $boxSecret = $this->boxSecret($object->calendarId, $object->keyVersion, $key->key(), $keyRow);

        if ($boxSecret === null) {
            return null;
        }

        $plain = CipherSuite::open($boxSecret, (string) $keyRow['box_public_key'], $object->payload);
        CipherSuite::wipe($boxSecret);

        return $plain === null ? null : self::openEnvelope($plain, $object->calendarId, $object->uri);
    }

    public function sealTiming(int $calendarId, string $uri, string $skeleton): string
    {
        $key = $this->serverKey(KeyProvider::PURPOSE_TEAM_TIMING);
        $sealed = CipherSuite::encrypt($key, $skeleton, CipherSuite::associatedData('timing', $calendarId, $uri));
        CipherSuite::wipe($key);

        return $sealed;
    }

    public function openTiming(int $calendarId, string $uri, string $timing): ?string
    {
        $key = $this->serverKey(KeyProvider::PURPOSE_TEAM_TIMING);
        $plain = CipherSuite::decrypt($key, $timing, CipherSuite::associatedData('timing', $calendarId, $uri));
        CipherSuite::wipe($key);

        return $plain;
    }

    public function uidHash(int $calendarId, string $uid): string
    {
        $key = $this->serverKey(KeyProvider::PURPOSE_TEAM_UID);
        $hash = hash_hmac('sha256', $calendarId . "\0" . $uid, $key, true);
        CipherSuite::wipe($key);

        return $hash;
    }

    /**
     * Specification 5.2: `HMAC-SHA256(key(TEAM_ROW_MAC), associatedData('row', table, field1, value1, …))`.
     * Encoding, fixed here for everybody: the table name is normalised
     * (`#__appointments_x`, `appointments_x` and `x` are one table); fields in
     * ascending name order, so the caller's order does not matter; a value is
     * `N` for null and `V` followed by its string form otherwise, booleans as 0/1.
     */
    public function rowMac(string $table, array $fields): string
    {
        ksort($fields, SORT_STRING);
        $parts = [];

        foreach ($fields as $name => $value) {
            if (!\is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('A MAC field holds a scalar or null.');
            }

            $parts[] = (string) $name;
            $parts[] = $value === null ? 'N' : 'V' . (\is_bool($value) ? (string) (int) $value : (string) $value);
        }

        $key = $this->serverKey(KeyProvider::PURPOSE_TEAM_ROW_MAC);
        $mac = hash_hmac('sha256', CipherSuite::associatedData('row', self::canonicalTable($table), ...$parts), $key, true);
        CipherSuite::wipe($key);

        return $mac;
    }

    public function verifyRowMac(string $table, array $fields, string $mac): bool
    {
        return \strlen($mac) === 32 && hash_equals($this->rowMac($table, $fields), $mac);
    }

    // ------------------------------------------------------------------
    // For the key session (same work package), not part of the interface
    // ------------------------------------------------------------------

    /**
     * The state of a user's key row for a sign-in: `none` (no row), `reset`
     * (no usable secret, the next password sign-in creates a pair), `usable`
     * or `tampered` (MAC mismatch, logged). Only public data.
     *
     * @return  array{status: string, version: int, public_key: string, salt: string, opslimit: int, memlimit: int}
     */
    public function userKeyStatus(int $userId): array
    {
        $raw = $this->rawUserKeyRow($userId);

        if ($raw === null) {
            return ['status' => 'none', 'version' => 0, 'public_key' => '', 'salt' => '', 'opslimit' => 0, 'memlimit' => 0];
        }

        $status = 'usable';

        if (!$this->verifyStoredRow('user_keys', $raw)) {
            $this->macFailure('user_keys', ['subject_user_id' => $userId]);
            $status = 'tampered';
        } elseif ($raw['password_wrapped'] === null || !CipherSuite::acceptableLimits((int) $raw['pwhash_opslimit'], (int) $raw['pwhash_memlimit'])) {
            $status = 'reset';
        }

        return [
            'status'     => $status,
            'version'    => (int) $raw['key_version'],
            'public_key' => (string) $raw['public_key'],
            'salt'       => (string) $raw['password_salt'],
            'opslimit'   => (int) $raw['pwhash_opslimit'],
            'memlimit'   => (int) $raw['pwhash_memlimit'],
        ];
    }

    /**
     * The password key of specification 5.2 (the caller wipes it).
     */
    public function passwordKek(string $password, string $salt, int $opslimit, int $memlimit): string
    {
        $server = $this->serverKey(KeyProvider::PURPOSE_TEAM_PASSWORD_KEK);

        try {
            return CipherSuite::passwordKey($password, $salt, $opslimit, $memlimit, $server);
        } finally {
            CipherSuite::wipe($server);
        }
    }

    /**
     * Unwrap the user's secret with an already derived password key.
     */
    public function unlockWithKek(int $userId, string $kek): ?KeyContext
    {
        $row = $this->usableUserKey($userId);

        if ($row === null) {
            return null;
        }

        $version = (int) $row['key_version'];
        $secret = CipherSuite::decrypt($kek, (string) $row['password_wrapped'], self::passwordAd($userId, $version));

        return $this->contextFrom($userId, $version, $secret, (string) $row['public_key']);
    }

    /**
     * A key pair wrapped with an already derived password key. Replaces only a
     * row in state `reset`; a usable or tampered row is refused with 409.
     */
    public function createUserKeyWithKek(int $userId, string $kek, string $salt, int $opslimit, int $memlimit): KeyContext
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('A key pair belongs to a user.');
        }

        $status = $this->userKeyStatus($userId);

        if ($status['status'] === 'usable' || $status['status'] === 'tampered') {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_EXISTS', 409);
        }

        $version = $status['status'] === 'none' ? 1 : $status['version'] + 1;
        $pair = CipherSuite::keyPair();
        $fields = [
            'user_id'          => $userId,
            'key_version'      => $version,
            'public_key'       => $pair['public'],
            'password_wrapped' => CipherSuite::encrypt($kek, $pair['secret'], self::passwordAd($userId, $version)),
            'password_salt'    => $salt,
            'pwhash_opslimit'  => $opslimit,
            'pwhash_memlimit'  => $memlimit,
        ];
        $mac = $this->rowMac('user_keys', $fields);
        $db = $this->services->db();
        $now = $this->nowSql();

        try {
            if ($status['status'] === 'none') {
                $this->insert('#__appointments_user_keys', $fields + ['row_mac' => $mac, 'created_utc' => $now, 'modified_utc' => $now], ['public_key', 'password_wrapped', 'password_salt', 'row_mac']);
            } else {
                $db->setQuery(
                    'UPDATE ' . $db->quoteName('#__appointments_user_keys')
                    . ' SET ' . $db->quoteName('key_version') . ' = ' . $version
                    . ', ' . $db->quoteName('public_key') . ' = ' . self::hex($fields['public_key'])
                    . ', ' . $db->quoteName('password_wrapped') . ' = ' . self::hex($fields['password_wrapped'])
                    . ', ' . $db->quoteName('password_salt') . ' = ' . self::hex($salt)
                    . ', ' . $db->quoteName('pwhash_opslimit') . ' = ' . $opslimit
                    . ', ' . $db->quoteName('pwhash_memlimit') . ' = ' . $memlimit
                    . ', ' . $db->quoteName('row_mac') . ' = ' . self::hex($mac)
                    . ', ' . $db->quoteName('modified_utc') . ' = ' . $db->quote($now)
                    . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $userId
                    . ' AND ' . $db->quoteName('key_version') . ' = ' . $status['version']
                    . ' AND ' . $db->quoteName('password_wrapped') . ' IS NULL'
                )->execute();

                if ($db->getAffectedRows() !== 1) {
                    throw new \RuntimeException('Concurrent change.');
                }

                $this->deleteAllWrapsOfUser($userId);
            }
        } catch (\Throwable $e) {
            CipherSuite::wipe($pair['secret']);

            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_EXISTS', 409);
        }

        $context = KeyContext::create($userId, $version, $pair['secret'], $pair['public']);
        CipherSuite::wipe($pair['secret']);
        $this->log(AuditEvent::USER_KEY_CREATED, ['subject_user_id' => $userId, 'details' => ['version' => $version]]);

        return $context;
    }

    // ------------------------------------------------------------------
    // Formulas (pure, public for the unit tests)
    // ------------------------------------------------------------------

    public static function passwordAd(int $userId, int $userKeyVersion): string
    {
        return CipherSuite::associatedData('user-key/password', $userId, $userKeyVersion);
    }

    /**
     * Associated data of a device wrap. The interface has no device id, so the
     * wrap is bound to the device's own random salt instead, which lives under
     * the device row's MAC.
     */
    public static function deviceAd(int $userId, int $userKeyVersion, string $kekSalt): string
    {
        return CipherSuite::associatedData('user-key/device', $userId, $userKeyVersion, bin2hex($kekSalt));
    }

    public static function boxAd(int $calendarId, int $version): string
    {
        return CipherSuite::associatedData('calendar-box', $calendarId, $version);
    }

    /**
     * Fingerprint of a recovery public key: 16 upper-case hex characters.
     */
    public static function fingerprintOf(string $publicKey): string
    {
        return strtoupper(substr(hash('sha256', 'com_appointments/recovery-fingerprint/v1|' . $publicKey), 0, 16));
    }

    /**
     * The printed form of a recovery secret: the 32 secret bytes and a 3-byte
     * typing check, Base32, 14 groups of four characters.
     */
    public static function formatRecoverySecret(string $secret): string
    {
        if (\strlen($secret) !== CipherSuite::KEY_BYTES) {
            throw new \InvalidArgumentException('A recovery secret has exactly 32 bytes.');
        }

        $data = $secret . self::recoveryCheck($secret);
        $bits = '';

        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $text = '';

        foreach (str_split($bits, 5) as $chunk) {
            $text .= self::BASE32[bindec($chunk)];
        }

        CipherSuite::wipe($data);
        CipherSuite::wipe($bits);

        return implode('-', str_split($text, 4));
    }

    /**
     * The 32 secret bytes of a typed recovery secret, null when it is not one
     * (wrong length, wrong character, failed typing check). Case, spaces and
     * hyphens are ignored; 0, 1 and 8 are read as O, I and B.
     */
    public static function parseRecoverySecret(string $input): ?string
    {
        $text = strtr(strtoupper(preg_replace('/[\s\-]+/', '', $input) ?? ''), ['0' => 'O', '1' => 'I', '8' => 'B']);
        $chars = (int) ceil((CipherSuite::KEY_BYTES + self::RECOVERY_CHECK_BYTES) * 8 / 5);

        if (\strlen($text) !== $chars || strspn($text, self::BASE32) !== $chars) {
            return null;
        }

        $bits = '';

        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);
        }

        $data = '';

        foreach (str_split(substr($bits, 0, (CipherSuite::KEY_BYTES + self::RECOVERY_CHECK_BYTES) * 8), 8) as $byte) {
            $data .= \chr(bindec($byte));
        }

        CipherSuite::wipe($bits);
        CipherSuite::wipe($text);
        $secret = substr($data, 0, CipherSuite::KEY_BYTES);
        $ok = hash_equals(self::recoveryCheck($secret), substr($data, CipherSuite::KEY_BYTES));
        CipherSuite::wipe($data);

        if (!$ok) {
            CipherSuite::wipe($secret);

            return null;
        }

        return $secret;
    }

    /**
     * The iCalendar text of an opened scheme-2 envelope, null when it was
     * written for another calendar or URI.
     */
    public static function openEnvelope(string $plain, int $calendarId, string $uri): ?string
    {
        if (!str_starts_with($plain, self::ENVELOPE)) {
            return null;
        }

        $end = strpos($plain, "\n", \strlen(self::ENVELOPE));

        if ($end === false) {
            return null;
        }

        $meta = json_decode(substr($plain, \strlen(self::ENVELOPE), $end - \strlen(self::ENVELOPE)), true);

        if (!\is_array($meta) || ($meta['c'] ?? null) !== $calendarId || ($meta['u'] ?? null) !== $uri) {
            return null;
        }

        return substr($plain, $end + 1);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function recoveryCheck(string $secret): string
    {
        return substr(hash('sha256', 'com_appointments/recovery-check/v1|' . $secret, true), 0, self::RECOVERY_CHECK_BYTES);
    }

    private static function canonicalTable(string $table): string
    {
        $name = preg_replace('/^(#__)?(appointments_)?/', '', $table) ?? '';

        if (!\in_array($name, self::MAC_TABLES, true)) {
            throw new \InvalidArgumentException('No row MAC is defined for that table.');
        }

        return 'appointments_' . $name;
    }

    private static function hex(string $bytes): string
    {
        return "X'" . bin2hex($bytes) . "'";
    }

    private function contextFrom(int $userId, int $version, ?string $secret, string $publicKey): ?KeyContext
    {
        if ($secret === null || \strlen($secret) !== CipherSuite::KEY_BYTES || !hash_equals($publicKey, CipherSuite::publicKeyOf($secret))) {
            CipherSuite::wipe($secret);

            return null;
        }

        $context = KeyContext::create($userId, $version, $secret, $publicKey);
        CipherSuite::wipe($secret);

        return $context;
    }

    private function serverKey(string $purpose): string
    {
        return $this->services->keyProvider()->key($purpose);
    }

    private function query(): object
    {
        $db = $this->services->db();

        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    private function nowSql(): string
    {
        return $this->services->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param   array<string, scalar|null>  $row     Column => value.
     * @param   string[]                    $binary  Columns written as hex literals.
     */
    private function insert(string $table, array $row, array $binary): void
    {
        $db = $this->services->db();
        $columns = [];
        $values = [];

        foreach ($row as $column => $value) {
            $columns[] = $db->quoteName($column);
            $values[] = match (true) {
                $value === null => 'NULL',
                \in_array($column, $binary, true) => self::hex((string) $value),
                \is_int($value) => (string) $value,
                default => $db->quote((string) $value),
            };
        }

        $db->setQuery('INSERT INTO ' . $db->quoteName($table) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')')->execute();
    }

    /**
     * @param   array<string, mixed>  $row  A stored row including `row_mac`.
     */
    private function verifyStoredRow(string $table, array $row): bool
    {
        $fields = [];

        foreach (self::macFields($table) as $name) {
            if (!\array_key_exists($name, $row)) {
                return false;
            }

            $fields[$name] = $row[$name];
        }

        return $this->verifyRowMac($table, $fields, (string) ($row['row_mac'] ?? ''));
    }

    /**
     * @return  array<string, mixed>|null
     */
    private function rawRecoveryRow(): ?array
    {
        $db = $this->services->db();

        return $db->setQuery(
            $this->query()
                ->select($db->quoteName(['purpose', 'public_key', 'fingerprint', 'row_mac', 'created_by', 'created_utc']))
                ->from($db->quoteName('#__appointments_keyring'))
                ->where($db->quoteName('purpose') . ' = ' . $db->quote(self::RECOVERY))
        )->loadAssoc() ?: null;
    }

    /**
     * @return  array<string, mixed>|null  The recovery row after its MAC is verified.
     */
    private function recoveryRow(): ?array
    {
        $row = $this->rawRecoveryRow();

        if ($row === null) {
            return null;
        }

        if (!$this->verifyStoredRow('keyring', $row) || \strlen((string) $row['public_key']) !== CipherSuite::KEY_BYTES) {
            $this->macFailure('keyring', []);

            return null;
        }

        return $row;
    }

    /**
     * @return  array<string, mixed>|null
     */
    private function rawUserKeyRow(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $db = $this->services->db();

        return $db->setQuery(
            $this->query()
                ->select($db->quoteName(['user_id', 'key_version', 'public_key', 'password_wrapped', 'password_salt', 'pwhash_opslimit', 'pwhash_memlimit', 'row_mac']))
                ->from($db->quoteName('#__appointments_user_keys'))
                ->where($db->quoteName('user_id') . ' = ' . $userId)
        )->loadAssoc() ?: null;
    }

    /**
     * @return  array<string, mixed>|null  A verified row with a usable wrapped secret.
     */
    private function usableUserKey(int $userId): ?array
    {
        $row = $this->rawUserKeyRow($userId);

        if ($row === null) {
            return null;
        }

        if (!$this->verifyStoredRow('user_keys', $row) || \strlen((string) $row['public_key']) !== CipherSuite::KEY_BYTES) {
            $this->macFailure('user_keys', ['subject_user_id' => $userId]);

            return null;
        }

        if ($row['password_wrapped'] === null || !CipherSuite::acceptableLimits((int) $row['pwhash_opslimit'], (int) $row['pwhash_memlimit'])) {
            return null;
        }

        return $row;
    }

    /**
     * @return  array{key_version: int, rotation_due: bool}|null
     */
    private function calendarState(int $calendarId): ?array
    {
        if ($calendarId <= 0) {
            return null;
        }

        $db = $this->services->db();
        $row = $db->setQuery(
            $this->query()
                ->select($db->quoteName(['key_version', 'rotation_due']))
                ->from($db->quoteName('#__appointments_calendars'))
                ->where($db->quoteName('id') . ' = ' . $calendarId)
        )->loadAssoc();

        return $row ? ['key_version' => (int) $row['key_version'], 'rotation_due' => (int) $row['rotation_due'] === 1] : null;
    }

    /**
     * @return  array<string, mixed>|null  The verified `calendar_keys` row.
     */
    private function calendarKeyRow(int $calendarId, int $version): ?array
    {
        $cacheKey = $calendarId . ':' . $version;

        if (!\array_key_exists($cacheKey, $this->calendarKeyRows)) {
            $db = $this->services->db();
            $row = $db->setQuery(
                $this->query()
                    ->select($db->quoteName(['calendar_id', 'key_version', 'box_public_key', 'box_secret_wrapped', 'recovery_wrapped', 'recovery_fingerprint', 'row_mac']))
                    ->from($db->quoteName('#__appointments_calendar_keys'))
                    ->where($db->quoteName('calendar_id') . ' = ' . $calendarId)
                    ->where($db->quoteName('key_version') . ' = ' . $version)
            )->loadAssoc();

            if ($row && (!$this->verifyStoredRow('calendar_keys', $row) || \strlen((string) $row['box_public_key']) !== CipherSuite::KEY_BYTES)) {
                $this->macFailure('calendar_keys', ['calendar_id' => $calendarId]);
                $row = null;
            }

            $this->calendarKeyRows[$cacheKey] = $row ?: false;
        }

        return $this->calendarKeyRows[$cacheKey] ?: null;
    }

    /**
     * The sealing secret of a key version (the caller wipes it), null when the
     * calendar key does not open it or it does not belong to the row's public key.
     *
     * @param   array<string, mixed>  $keyRow
     */
    private function boxSecret(int $calendarId, int $version, string $calendarKey, array $keyRow): ?string
    {
        $secret = CipherSuite::decrypt($calendarKey, (string) $keyRow['box_secret_wrapped'], self::boxAd($calendarId, $version));

        if ($secret === null || \strlen($secret) !== CipherSuite::KEY_BYTES || !hash_equals((string) $keyRow['box_public_key'], CipherSuite::publicKeyOf($secret))) {
            CipherSuite::wipe($secret);

            return null;
        }

        return $secret;
    }

    /**
     * Whether a candidate calendar key is the real key of this version.
     *
     * @param   array<string, mixed>  $keyRow  The verified row.
     */
    private function opensBox(int $calendarId, int $version, string $calendarKey, array $keyRow): bool
    {
        if (\strlen($calendarKey) !== CipherSuite::KEY_BYTES) {
            return false;
        }

        $secret = $this->boxSecret($calendarId, $version, $calendarKey, $keyRow);
        $ok = $secret !== null;
        CipherSuite::wipe($secret);

        return $ok;
    }

    private function openWrap(int $calendarId, int $version, string $wrapped, KeyContext $keys): ?CalendarKey
    {
        $keyRow = $this->calendarKeyRow($calendarId, $version);
        $calendarKey = $keyRow === null ? null : CipherSuite::open($keys->secretKey(), $keys->publicKey(), $wrapped);

        if ($calendarKey === null) {
            return null;
        }

        if (!$this->opensBox($calendarId, $version, $calendarKey, $keyRow)) {
            CipherSuite::wipe($calendarKey);
            $this->macFailure('calendar_key_wraps', ['calendar_id' => $calendarId, 'subject_user_id' => $keys->userId()]);

            return null;
        }

        $key = CalendarKey::create($calendarId, $version, $calendarKey);
        CipherSuite::wipe($calendarKey);

        return $key;
    }

    /**
     * @param   array<string, mixed>  $recovery  The verified recovery row.
     */
    private function insertKeyVersion(int $calendarId, int $version, string $calendarKey, array $recovery): void
    {
        $box = CipherSuite::keyPair();
        $fields = [
            'calendar_id'          => $calendarId,
            'key_version'          => $version,
            'box_public_key'       => $box['public'],
            'box_secret_wrapped'   => CipherSuite::encrypt($calendarKey, $box['secret'], self::boxAd($calendarId, $version)),
            'recovery_wrapped'     => CipherSuite::seal((string) $recovery['public_key'], $calendarKey),
            'recovery_fingerprint' => (string) $recovery['fingerprint'],
        ];
        CipherSuite::wipe($box['secret']);
        $this->insert(
            '#__appointments_calendar_keys',
            $fields + ['row_mac' => $this->rowMac('calendar_keys', $fields), 'created_utc' => $this->nowSql()],
            ['box_public_key', 'box_secret_wrapped', 'recovery_wrapped', 'row_mac']
        );
        unset($this->calendarKeyRows[$calendarId . ':' . $version]);
    }

    /**
     * @param   array<string, mixed>  $keyRow  Row with new recovery fields.
     */
    private function updateCalendarKeyRow(array $keyRow): void
    {
        $db = $this->services->db();
        $fields = [];

        foreach (self::macFields('calendar_keys') as $name) {
            $fields[$name] = $keyRow[$name];
        }

        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__appointments_calendar_keys')
            . ' SET ' . $db->quoteName('recovery_wrapped') . ' = ' . self::hex((string) $keyRow['recovery_wrapped'])
            . ', ' . $db->quoteName('recovery_fingerprint') . ' = ' . $db->quote((string) $keyRow['recovery_fingerprint'])
            . ', ' . $db->quoteName('row_mac') . ' = ' . self::hex($this->rowMac('calendar_keys', $fields))
            . ' WHERE ' . $db->quoteName('calendar_id') . ' = ' . (int) $keyRow['calendar_id']
            . ' AND ' . $db->quoteName('key_version') . ' = ' . (int) $keyRow['key_version']
        )->execute();
    }

    private function writeWrap(int $calendarId, int $version, int $userId, int $userKeyVersion, string $publicKey, string $calendarKey): void
    {
        $db = $this->services->db();
        $wrapped = CipherSuite::seal($publicKey, $calendarKey);
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__appointments_calendar_key_wraps')
            . ' (' . implode(', ', $db->quoteName(['calendar_id', 'key_version', 'user_id', 'user_key_version', 'wrapped', 'created_utc'])) . ')'
            . ' VALUES (' . $calendarId . ', ' . $version . ', ' . $userId . ', ' . $userKeyVersion . ', ' . self::hex($wrapped) . ', ' . $db->quote($this->nowSql()) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('user_key_version') . ' = VALUES(' . $db->quoteName('user_key_version') . ')'
            . ', ' . $db->quoteName('wrapped') . ' = VALUES(' . $db->quoteName('wrapped') . ')'
            . ', ' . $db->quoteName('created_utc') . ' = VALUES(' . $db->quoteName('created_utc') . ')'
        )->execute();
    }

    private function deleteAllWrapsOfUser(int $userId): void
    {
        $db = $this->services->db();
        $db->setQuery(
            'DELETE FROM ' . $db->quoteName('#__appointments_calendar_key_wraps')
            . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $userId
        )->execute();
    }

    /**
     * @return  array<int, true>  Versions the user holds a wrap of with this user key version.
     */
    private function wrappedVersions(int $calendarId, int $userId, int $userKeyVersion): array
    {
        $db = $this->services->db();
        $versions = $db->setQuery(
            $this->query()
                ->select($db->quoteName('key_version'))
                ->from($db->quoteName('#__appointments_calendar_key_wraps'))
                ->where($db->quoteName('calendar_id') . ' = ' . $calendarId)
                ->where($db->quoteName('user_id') . ' = ' . $userId)
                ->where($db->quoteName('user_key_version') . ' = ' . $userKeyVersion)
        )->loadColumn() ?: [];

        return array_fill_keys(array_map('intval', $versions), true);
    }

    /**
     * Grantees that hold a key: grant MAC valid, state active or waiting for
     * its key (never suspended, never waiting for confirmation), a key-holding
     * role, an effective account and a usable key pair. Without the grant
     * service's MAC field list (work package C not present) nobody qualifies.
     *
     * @return  array<int, array{user_id: int, key_version: int, public_key: string}>
     */
    private function keyHolders(int $calendarId): array
    {
        if (!method_exists(GrantService::class, 'macFields')) {
            return [];
        }

        /** @var string[] $macFields */
        $macFields = GrantService::macFields();
        $db = $this->services->db();
        $rows = $db->setQuery(
            $this->query()
                ->select($db->quoteName(array_values(array_unique(array_merge($macFields, ['user_id', 'role', 'state', 'row_mac'])))))
                ->from($db->quoteName('#__appointments_calendar_grants'))
                ->where($db->quoteName('calendar_id') . ' = ' . $calendarId)
                ->where($db->quoteName('state') . ' IN (' . TeamStates::GRANT_ACTIVE . ', ' . TeamStates::GRANT_PENDING_KEY . ')')
        )->loadAssocList() ?: [];
        $holders = [];

        foreach ($rows as $row) {
            $fields = [];

            foreach ($macFields as $name) {
                $fields[$name] = $row[$name];
            }

            if (!$this->verifyRowMac('calendar_grants', $fields, (string) $row['row_mac'])) {
                $this->macFailure('calendar_grants', ['calendar_id' => $calendarId, 'subject_user_id' => (int) $row['user_id']]);

                continue;
            }

            $role = \Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole::tryFrom((int) $row['role']);
            $userId = (int) $row['user_id'];

            if ($role === null || !$role->holdsKey() || !$this->accountEffective($userId)) {
                continue;
            }

            $key = $this->usableUserKey($userId);

            if ($key !== null) {
                $holders[$userId] = ['user_id' => $userId, 'key_version' => (int) $key['key_version'], 'public_key' => (string) $key['public_key']];
            }
        }

        return $holders;
    }

    private function accountEffective(int $userId): bool
    {
        try {
            $db = $this->services->db();
            $block = $db->setQuery(
                $this->query()->select($db->quoteName('block'))->from($db->quoteName('#__users'))
                    ->where($db->quoteName('id') . ' = ' . $userId)
            )->loadResult();

            return $block !== null && (int) $block === 0
                && $this->services->grants()->audienceOf($userId) !== 'none'
                && $this->services->grants()->hasSecondFactor($userId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasStaleObjects(int $calendarId, int $currentVersion): bool
    {
        $db = $this->services->db();
        $query = $this->query()
            ->select('1')
            ->from($db->quoteName('#__appointments_calendar_objects'))
            ->where($db->quoteName('calendar_id') . ' = ' . $calendarId)
            ->where('(' . $db->quoteName('enc_scheme') . ' = ' . TeamStates::SCHEME_SEALED
                . ' OR ' . $db->quoteName('key_version') . ' < ' . $currentVersion . ')');

        return (bool) $db->setQuery($query, 0, 1)->loadResult();
    }

    /**
     * @param   array<string, int>  $context
     */
    private function macFailure(string $table, array $context): void
    {
        $this->log(self::EVENT_MAC_FAILURE, $context + ['details' => ['table' => $table]]);
    }

    /**
     * Logging never fails the operation it records.
     *
     * @param   array<string, mixed>  $context
     */
    private function log(string $event, array $context): void
    {
        try {
            $this->services->audit()->log($event, $context);
        } catch (\Throwable $e) {
            $this->warn('an audit entry could not be written', $e);
        }
    }

    private function warn(string $what, \Throwable $e): void
    {
        // The class name only: a message could carry a path, an id or worse.
        try {
            Log::add('Internet calendars: ' . $what . ' (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
        } catch (\Throwable $ignored) {
        }
    }
}
