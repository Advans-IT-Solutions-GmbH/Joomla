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
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\StoredObject;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\UserFactoryInterface;

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
            'appointments_user_keys' => ['user_id', 'key_version', 'public_key', 'password_wrapped', 'password_salt', 'pwhash_opslimit', 'pwhash_memlimit', 'device_epoch'],
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
            // Under the lock of the recovery row nobody seals a new key version to
            // the old public key while the existing ones are moved to the new one.
            $locked = $this->recoveryRow(true);

            if ($locked === null || !hash_equals((string) $locked['public_key'], (string) $oldPublic)) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_RECOVERY_INVALID', 409);
            }

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
            $this->rollback();
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
            'device_epoch'     => (int) $row['device_epoch'],
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
            // A device epoch raised meanwhile (work package D) must not be signed back.
            . ' AND ' . $db->quoteName('device_epoch') . ' = ' . (int) $row['device_epoch']
        )->execute();

        if ($db->getAffectedRows() !== 1) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_CHANGED', 409);
        }
    }

    public function resetUserKey(int $userId): void
    {
        $this->resetRow($userId, null, null);
    }

    /**
     * A reset only if the row is still the one observed (version and public
     * key), compared under the lock of the reset; otherwise 409 and nothing
     * changes. For the key session: a stale escrow never resets a newer key.
     */
    public function resetUserKeyIf(int $userId, int $expectedVersion, string $expectedPublicKey): void
    {
        $this->resetRow($userId, $expectedVersion, $expectedPublicKey);
    }

    private function resetRow(int $userId, ?int $expectedVersion, ?string $expectedPublicKey): void
    {
        $db = $this->services->db();
        $db->transactionStart(true);

        try {
            $query = $this->query()
                ->select($db->quoteName(['user_id', 'key_version', 'public_key', 'password_wrapped', 'password_salt', 'pwhash_opslimit', 'pwhash_memlimit', 'device_epoch', 'row_mac']))
                ->from($db->quoteName('#__appointments_user_keys'))
                ->where($db->quoteName('user_id') . ' = ' . $userId);
            $stored = $db->setQuery((string) $query . ' FOR UPDATE')->loadAssoc() ?: null;
            $current = $stored === null ? null : (int) $stored['key_version'];

            // A row that fails its MAC is left alone: after a change of the site
            // secret every row fails, and the way back (the old secret) must still
            // find the user's key and wraps.
            if ($stored !== null && !$this->verifyStoredRow('user_keys', $stored)) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_TAMPERED', 409);
            }

            if ($expectedVersion !== null
                && ($stored === null || $current !== $expectedVersion || !hash_equals((string) $stored['public_key'], (string) $expectedPublicKey))) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_CHANGED', 409);
            }

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
                    'device_epoch'     => (int) $stored['device_epoch'],
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
                $this->rebindGrants($userId, $current);
            }

            $this->deleteAllWrapsOfUser($userId);
            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $this->rollback();

            if ($e instanceof TeamException) {
                if ($e->getMessage() === 'COM_APPOINTMENTS_TEAM_B_ERROR_KEY_TAMPERED') {
                    $this->macFailure('user_keys', 'mismatch', ['subject_user_id' => $userId]);
                }

                throw $e;
            }

            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_OPERATION', 500);
        }

        $this->log(AuditEvent::USER_KEY_RESET, ['subject_user_id' => $userId]);
    }

    /**
     * The repair of a user key row that fails its MAC. The row is not deleted
     * but replaced by a `reset` row of the next key version (new public key,
     * secret discarded, no password wrap), so no key version is ever used twice:
     * a grant signed for an older version can never verify again. The device
     * epoch rises above the stored and the anchored value and is anchored once
     * committed. In one transaction the user's grants are bound to the new version
     * and suspended, and the wraps go. Refused while the site secret looks
     * changed, because then every row fails and the old secret is the way back.
     * The actor must be the signed-in user; without a signed-in identity (CLI)
     * only the actor's own permission is checked.
     */
    public function discardUserKey(int $actorUserId, int $userId): void
    {
        $actor = $actorUserId > 0 ? Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($actorUserId) : null;
        $identity = Factory::getApplication()->getIdentity();

        if ($actor === null || $actor->guest || !$actor->authorise('core.admin', 'com_appointments')
            || ($identity !== null && !$identity->guest && (int) $identity->id !== $actorUserId)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_NOT_ALLOWED', 403);
        }

        if ($this->siteSecretSuspect()) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_SITE_SECRET', 409);
        }

        $stored = $this->rawUserKeyRow($userId);

        if ($stored === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_NO_USER_KEY', 404);
        }

        if ($this->verifyStoredRow('user_keys', $stored)) {
            // A row that verifies is never discarded: that is what a reset is for.
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_VERIFIES', 409);
        }

        $minimum = $this->anchorMinimum(EpochAnchorInterface::KIND_USER, $userId);
        $db = $this->services->db();
        $db->transactionStart(true);

        try {
            // Decided again under the lock: a key created or repaired meanwhile
            // must not be overwritten.
            $locked = $this->rawUserKeyRow($userId, true);

            if ($locked === null) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_NO_USER_KEY', 404);
            }

            if ($this->verifyStoredRow('user_keys', $locked)) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_VERIFIES', 409);
            }

            $previous = (int) $locked['key_version'];
            $epoch = max((int) $locked['device_epoch'], $minimum) + 1;
            $highestWrap = (int) $db->setQuery(
                $this->query()->select('MAX(' . $db->quoteName('user_key_version') . ')')
                    ->from($db->quoteName('#__appointments_calendar_key_wraps'))
                    ->where($db->quoteName('user_id') . ' = ' . $userId)
            )->loadResult();
            $pair = CipherSuite::keyPair();
            CipherSuite::wipe($pair['secret']);
            $fields = [
                'user_id'          => $userId,
                'key_version'      => max($previous, $highestWrap) + 1,
                'public_key'       => $pair['public'],
                'password_wrapped' => null,
                'password_salt'    => random_bytes(CipherSuite::PWHASH_SALT_BYTES),
                'pwhash_opslimit'  => CipherSuite::PWHASH_OPSLIMIT,
                'pwhash_memlimit'  => CipherSuite::PWHASH_MEMLIMIT,
                'device_epoch'     => $epoch,
            ];
            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__appointments_user_keys')
                . ' SET ' . $db->quoteName('key_version') . ' = ' . $fields['key_version']
                . ', ' . $db->quoteName('public_key') . ' = ' . self::hex($fields['public_key'])
                . ', ' . $db->quoteName('password_wrapped') . ' = NULL'
                . ', ' . $db->quoteName('password_salt') . ' = ' . self::hex($fields['password_salt'])
                . ', ' . $db->quoteName('pwhash_opslimit') . ' = ' . $fields['pwhash_opslimit']
                . ', ' . $db->quoteName('pwhash_memlimit') . ' = ' . $fields['pwhash_memlimit']
                . ', ' . $db->quoteName('device_epoch') . ' = ' . $epoch
                . ', ' . $db->quoteName('row_mac') . ' = ' . self::hex($this->rowMac('user_keys', $fields))
                . ', ' . $db->quoteName('modified_utc') . ' = ' . $db->quote($this->nowSql())
                . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $userId
            )->execute();
            $this->deleteAllWrapsOfUser($userId);
            $this->rebindGrants($userId, $previous);
            $this->services->grants()->suspendAllForUser($userId, 'key_discarded');
            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $this->rollback();

            throw $e instanceof TeamException ? $e : new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_OPERATION', 500);
        }

        $this->anchorAfterCommit(EpochAnchorInterface::KIND_USER, $userId);

        foreach ([
            fn () => $this->services->cleanup()->startForUser($userId, 'key_discarded', $actorUserId),
            fn () => $this->services->mailer()->accessSuspended($userId, 'key_discarded'),
        ] as $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $this->warn('a step after discarding a key failed', $e);
            }
        }

        $this->log(AuditEvent::USER_KEY_RESET, ['actor_user_id' => $actorUserId, 'subject_user_id' => $userId, 'details' => ['reason' => 'discarded']]);
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
        $owner = $this->usableUserKey($ownerUserId);

        if ($owner === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_NO_OWNER_KEY', 409);
        }

        $db = $this->services->db();
        $calendarKey = CipherSuite::randomKey();
        $db->transactionStart(true);

        try {
            // The recovery row is locked, so a recovery key rotation cannot run
            // between reading its public key and sealing to it.
            $recovery = $this->recoveryRow(true);

            if ($recovery === null) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_NO_RECOVERY_KEY', 409);
            }

            $exists = $db->setQuery(
                $this->query()->select('COUNT(*)')->from($db->quoteName('#__appointments_calendar_keys'))
                    ->where($db->quoteName('calendar_id') . ' = ' . $calendarId)
            )->loadResult();

            if ((int) $exists > 0) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_EXISTS', 409);
            }

            $this->insertKeyVersion($calendarId, 1, $calendarKey, $recovery);
            $this->writeWrap($calendarId, 1, $ownerUserId, (int) $owner['key_version'], (string) $owner['public_key'], $calendarKey);
            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__appointments_calendars')
                . ' SET ' . $db->quoteName('key_version') . ' = 1, ' . $db->quoteName('rotation_due') . ' = 0'
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $calendarId
            )->execute();
            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $this->rollback();

            throw $e instanceof TeamException ? $e : new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_OPERATION', 500);
        } finally {
            CipherSuite::wipe($calendarKey);
            unset($this->calendarKeyRows[$calendarId . ':1']);
        }

        $this->anchorAfterCommit(EpochAnchorInterface::KIND_CALENDAR_KEY, $calendarId);
    }

    /**
     * Also carries out a due rotation (`rotation_due`): the next key holder's
     * request creates the next version, so the returned keyring may be newer
     * than the stored one was.
     */
    public function openKeyring(int $calendarId, KeyContext $keys): ?CalendarKeyring
    {
        $keyring = $this->openWithoutRotation($calendarId, $keys);

        if ($keyring === null || !$this->rotationDue($calendarId)) {
            return $keyring;
        }

        try {
            $rotated = $this->rotate($keyring, $keys->userId());
        } catch (TeamException $e) {
            if ($e->getMessage() !== 'COM_APPOINTMENTS_TEAM_B_ERROR_KEY_CHANGED'
                && $this->currentVersion($calendarId, true) === $keyring->current()->version()) {
                // Postponed (no holder can be judged now, no recovery key) and,
                // read under a lock, still the current version.
                $this->warn('a due key rotation was postponed', $e);

                return $keyring;
            }

            // Another request rotated first: never hand out the older version as
            // current; open again, with the new version if this user holds it.
            $keyring->wipe();

            return $this->openWithoutRotation($calendarId, $keys);
        }

        if ($rotated === null) {
            // The rotation went through, but this user is no holder any more
            // (revoked while waiting for the lock): no key for this request.
            $keyring->wipe();
        }

        return $rotated;
    }

    private function rotationDue(int $calendarId): bool
    {
        return (bool) ($this->calendarState($calendarId)['rotation_due'] ?? false);
    }

    private function openWithoutRotation(int $calendarId, KeyContext $keys): ?CalendarKeyring
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

        return new CalendarKeyring($calendarId, $calendar['key_version'], array_values($calendarKeys));
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
     *
     * Writing happens under a lock of the calendar row and with the holders
     * determined again inside it, so a revocation that runs at the same time
     * (it locks the same row) never receives a wrap.
     */
    public function completePendingWraps(CalendarKeyring $keyring): int
    {
        $calendarId = $keyring->calendarId();
        $current = $keyring->current()->version();
        $written = 0;

        // A cheap look without a lock first: most requests have nothing to complete.
        if ($this->missingWraps($calendarId, $keyring, $this->keyHolders($calendarId, false) ?? []) !== []) {
            $db = $this->services->db();
            $db->transactionStart(true);

            try {
                $this->lockCalendar($calendarId);

                foreach ($this->missingWraps($calendarId, $keyring, $this->keyHolders($calendarId, false, true) ?? [], true) as $userId => $missing) {
                    foreach ($missing['versions'] as $version) {
                        $this->writeWrap($calendarId, $version, $userId, $missing['key_version'], $missing['public_key'], $keyring->version($version)->key());
                        $written++;
                    }

                    $this->log(AuditEvent::GRANT_KEY_COMPLETED, ['subject_user_id' => $userId, 'calendar_id' => $calendarId]);
                }

                $db->transactionCommit(true);
            } catch (\Throwable $e) {
                $this->rollback();
                $this->warn('pending wraps could not be completed', $e);
                $written = 0;
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

    /**
     * A version rotated by another request in the meantime is a conflict
     * (409): the caller must open the calendar again rather than go on with
     * an older current key.
     */
    public function rotateCalendarKey(CalendarKeyring $keyring): CalendarKeyring
    {
        $rotated = $this->rotate($keyring, null);

        if ($rotated === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_CHANGED', 409);
        }

        return $rotated;
    }

    /**
     * @param   int|null  $requesterUserId  The user whose request rotates; when they are no
     *                                      holder any more under the lock, the rotation still
     *                                      happens but no keyring is returned.
     */
    private function rotate(CalendarKeyring $keyring, ?int $requesterUserId): ?CalendarKeyring
    {
        $calendarId = $keyring->calendarId();

        $db = $this->services->db();
        $newKey = CipherSuite::randomKey();
        $db->transactionStart(true);

        try {
            $this->lockCalendar($calendarId);
            $recovery = $this->recoveryRow(true);

            if ($recovery === null) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_NO_RECOVERY_KEY', 409);
            }

            $current = $this->currentVersion($calendarId, true);

            if ($current === null || $current !== $keyring->current()->version()) {
                // Somebody rotated in the meantime: a conflict, never the old ring.
                throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_CHANGED', 409);
            }

            // A rotation is a change of who holds the key: the grant epoch rises
            // (spec 5.11) and the grant service re-signs the verified grants, so
            // no grant row from before can be played back. Without that service
            // (work package C) there is no rotation.
            if (!method_exists(CalendarService::class, 'bumpEpoch')) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_OPERATION', 503);
            }

            $epoch = (int) CalendarService::bumpEpoch($this->services, $calendarId);

            // Every remaining holder must be known before a version nobody else
            // can open is created; an account that cannot be judged now, or no
            // holder at all, postpones the rotation. Determined under the lock, so
            // a revocation running at the same time is either fully before or after.
            $holders = $this->keyHolders($calendarId, true, true);

            if ($holders === null || $holders === []) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_OPERATION', 503);
            }

            $version = $current + 1;
            $this->insertKeyVersion($calendarId, $version, $newKey, $recovery);

            foreach ($holders as $holder) {
                $this->writeWrap($calendarId, $version, $holder['user_id'], $holder['key_version'], $holder['public_key'], $newKey);
            }

            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__appointments_calendars')
                . ' SET ' . $db->quoteName('key_version') . ' = ' . $version . ', ' . $db->quoteName('rotation_due') . ' = 0'
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $calendarId
            )->execute();
            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $this->rollback();
            CipherSuite::wipe($newKey);

            throw $e instanceof TeamException ? $e : new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_OPERATION', 500);
        }

        // Outside the database only once the change is committed, also when this
        // ran inside somebody else's transaction; a failing anchor never undoes
        // the rotation (the system check reports a missing anchor).
        $this->anchorAfterCommit(EpochAnchorInterface::KIND_CALENDAR, $calendarId);
        $this->anchorAfterCommit(EpochAnchorInterface::KIND_CALENDAR_KEY, $calendarId);
        $this->log(AuditEvent::CALENDAR_KEY_ROTATED, ['calendar_id' => $calendarId, 'details' => ['version' => $version]]);

        // The new key goes back only to a requester who is a holder under the
        // lock, so a request that lost its grant while it waited gets nothing.
        if ($requesterUserId !== null && !isset($holders[$requesterUserId])) {
            CipherSuite::wipe($newKey);

            return null;
        }

        $keys = [CalendarKey::create($calendarId, $version, $newKey)];
        CipherSuite::wipe($newKey);

        for ($old = 1; $old < $version; $old++) {
            if ($keyring->version($old) !== null) {
                $keys[] = $keyring->version($old);
            }
        }

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

        $macKey = $this->serverKey(KeyProvider::PURPOSE_TEAM_ENVELOPE);
        $envelope = self::sealEnvelope($calendarId, $uri, $ics, $macKey);
        CipherSuite::wipe($macKey);

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

        if ($plain === null) {
            return null;
        }

        $macKey = $this->serverKey(KeyProvider::PURPOSE_TEAM_ENVELOPE);
        $ics = self::openEnvelope($plain, $object->calendarId, $object->uri, $macKey);
        CipherSuite::wipe($macKey);

        return $ics;
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
     * @return  array{status: string, version: int, public_key: string, salt: string, opslimit: int, memlimit: int, device_epoch: int}
     */
    public function userKeyStatus(int $userId): array
    {
        $raw = $this->rawUserKeyRow($userId);

        if ($raw === null) {
            return ['status' => 'none', 'version' => 0, 'public_key' => '', 'salt' => '', 'opslimit' => 0, 'memlimit' => 0, 'device_epoch' => 0];
        }

        $status = 'usable';

        if (!$this->verifyStoredRow('user_keys', $raw)) {
            $this->macFailure('user_keys', 'mismatch', ['subject_user_id' => $userId]);
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
            'device_epoch' => (int) $raw['device_epoch'],
        ];
    }

    /**
     * The password key of specification 5.2 (the caller wipes it).
     */
    public function passwordKek(#[\SensitiveParameter] string $password, string $salt, int $opslimit, int $memlimit): string
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
    public function unlockWithKek(int $userId, #[\SensitiveParameter] string $kek): ?KeyContext
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
     * row in state `reset`; a usable or tampered row is refused with 409. In
     * the same transaction every stale wrap of the user goes and the user's
     * grants are bound to the new key version (`GrantService::rebindUserKey()`),
     * so grants given while the user had no usable pair stay valid.
     */
    public function createUserKeyWithKek(int $userId, #[\SensitiveParameter] string $kek, string $salt, int $opslimit, int $memlimit): KeyContext
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('A key pair belongs to a user.');
        }

        $db = $this->services->db();
        $pair = CipherSuite::keyPair();
        $db->transactionStart(true);

        try {
            $query = $this->query()
                ->select($db->quoteName(['user_id', 'key_version', 'public_key', 'password_wrapped', 'password_salt', 'pwhash_opslimit', 'pwhash_memlimit', 'device_epoch', 'row_mac']))
                ->from($db->quoteName('#__appointments_user_keys'))
                ->where($db->quoteName('user_id') . ' = ' . $userId);
            $stored = $db->setQuery((string) $query . ' FOR UPDATE')->loadAssoc() ?: null;

            if ($stored !== null && (!$this->verifyStoredRow('user_keys', $stored)
                || ($stored['password_wrapped'] !== null && CipherSuite::acceptableLimits((int) $stored['pwhash_opslimit'], (int) $stored['pwhash_memlimit'])))) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_EXISTS', 409);
            }

            $previous = $stored === null ? 0 : (int) $stored['key_version'];
            // Above every version a wrap of this user still names, so a stale wrap
            // can never pass for one of the new pair.
            $highestWrap = (int) $db->setQuery(
                $this->query()->select('MAX(' . $db->quoteName('user_key_version') . ')')
                    ->from($db->quoteName('#__appointments_calendar_key_wraps'))
                    ->where($db->quoteName('user_id') . ' = ' . $userId)
            )->loadResult();
            $version = max($previous, $highestWrap) + 1;
            $fields = [
                'user_id'          => $userId,
                'key_version'      => $version,
                'public_key'       => $pair['public'],
                'password_wrapped' => CipherSuite::encrypt($kek, $pair['secret'], self::passwordAd($userId, $version)),
                'password_salt'    => $salt,
                'pwhash_opslimit'  => $opslimit,
                'pwhash_memlimit'  => $memlimit,
                // Never below what the anchor knows: a row deleted in the database
                // must not come back with an epoch that would refuse it (and every
                // device of the user) for good.
                'device_epoch'     => $stored === null ? max(1, $this->anchorMinimum(EpochAnchorInterface::KIND_USER, $userId)) : (int) $stored['device_epoch'],
            ];
            $mac = $this->rowMac('user_keys', $fields);
            $now = $this->nowSql();

            if ($stored === null) {
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
                )->execute();
            }

            $this->deleteAllWrapsOfUser($userId);
            $this->rebindGrants($userId, $previous);
            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $this->rollback();
            CipherSuite::wipe($pair['secret']);

            throw $e instanceof TeamException ? $e : new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEY_OPERATION', 500);
        }

        $context = KeyContext::create($userId, $version, $pair['secret'], $pair['public']);
        CipherSuite::wipe($pair['secret']);
        $this->log(AuditEvent::USER_KEY_CREATED, ['subject_user_id' => $userId, 'details' => ['version' => $version]]);

        return $context;
    }

    /**
     * A MAC mismatch found by the key session, logged after its transaction
     * was rolled back (a log row written inside it would be rolled back too).
     *
     * @param   array<string, int>  $context
     */
    public function reportMacFailure(string $table, array $context): void
    {
        $this->macFailure($table, 'mismatch', $context);
    }

    /**
     * Whether the site secret looks changed: a recovery key row exists and its
     * MAC fails. Then every row fails, and nothing may be suspended or rewritten
     * under the new secret, so the old one can still be restored.
     */
    public function siteSecretSuspect(): bool
    {
        $row = $this->rawRecoveryRow();

        return $row !== null && !$this->verifyStoredRow('keyring', $row);
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
    public static function formatRecoverySecret(#[\SensitiveParameter] string $secret): string
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
    public static function parseRecoverySecret(#[\SensitiveParameter] string $input): ?string
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
     * The scheme-2 envelope: `"ACv1\n" . json_encode(['c' => calendarId, 'u' => uri, 'm' => mac]) . "\n" . ics`.
     * Anybody can seal to the public sealing key, so the envelope carries
     * `m = base64(HMAC-SHA256(key(TEAM_ENVELOPE), associatedData('envelope', calendarId, uri, ics)))`:
     * only the server (the booking flow) can write an envelope that opens, and
     * an object planted by somebody who can write the database alone is never
     * read, nor re-encrypted into scheme 1.
     */
    public static function sealEnvelope(int $calendarId, string $uri, string $ics, #[\SensitiveParameter] string $macKey): string
    {
        $mac = hash_hmac('sha256', CipherSuite::associatedData('envelope', $calendarId, $uri, $ics), $macKey, true);

        return self::ENVELOPE . json_encode(['c' => $calendarId, 'u' => $uri, 'm' => base64_encode($mac)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n" . $ics;
    }

    /**
     * The iCalendar text of an opened scheme-2 envelope, null when it was
     * written for another calendar or URI or does not carry the server's MAC.
     */
    public static function openEnvelope(string $plain, int $calendarId, string $uri, #[\SensitiveParameter] string $macKey): ?string
    {
        if (!str_starts_with($plain, self::ENVELOPE)) {
            return null;
        }

        $end = strpos($plain, "\n", \strlen(self::ENVELOPE));

        if ($end === false) {
            return null;
        }

        $meta = json_decode(substr($plain, \strlen(self::ENVELOPE), $end - \strlen(self::ENVELOPE)), true);

        if (!\is_array($meta) || ($meta['c'] ?? null) !== $calendarId || ($meta['u'] ?? null) !== $uri || !\is_string($meta['m'] ?? null)) {
            return null;
        }

        $ics = substr($plain, $end + 1);
        $expected = hash_hmac('sha256', CipherSuite::associatedData('envelope', $calendarId, $uri, $ics), $macKey, true);

        return hash_equals($expected, (string) base64_decode($meta['m'], true)) ? $ics : null;
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

    /**
     * Roll back to the savepoint of this service. After a deadlock InnoDB has
     * already rolled back the whole transaction, and the rollback to a savepoint
     * that no longer exists throws; that must not hide the original error.
     */
    private function rollback(): void
    {
        try {
            $this->services->db()->transactionRollback(true);
        } catch (\Throwable $e) {
            $this->warn('a rollback failed', $e);
        }
    }

    private function anchorAfterCommit(string $kind, int $id): void
    {
        try {
            $this->services->anchor()->recordAfterCommit($kind, $id);
        } catch (\Throwable $e) {
            $this->warn('an epoch could not be queued for the anchor', $e);
        }
    }

    private function contextFrom(int $userId, int $version, #[\SensitiveParameter] ?string $secret, string $publicKey): ?KeyContext
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

        if (!$this->verifyRowMac($table, $fields, (string) ($row['row_mac'] ?? ''))) {
            return false;
        }

        // A user key row played back together with an older device epoch is
        // refused once the anchor outside the database knows a newer one.
        return self::canonicalTable($table) !== 'appointments_user_keys'
            || $this->anchorAccepts(EpochAnchorInterface::KIND_USER, (int) $row['user_id'], (int) $row['device_epoch']);
    }

    private function anchorMinimum(string $kind, int $id): int
    {
        try {
            return $this->services->anchor()->minimum($kind, $id);
        } catch (\Throwable $e) {
            $this->warn('the epoch anchor could not be read', $e);

            return 0;
        }
    }

    private function anchorAccepts(string $kind, int $id, int $epoch): bool
    {
        try {
            return $this->services->anchor()->accepts($kind, $id, $epoch);
        } catch (\Throwable $e) {
            // An anchor that exists but fails is no reason to trust a row (as in
            // the grant service); only a missing writable folder turns it off.
            $this->warn('the epoch anchor could not be read', $e);

            return false;
        }
    }

    /**
     * Grants of the user, MAC-bound to the previous key version, are bound to
     * the current one (work package C). Without it nothing is rebound and the
     * grants stay ineffective until somebody grants again.
     */
    private function rebindGrants(int $userId, int $previousKeyVersion): void
    {
        if (method_exists(GrantService::class, 'rebindUserKey')) {
            GrantService::rebindUserKey($this->services, $userId, $previousKeyVersion);
        }
    }

    /**
     * @return  array<string, mixed>|null
     */
    private function rawRecoveryRow(bool $forUpdate = false): ?array
    {
        $db = $this->services->db();
        $query = $this->query()
            ->select($db->quoteName(['purpose', 'public_key', 'fingerprint', 'row_mac', 'created_by', 'created_utc']))
            ->from($db->quoteName('#__appointments_keyring'))
            ->where($db->quoteName('purpose') . ' = ' . $db->quote(self::RECOVERY));

        return $db->setQuery((string) $query . ($forUpdate ? ' FOR UPDATE' : ''))->loadAssoc() ?: null;
    }

    /**
     * @param   bool  $forUpdate  Lock the row (inside a transaction) against a concurrent rotation.
     *
     * @return  array<string, mixed>|null  The recovery row after its MAC is verified.
     */
    private function recoveryRow(bool $forUpdate = false): ?array
    {
        $row = $this->rawRecoveryRow($forUpdate);

        if ($row === null) {
            return null;
        }

        if (!$this->verifyStoredRow('keyring', $row) || \strlen((string) $row['public_key']) !== CipherSuite::KEY_BYTES) {
            $this->macFailure('keyring', 'mismatch', []);

            return null;
        }

        return $row;
    }

    /**
     * @return  array<string, mixed>|null
     */
    private function rawUserKeyRow(int $userId, bool $locking = false): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $db = $this->services->db();
        $query = $this->query()
            ->select($db->quoteName(['user_id', 'key_version', 'public_key', 'password_wrapped', 'password_salt', 'pwhash_opslimit', 'pwhash_memlimit', 'device_epoch', 'row_mac']))
            ->from($db->quoteName('#__appointments_user_keys'))
            ->where($db->quoteName('user_id') . ' = ' . $userId);

        return $db->setQuery((string) $query . ($locking ? ' FOR UPDATE' : ''))->loadAssoc() ?: null;
    }

    /**
     * @return  array<string, mixed>|null  A verified row with a usable wrapped secret.
     */
    private function usableUserKey(int $userId, bool $locking = false): ?array
    {
        $row = $this->rawUserKeyRow($userId, $locking);

        if ($row === null) {
            return null;
        }

        if (!$this->verifyStoredRow('user_keys', $row) || \strlen((string) $row['public_key']) !== CipherSuite::KEY_BYTES) {
            $this->macFailure('user_keys', 'mismatch', ['subject_user_id' => $userId]);

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
        $current = $row ? $this->currentVersion($calendarId) : null;

        if ($current === null) {
            return null;
        }

        // `calendars.key_version` carries no MAC; the newest verified key row
        // decides, so a version set back in the database is not followed.
        if ($current !== (int) $row['key_version']) {
            $this->macFailure('calendars', 'version', ['calendar_id' => $calendarId]);
        }

        return ['key_version' => $current, 'rotation_due' => (int) $row['rotation_due'] === 1];
    }

    /**
     * The newest key version of a calendar whose `calendar_keys` row verifies,
     * null when there is none.
     */
    /**
     * @param   bool  $locking  Locking reads that bypass the row cache: the newest
     *                          committed state also inside an older transaction snapshot.
     */
    private function currentVersion(int $calendarId, bool $locking = false): ?int
    {
        $db = $this->services->db();
        $lock = $locking ? ' FOR UPDATE' : '';
        $versionQuery = $this->query()
            ->select($db->quoteName('key_version'))
            ->from($db->quoteName('#__appointments_calendar_keys'))
            ->where($db->quoteName('calendar_id') . ' = ' . $calendarId)
            ->order($db->quoteName('key_version') . ' DESC');
        $versions = $db->setQuery((string) $versionQuery . $lock)->loadColumn() ?: [];

        // Only the newest row counts. If it fails its MAC the calendar fails
        // closed instead of silently falling back to an older version, which a
        // database writer could otherwise force by damaging or deleting rows.
        $newest = $versions === [] ? null : (int) $versions[0];

        if ($newest === null || $this->calendarKeyRow($calendarId, $newest, $locking) === null) {
            return null;
        }

        $storedQuery = $this->query()->select($db->quoteName('key_version'))->from($db->quoteName('#__appointments_calendars'))
            ->where($db->quoteName('id') . ' = ' . $calendarId);
        $stored = (int) $db->setQuery((string) $storedQuery . $lock)->loadResult();

        // A newest row below the version the calendar row names, or below the
        // version anchored outside the database, means key rows were removed
        // (and the column lowered): fail closed as well.
        if ($newest < $stored || !$this->anchorAccepts(EpochAnchorInterface::KIND_CALENDAR_KEY, $calendarId, $newest)) {
            $this->macFailure('calendar_keys', 'missing', ['calendar_id' => $calendarId]);

            return null;
        }

        return $newest;
    }

    private function lockCalendar(int $calendarId): void
    {
        $db = $this->services->db();
        $query = $this->query()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__appointments_calendars'))
            ->where($db->quoteName('id') . ' = ' . $calendarId);
        $db->setQuery((string) $query . ' FOR UPDATE')->loadResult();
    }

    /**
     * The versions of a keyring each holder still lacks a wrap of.
     *
     * @param   array<int, array{user_id: int, key_version: int, public_key: string}>  $holders
     *
     * @return  array<int, array{key_version: int, public_key: string, versions: int[]}>
     */
    private function missingWraps(int $calendarId, CalendarKeyring $keyring, array $holders, bool $locking = false): array
    {
        $missing = [];

        foreach ($holders as $holder) {
            $have = $this->wrappedVersions($calendarId, $holder['user_id'], $holder['key_version'], $locking);
            $versions = [];

            for ($version = 1; $version <= $keyring->current()->version(); $version++) {
                if ($keyring->version($version) !== null && !isset($have[$version])) {
                    $versions[] = $version;
                }
            }

            if ($versions !== []) {
                $missing[$holder['user_id']] = ['key_version' => $holder['key_version'], 'public_key' => $holder['public_key'], 'versions' => $versions];
            }
        }

        return $missing;
    }

    /**
     * @return  array<string, mixed>|null  The verified `calendar_keys` row.
     */
    private function calendarKeyRow(int $calendarId, int $version, bool $locking = false): ?array
    {
        $cacheKey = $calendarId . ':' . $version;

        if ($locking || !\array_key_exists($cacheKey, $this->calendarKeyRows)) {
            $db = $this->services->db();
            $query = $this->query()
                ->select($db->quoteName(['calendar_id', 'key_version', 'box_public_key', 'box_secret_wrapped', 'recovery_wrapped', 'recovery_fingerprint', 'row_mac']))
                ->from($db->quoteName('#__appointments_calendar_keys'))
                ->where($db->quoteName('calendar_id') . ' = ' . $calendarId)
                ->where($db->quoteName('key_version') . ' = ' . $version);
            $row = $db->setQuery((string) $query . ($locking ? ' FOR UPDATE' : ''))->loadAssoc();

            if ($row && (!$this->verifyStoredRow('calendar_keys', $row) || \strlen((string) $row['box_public_key']) !== CipherSuite::KEY_BYTES)) {
                $this->macFailure('calendar_keys', 'mismatch', ['calendar_id' => $calendarId]);
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
    private function boxSecret(int $calendarId, int $version, #[\SensitiveParameter] string $calendarKey, array $keyRow): ?string
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
    private function opensBox(int $calendarId, int $version, #[\SensitiveParameter] string $calendarKey, array $keyRow): bool
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
            $this->macFailure('calendar_key_wraps', 'foreign_key', ['calendar_id' => $calendarId, 'subject_user_id' => $keys->userId()]);

            return null;
        }

        $key = CalendarKey::create($calendarId, $version, $calendarKey);
        CipherSuite::wipe($calendarKey);

        return $key;
    }

    /**
     * @param   array<string, mixed>  $recovery  The verified recovery row.
     */
    private function insertKeyVersion(int $calendarId, int $version, #[\SensitiveParameter] string $calendarKey, array $recovery): void
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

    private function writeWrap(int $calendarId, int $version, int $userId, int $userKeyVersion, string $publicKey, #[\SensitiveParameter] string $calendarKey): void
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
    private function wrappedVersions(int $calendarId, int $userId, int $userKeyVersion, bool $locking = false): array
    {
        $db = $this->services->db();
        $query = $this->query()
            ->select($db->quoteName('key_version'))
            ->from($db->quoteName('#__appointments_calendar_key_wraps'))
            ->where($db->quoteName('calendar_id') . ' = ' . $calendarId)
            ->where($db->quoteName('user_id') . ' = ' . $userId)
            ->where($db->quoteName('user_key_version') . ' = ' . $userKeyVersion);
        $versions = $db->setQuery((string) $query . ($locking ? ' FOR UPDATE' : ''))->loadColumn() ?: [];

        return array_fill_keys(array_map('intval', $versions), true);
    }

    /**
     * Grantees that hold a key: grant valid (MAC under the calendar's current
     * grant epoch and the grantee's current key version, checked by the grant
     * service), state active or waiting for its key (never suspended), a
     * key-holding role, an effective account and a usable key pair.
     *
     * With `$locking` every row is read with FOR UPDATE: a locking read always
     * sees the newest committed state, also inside an outer transaction whose
     * snapshot is older, so a revocation committed while this request waited
     * for the calendar lock is never missed.
     *
     * With `$strict` (a rotation, which must not leave a holder behind) the
     * answer is null as soon as one account cannot be judged, for example
     * because the grant service fails; without, such an account is skipped and
     * the next completion picks it up.
     *
     * @return  array<int, array{user_id: int, key_version: int, public_key: string}>|null
     */
    private function keyHolders(int $calendarId, bool $strict, bool $locking = false): ?array
    {
        if (!method_exists(GrantService::class, 'rowValid') || !method_exists(CalendarService::class, 'rowValid')) {
            return $strict ? null : [];
        }

        $db = $this->services->db();
        $lock = $locking ? ' FOR UPDATE' : '';
        $calendarQuery = $this->query()
            ->select('*')
            ->from($db->quoteName('#__appointments_calendars'))
            ->where($db->quoteName('id') . ' = ' . $calendarId);
        $calendar = $db->setQuery((string) $calendarQuery . $lock)->loadObject();

        // The calendar row first (its MAC and its grant epoch against the
        // anchor): a grant is only as fresh as the epoch it is checked against.
        try {
            $calendarValid = $calendar !== null && CalendarService::rowValid($this->services, $calendar);
        } catch (\Throwable $e) {
            return $strict ? null : [];
        }

        if (!$calendarValid) {
            $this->macFailure('calendars', 'mismatch', ['calendar_id' => $calendarId]);

            return $strict ? null : [];
        }

        $epoch = (int) $calendar->grant_epoch;
        // The grant table holds no secret; every column is read so that the
        // grant service alone decides which ones its MAC covers.
        $grantQuery = $this->query()
            ->select('*')
            ->from($db->quoteName('#__appointments_calendar_grants'))
            ->where($db->quoteName('calendar_id') . ' = ' . $calendarId)
            ->where($db->quoteName('state') . ' IN (' . TeamStates::GRANT_ACTIVE . ', ' . TeamStates::GRANT_PENDING_KEY . ')');
        $rows = $db->setQuery((string) $grantQuery . $lock)->loadObjectList() ?: [];
        $holders = [];

        foreach ($rows as $row) {
            $role = CalendarRole::tryFrom((int) $row->role);
            $userId = (int) $row->user_id;

            if ($role === null || !$role->holdsKey()) {
                continue;
            }

            $key = $this->usableUserKey($userId, $locking);

            if ($key === null) {
                continue;
            }

            try {
                $valid = GrantService::rowValid($this->services, $row, $epoch, (int) $key['key_version']);
            } catch (\Throwable $e) {
                $valid = null;
            }

            if ($valid === null && $strict) {
                return null;
            }

            if ($valid !== true) {
                if ($valid === false) {
                    $this->macFailure('calendar_grants', 'mismatch', ['calendar_id' => $calendarId, 'subject_user_id' => $userId]);
                }

                continue;
            }

            $effective = $this->accountEffective($userId, $locking);

            if ($effective === null && $strict) {
                return null;
            }

            if ($effective === true) {
                $holders[$userId] = ['user_id' => $userId, 'key_version' => (int) $key['key_version'], 'public_key' => (string) $key['public_key']];
            }
        }

        return $holders;
    }
    /**
     * @return  bool|null  Null when it cannot be judged now.
     */
    private function accountEffective(int $userId, bool $locking = false): ?bool
    {
        try {
            $db = $this->services->db();
            $query = $this->query()->select($db->quoteName('block'))->from($db->quoteName('#__users'))
                ->where($db->quoteName('id') . ' = ' . $userId);
            // A shared lock: current like the other reads under the calendar
            // lock, without blocking sign-ins that update the account row.
            $block = $db->setQuery((string) $query . ($locking ? ' LOCK IN SHARE MODE' : ''))->loadResult();

            return $block !== null && (int) $block === 0
                && $this->services->grants()->audienceOf($userId) !== 'none'
                && $this->services->grants()->hasSecondFactor($userId);
        } catch (\Throwable $e) {
            return null;
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
                . ' OR ' . $db->quoteName('key_version') . ' < ' . $currentVersion . ')')
            ->setLimit(1);

        return (bool) $db->setQuery($query)->loadResult();
    }

    /**
     * @param   string              $reason   `mismatch` (MAC) or `foreign_key` (a wrap that does not open its key row).
     * @param   array<string, int>  $context
     */
    private function macFailure(string $table, string $reason, array $context): void
    {
        $this->log(AuditEvent::ROW_MAC_FAILURE, $context + ['details' => ['table' => $table, 'reason' => $reason]]);
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
