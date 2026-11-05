<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKey;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\StoredObject;

defined('_JEXEC') or die;

/**
 * Key hierarchy and encryption of the team calendars (work package B).
 *
 * Specification: `.claude/skills/appointments/references/team-calendars.md`,
 * section "Encryption". In short: one symmetric key per calendar and version,
 * wrapped for every key-holding grant with the grantee's X25519 public key and
 * for the offline recovery key. A user's secret key is wrapped with a key
 * stretched from the Joomla password and, per device, with a key derived from
 * the app password. The server holds a calendar key only during a request that
 * authenticated a key holder. Every crypto call goes through {@see \Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite}.
 *
 * Every method that reads a row with a `row_mac` verifies it first and treats a
 * mismatch as "no key" (and logs `key.*` events through the audit service).
 *
 * @since  1.4.0
 */
interface KeyServiceInterface
{
    /**
     * Create the site recovery key pair. The public key is stored in
     * `#__appointments_keyring`, the secret is returned once, formatted for
     * printing, and never stored.
     *
     * @return  string  The printable recovery secret.
     *
     * @throws  \Advans\Component\Appointments\Administrator\Helper\Team\TeamException  409 when one exists already.
     */
    public function createRecoveryKey(int $actorUserId): string;

    /**
     * Replace the recovery key: every calendar key is unsealed with the old
     * secret and sealed to the new public key in one transaction.
     *
     * @return  string  The new printable recovery secret.
     */
    public function rotateRecoveryKey(#[\SensitiveParameter] string $oldRecoverySecret, int $actorUserId): string;

    /**
     * @return  string|null  Fingerprint of the stored recovery public key, null when none exists.
     */
    public function recoveryFingerprint(): ?string;

    public function hasUserKey(int $userId): bool;

    /**
     * The user's public key after its row MAC is verified, null when there is
     * none or the MAC does not match.
     */
    public function userPublicKey(int $userId): ?string;

    /**
     * First key pair of a user, wrapped with the password. Called at sign-in,
     * only after the second factor, only for users in a team or external group.
     */
    public function createUserKey(int $userId, #[\SensitiveParameter] string $password): KeyContext;

    /**
     * @return  KeyContext|null  Null when the user has no key or the password does not unwrap it.
     */
    public function unlockWithPassword(int $userId, #[\SensitiveParameter] string $password): ?KeyContext;

    /**
     * Wrap the unlocked key for a new password (password change in an unlocked session).
     */
    public function rewrapForPassword(KeyContext $keys, #[\SensitiveParameter] string $newPassword): void;

    /**
     * A password reset: a new key pair replaces the old one, every calendar key
     * wrap of the user is deleted, and the caller suspends the grants and puts the
     * devices into clean-up. The old secret key is unrecoverable by design.
     */
    public function resetUserKey(int $userId): void;

    /**
     * Discard a user key row that no longer verifies (wrong MAC), as a logged
     * administrator action (`core.admin`, backend of work package C). Deletes the
     * row and the user's wraps, suspends the grants and puts the devices into
     * clean-up; the next password sign-in creates a new key pair. Refused for a
     * row that still verifies, so it can never be used instead of a reset.
     */
    public function discardUserKey(int $actorUserId, int $userId): void;

    /**
     * @return  string  `usk_wrapped` for `#__appointments_devices`.
     */
    public function wrapForDevice(KeyContext $keys, #[\SensitiveParameter] string $appPasswordRaw, string $kekSalt): string;

    public function unlockWithDevice(int $userId, #[\SensitiveParameter] string $appPasswordRaw, string $kekSalt, string $uskWrapped): ?KeyContext;

    /**
     * Version 1 of a new calendar's keys: calendar key, sealing key pair, a wrap
     * for the owner (who must have a key pair) and the recovery wrap (a recovery
     * key must exist). Only public keys are needed, so no key context.
     *
     * @throws  \Advans\Component\Appointments\Administrator\Helper\Team\TeamException  409 without recovery key or owner key.
     */
    public function createCalendarKeys(int $calendarId, int $ownerUserId): void;

    /**
     * Every key version of the calendar the user holds a wrap for, null when none.
     */
    public function openKeyring(int $calendarId, KeyContext $keys): ?CalendarKeyring;

    /**
     * Seal one calendar key version to a user's public key.
     *
     * @return  bool  False when the user has no (valid) key pair yet; the grant then stays pending.
     */
    public function wrapForUser(CalendarKey $key, int $userId): bool;

    /**
     * Complete the wraps of every effective key-holding grant of this calendar
     * that has none for the current version. Called opportunistically by every
     * request that holds the key. Grants in state suspended are never completed.
     *
     * @return  int  Number of wraps written.
     */
    public function completePendingWraps(CalendarKeyring $keyring): int;

    public function deleteUserWraps(int $calendarId, int $userId): void;

    /**
     * A new key version after a key holder lost access: new calendar key and
     * sealing pair, wraps for every remaining holder and the recovery key. Old
     * versions stay until every object is re-encrypted.
     */
    public function rotateCalendarKey(CalendarKeyring $keyring): CalendarKeyring;

    /**
     * Unseal the calendar's keys with the recovery secret (never stored).
     */
    public function recoverKeyring(int $calendarId, #[\SensitiveParameter] string $recoverySecret): ?CalendarKeyring;

    /**
     * @return  string  `payload` for scheme 1 (calendar key).
     */
    public function encryptObject(CalendarKey $key, string $uri, string $ics): string;

    /**
     * Seal an object to the calendar's current sealing public key, for the
     * booking flow that holds no user key.
     *
     * @return  array{scheme: int, version: int, payload: string}
     */
    public function sealObject(int $calendarId, string $uri, string $ics): array;

    /**
     * @return  string|null  The iCalendar text, null when the keyring cannot open it.
     */
    public function decryptObject(CalendarKeyring $keyring, StoredObject $object): ?string;

    /**
     * Encrypt / decrypt the time skeleton with the server timing key.
     */
    public function sealTiming(int $calendarId, string $uri, string $skeleton): string;

    public function openTiming(int $calendarId, string $uri, string $timing): ?string;

    /**
     * Keyed hash of an iCalendar UID within one calendar (32 raw bytes).
     */
    public function uidHash(int $calendarId, string $uid): string;

    /**
     * Row MAC over the named fields of a row (32 raw bytes). `$table` is the
     * table name without prefix, so a MAC can never be valid for another table.
     *
     * @param   array<string, scalar|null>  $fields
     */
    public function rowMac(string $table, array $fields): string;

    /**
     * @param   array<string, scalar|null>  $fields
     */
    public function verifyRowMac(string $table, array $fields, string $mac): bool;
}
