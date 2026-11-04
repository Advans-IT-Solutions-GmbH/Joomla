<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;

defined('_JEXEC') or die;

/**
 * The personal fields of a booking at rest (team-calendars.md, section 5.10).
 *
 * Every value of {@see self::COLUMNS} is stored as `v1:` plus Base64 of
 * `CipherSuite::encrypt(key(PURPOSE_BOOKING_DATA), value, associatedData('booking', uid, created_utc, column))`.
 * UID and creation time exist before the row is inserted and never change, so a
 * value can be moved neither to another booking nor to another column, and
 * copying UID and personal fields together into another row fails unless the
 * creation time is copied too (which makes it that booking).
 *
 * This class is the only code that turns these columns into text and back.
 * Code that reads a booking row calls {@see self::open()} right after the read,
 * code that writes calls {@see self::seal()} right before the write, with the
 * clear values it writes. Both accept a whole row: other keys pass through
 * unchanged, a null value stays null.
 *
 * An opened row carries the key {@see self::OPENED}, so opening it again
 * changes nothing (code that receives either a stored or an opened row opens
 * it), and sealing removes the key again before the write. A stored value that
 * is not sealed is clear text of a row written before 1.4.0: it is accepted
 * only while the migration of the update to 1.4.0 runs, and only in a row
 * created before that update (`$clearBefore`). The update writes a migration
 * token into the component option {@see self::MIGRATION_OPTION}: the update
 * time and an expiry of at most {@see self::MIGRATION_DAYS} days, keyed with
 * the site secret. The migration renews the expiry while old rows are left and
 * deletes the token when none is (also a token an Options form wrote back).
 * Without a valid token (a new installation, a finished migration, a token
 * deleted or forged by a database write), and in any row created later, a
 * clear value counts as one that does not open: an address put in as clear
 * text by a database write alone neither receives mail nor answers a privacy
 * request, and the migration does not encrypt it either.
 *
 * A value that does not open (another site secret, a changed byte) throws in
 * {@see self::open()}; {@see self::openLenient()} blanks it instead and reports
 * the column, for pages, mails, reminders and exports.
 *
 * Pure: no database and, except {@see self::fromApplication()}, no Joomla
 * runtime, so it is tested on its own.
 *
 * @since  1.4.0
 */
final class BookingData
{
    /** The encrypted columns of `#__appointments_bookings`. */
    public const COLUMNS = ['invitee_name', 'invitee_first_name', 'invitee_last_name', 'invitee_email', 'invitee_phone',
        'guest_emails', 'custom_fields', 'cancel_reason', 'conference_url'];

    /** The key an opened row carries. */
    public const OPENED = '_opened';

    /** The component option that holds the migration token while clear rows are encrypted. */
    public const MIGRATION_OPTION = 'booking_data_migration';

    /** How long a migration token is valid; the migration renews it while old rows are left. */
    public const MIGRATION_DAYS = 7;

    private const PREFIX = 'v1:';

    /** `v1:` and Base64 of at least nonce and tag (40 bytes). */
    private const SEALED = '/^v1:[A-Za-z0-9+\/]{54,}={0,2}$/D';

    /**
     * @var  string
     */
    private $key;

    /**
     * @param   int|null  $clearBefore  Unix time: a stored clear value is read as it is in a row created before it (only while the migration runs).
     */
    public function __construct(KeyProvider $keys, private readonly ?int $clearBefore = null)
    {
        $this->key = $keys->key(KeyProvider::PURPOSE_BOOKING_DATA);
    }

    /**
     * The installation's instance, for code that runs inside Joomla: clear
     * values are accepted only with a valid migration token.
     */
    public static function fromApplication(): self
    {
        $keys = new KeyProvider((string) \Joomla\CMS\Factory::getApplication()->get('secret', ''));
        $token = (string) \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments')->get(self::MIGRATION_OPTION, '');

        return self::forToken($keys, $token);
    }

    /** An instance that reads clear values of old rows only while `$token` is a valid migration token. */
    public static function forToken(KeyProvider $keys, string $token): self
    {
        return new self($keys, self::migrationCutoff($token, $keys, time()));
    }

    /** A migration token for rows created before `$cutoff`, valid until `$expiresAt` (Unix seconds). */
    public static function migrationToken(KeyProvider $keys, int $expiresAt, int $cutoff): string
    {
        return $expiresAt . '.' . $cutoff . '.' . hash_hmac('sha256', 'com_appointments/booking-migration/v2|' . $expiresAt . '|' . $cutoff,
            $keys->key(KeyProvider::PURPOSE_BOOKING_DATA));
    }

    /**
     * The update time of a token made with this site's key that has not expired,
     * null for any other value.
     */
    public static function migrationCutoff(string $token, KeyProvider $keys, int $now): ?int
    {
        if (!preg_match('/^(\d{1,12})\.(\d{1,12})\.[0-9a-f]{64}$/D', $token, $match) || (int) $match[1] <= $now
            || (int) $match[1] > $now + self::MIGRATION_DAYS * 86400 || (int) $match[2] > $now) {
            return null;
        }

        return hash_equals(self::migrationToken($keys, (int) $match[1], (int) $match[2]), $token) ? (int) $match[2] : null;
    }

    /** Whether a stored value has the form of a sealed value. */
    public static function isSealed(mixed $value): bool
    {
        return \is_string($value) && preg_match(self::SEALED, $value) === 1;
    }

    /**
     * The row with every personal field present sealed, also a value that only
     * looks sealed (input is never trusted to be ciphertext); an array (answers)
     * is stored as its JSON text. Pass the clear values that are written, never
     * stored values that were not opened.
     *
     * @param   array<string, mixed>  $row
     *
     * @return  array<string, mixed>
     *
     * @throws  \InvalidArgumentException  Without a UID, or for a value that is no text.
     */
    public function seal(string $uid, string $created, array $row): array
    {
        if ($uid === '' || $created === '') {
            throw new \InvalidArgumentException('A booking without UID or creation time cannot be sealed.');
        }

        unset($row[self::OPENED]);

        foreach (self::COLUMNS as $column) {
            if (!\array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            $value = $row[$column];

            if (\is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }

            if (!\is_scalar($value)) {
                throw new \InvalidArgumentException('A booking field must be text.');
            }

            $row[$column] = self::PREFIX . base64_encode(CipherSuite::encrypt($this->key, (string) $value, self::data($uid, $created, $column)));
        }

        return $row;
    }

    /**
     * The row with every personal field in clear text.
     *
     * @param   array<string, mixed>  $row  A booking row with its `uid` and `created_utc`.
     *
     * @return  array<string, mixed>
     *
     * @throws  \UnexpectedValueException  When a value does not open; the message names the column, never a value.
     */
    public function open(array $row): array
    {
        $failed = [];
        $opened = $this->openLenient($row, $failed);

        if ($failed !== []) {
            throw new \UnexpectedValueException('A booking field could not be decrypted: ' . implode(', ', $failed) . '.');
        }

        return $opened;
    }

    /**
     * Like {@see self::open()}, but a value that does not open becomes null
     * and its column is added to `$failed`.
     *
     * @param   array<string, mixed>  $row
     * @param   string[]              $failed
     *
     * @return  array<string, mixed>
     */
    public function openLenient(array $row, ?array &$failed = null): array
    {
        $failed = [];

        if (!empty($row[self::OPENED])) {
            return $row;
        }

        $uid = (string) ($row['uid'] ?? '');
        $created = (string) ($row['created_utc'] ?? '');

        foreach (self::COLUMNS as $column) {
            if (!\array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            if (!self::isSealed($row[$column])) {
                if (!$this->clearAccepted($row)) {
                    $failed[] = $column;
                    $row[$column] = null;
                }

                continue;
            }

            $raw = $uid === '' || $created === '' ? false : base64_decode(substr($row[$column], \strlen(self::PREFIX)), true);
            $plain = $raw === false ? null : CipherSuite::decrypt($this->key, $raw, self::data($uid, $created, $column));

            if ($plain === null) {
                $failed[] = $column;
            }

            $row[$column] = $plain;
        }

        $row[self::OPENED] = true;

        return $row;
    }

    /**
     * {@see self::openLenient()} for an object row (Joomla's `loadObject()`), in
     * place, for pages: a field that cannot be decrypted is shown empty.
     *
     * @param   string[]  $failed
     */
    public function openObject(object $row, ?array &$failed = null): object
    {
        foreach ($this->openLenient((array) $row, $failed) as $key => $value) {
            $row->$key = $value;
        }

        return $row;
    }

    /** Whether a clear value of this row is read as it is: a row created before the update, while the migration runs. */
    private function clearAccepted(array $row): bool
    {
        if ($this->clearBefore === null) {
            return false;
        }

        $created = UtcTimestamp::parse((string) ($row['created_utc'] ?? ''));

        return $created !== null && $created->getTimestamp() < $this->clearBefore;
    }

    private static function data(string $uid, string $created, string $column): string
    {
        return CipherSuite::associatedData('booking', $uid, $created, $column);
    }
}
