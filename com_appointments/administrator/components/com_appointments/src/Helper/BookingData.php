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
 * is not sealed is clear text of a row written before 1.4.0: it is read only
 * in a row created before that update (`$clearBefore`) that holds no sealed
 * field. The update writes a migration token into the component option
 * {@see self::MIGRATION_OPTION}: the update time and an expiry of at most
 * {@see self::MIGRATION_DAYS} days, keyed with the site secret. The migration
 * renews the expiry while old rows are left and writes a keyed end marker when
 * none is. While the token is valid such a value is read as data; after the
 * window (a migration that did not finish) it stays readable but the row is
 * marked {@see self::UNVERIFIED}: an update never makes data unreadable, and
 * nothing but the administrator action encrypts it then. Without any token of
 * this site (a new installation, a finished migration, a forged value), and in
 * any row created later or holding a sealed field, a clear value counts as one
 * that does not open: an address put in as clear text by a database write
 * alone neither receives mail nor answers a privacy request.
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

    /** The key an opened row carries when its clear values (a row of 1.3) were read as they are. */
    public const CLEAR = '_clear';

    /** The key an opened row of 1.3 carries when it was read after the migration window: shown as «not verified». */
    public const UNVERIFIED = '_unverified';

    /** The component option that holds the migration token while clear rows are encrypted. */
    public const MIGRATION_OPTION = 'booking_data_migration';

    /** How long a migration token is valid; the migration renews it while old rows are left. */
    public const MIGRATION_DAYS = 7;

    /**
     * How long after the update a migration may run or resume at all: a token
     * (also one kept from a backup and written back later) is void after that.
     */
    public const MIGRATION_LIMIT_DAYS = 90;

    private const PREFIX = 'v1:';

    /** `v1:` and Base64 of at least nonce and tag (40 bytes). */
    private const SEALED = '/^v1:[A-Za-z0-9+\/]{54,}={0,2}$/D';

    /**
     * @var  string
     */
    private $key;

    /**
     * @param   int|null  $clearBefore  Unix time: a stored clear value is read as it is in a row created before it.
     * @param   bool      $verified     False after the migration window: such a row is marked {@see self::UNVERIFIED}.
     */
    public function __construct(KeyProvider $keys, private readonly ?int $clearBefore = null, private readonly bool $verified = true)
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

    /**
     * An instance for the migration state `$token`: clear values of old rows are
     * data while it is a valid migration token, readable but not verified when
     * it is an older token of this site, and never read otherwise.
     */
    public static function forToken(KeyProvider $keys, string $token, ?int $now = null): self
    {
        $now ??= time();
        $cutoff = self::migrationCutoff($token, $keys, $now);

        if ($cutoff !== null) {
            return new self($keys, $cutoff);
        }

        $cutoff = self::tokenCutoff($token, $keys);

        return $cutoff === null || $cutoff > $now ? new self($keys) : new self($keys, $cutoff, false);
    }

    /** A migration token for rows created before `$cutoff`, valid until `$expiresAt` (Unix seconds). */
    public static function migrationToken(KeyProvider $keys, int $expiresAt, int $cutoff): string
    {
        return $expiresAt . '.' . $cutoff . '.' . hash_hmac('sha256', 'com_appointments/booking-migration/v2|' . $expiresAt . '|' . $cutoff,
            $keys->key(KeyProvider::PURPOSE_BOOKING_DATA));
    }

    /** The keyed marker the migration leaves when it is over (or there was nothing to migrate). */
    public static function migrationDoneMarker(KeyProvider $keys): string
    {
        return 'done.' . hash_hmac('sha256', 'com_appointments/booking-migration/v2|done', $keys->key(KeyProvider::PURPOSE_BOOKING_DATA));
    }

    public static function migrationDone(string $value, KeyProvider $keys): bool
    {
        return hash_equals(self::migrationDoneMarker($keys), $value);
    }

    /**
     * The update time of a token made with this site's key that has not expired,
     * null for any other value.
     */
    public static function migrationCutoff(string $token, KeyProvider $keys, int $now): ?int
    {
        $cutoff = self::authenticCutoff($token, $keys, $now);

        return $cutoff !== null && (int) explode('.', $token)[0] > $now && (int) explode('.', $token)[0] <= $now + self::MIGRATION_DAYS * 86400
            ? $cutoff : null;
    }

    /**
     * The update time of a token made with this site's key, expired or not: the
     * migration may resume from it (only rows created before that time), while
     * reading clear text needs an unexpired token. Null from
     * {@see self::MIGRATION_LIMIT_DAYS} after the update on: the update time is
     * inside the keyed hash, so a database write can neither move that limit nor
     * revive an old token.
     */
    public static function authenticCutoff(string $token, KeyProvider $keys, int $now): ?int
    {
        $cutoff = self::tokenCutoff($token, $keys);

        return $cutoff === null || $cutoff > $now || $now >= $cutoff + self::MIGRATION_LIMIT_DAYS * 86400 ? null : $cutoff;
    }

    /**
     * The update time of a token made with this site's key, however old: a
     * migration once opened is never opened again with a later update time.
     */
    public static function tokenCutoff(string $token, KeyProvider $keys): ?int
    {
        if (!preg_match('/^(\d{1,12})\.(\d{1,12})\.[0-9a-f]{64}$/D', $token, $match)) {
            return null;
        }

        return hash_equals(self::migrationToken($keys, (int) $match[1], (int) $match[2]), $token) ? (int) $match[2] : null;
    }

    /**
     * The columns a read selects to open personal fields: UID, creation time and
     * every personal column, also those it does not need, because whether a
     * clear value is read (a row of 1.3) is decided on the whole row.
     *
     * @return  string[]
     */
    public static function readColumns(string $alias = ''): array
    {
        return array_map(static fn (string $column): string => ($alias === '' ? '' : $alias . '.') . $column,
            array_merge(['uid', 'created_utc'], self::COLUMNS));
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

        unset($row[self::OPENED], $row[self::CLEAR], $row[self::UNVERIFIED]);

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
        // Decided on the row as stored, before any field is opened.
        $clearAccepted = $this->clearAccepted($row);

        foreach (self::COLUMNS as $column) {
            if (!\array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            if (!self::isSealed($row[$column])) {
                if (!$clearAccepted) {
                    $failed[] = $column;
                    $row[$column] = null;
                } else {
                    $row[self::CLEAR] = true;

                    if (!$this->verified) {
                        $row[self::UNVERIFIED] = true;
                    }
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
     * The sealed fields of a change to a stored booking. `$current` is the row
     * as opened right before (locked), `$fields` are the clear values that
     * change. When `$current` was a row of 1.3 read in clear text, every other
     * personal field of it is sealed in the same write, so a row is never left
     * half sealed (it would then be read neither as a row of 1.3 nor taken up by
     * the migration). A row that is not verified is never sealed by a change
     * (that would make clear text a database writer may have planted look
     * genuine): the change is written in clear, so the row stays a whole row of
     * 1.3 until the administrator encrypts it. A clear value that would look
     * sealed gets a leading space, so it never makes the row a mixed one.
     *
     * @param   array<string, mixed>  $current
     * @param   array<string, mixed>  $fields
     *
     * @return  array<string, mixed>
     */
    public function sealChange(array $current, array $fields): array
    {
        if (!empty($current[self::UNVERIFIED])) {
            unset($fields[self::OPENED], $fields[self::CLEAR], $fields[self::UNVERIFIED]);

            foreach (self::COLUMNS as $column) {
                if (!\array_key_exists($column, $fields) || $fields[$column] === null) {
                    continue;
                }

                $value = \is_array($fields[$column]) ? json_encode($fields[$column], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $fields[$column];

                if (!\is_scalar($value)) {
                    throw new \InvalidArgumentException('A booking field must be text.');
                }

                $fields[$column] = self::isSealed((string) $value) ? ' ' . $value : (string) $value;
            }

            return $fields;
        }

        if (!empty($current[self::CLEAR])) {
            $fields += array_intersect_key($current, array_flip(self::COLUMNS));
        }

        return $this->seal((string) ($current['uid'] ?? ''), (string) ($current['created_utc'] ?? ''), $fields);
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

    /**
     * Whether a clear value of this row is read as it is: while the migration
     * runs, in a row created before the update that holds no sealed field (a row
     * of 1.3 has none, and the migration seals all fields of a row at once; a
     * row with a backdated creation time and one clear field put in is refused).
     * Decided on the whole row: a read that selected only some personal columns
     * cannot tell, so its clear values are refused (readers select all of
     * {@see self::COLUMNS}).
     */
    private function clearAccepted(array $row): bool
    {
        if ($this->clearBefore === null) {
            return false;
        }

        foreach (self::COLUMNS as $column) {
            if (!\array_key_exists($column, $row) || self::isSealed($row[$column])) {
                return false;
            }
        }

        $created = UtcTimestamp::parse((string) ($row['created_utc'] ?? ''));

        return $created !== null && $created->getTimestamp() < $this->clearBefore;
    }

    private static function data(string $uid, string $created, string $column): string
    {
        return CipherSuite::associatedData('booking', $uid, $created, $column);
    }
}
