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
 * `CipherSuite::encrypt(key(PURPOSE_BOOKING_DATA), value, associatedData('booking', uid, column))`.
 * The booking's UID exists before the row is inserted and never changes, so a
 * value can be moved neither to another booking nor to another column.
 *
 * This class is the only code that turns these columns into text and back.
 * Code that reads a booking row calls {@see self::open()} right after the read,
 * code that writes calls {@see self::seal()} right before the write. Both
 * accept a whole row: other keys pass through unchanged, `null` stays `null`.
 * Opening is idempotent: a value that is not a sealed value (clear text of a
 * row the migration has not reached yet, or a value opened before) is
 * returned as it is. A sealed value that does not open (another site secret,
 * a changed byte) throws; {@see self::openLenient()} blanks it instead and
 * reports the column, for mails, reminders and exports.
 *
 * Pure: no database, no Joomla runtime, so it is tested on its own.
 *
 * @since  1.4.0
 */
final class BookingData
{
    /** The encrypted columns of `#__appointments_bookings`. */
    public const COLUMNS = ['invitee_name', 'invitee_first_name', 'invitee_last_name', 'invitee_email', 'invitee_phone',
        'guest_emails', 'custom_fields', 'cancel_reason', 'conference_url'];

    private const PREFIX = 'v1:';

    /** `v1:` and Base64 of at least nonce and tag (40 bytes): nothing a person types looks like it. */
    private const SEALED = '/^v1:[A-Za-z0-9+\/]{54,}={0,2}$/D';

    /**
     * @var  string
     */
    private $key;

    public function __construct(KeyProvider $keys)
    {
        $this->key = $keys->key(KeyProvider::PURPOSE_BOOKING_DATA);
    }

    /**
     * The installation's instance, for code that runs inside Joomla.
     */
    public static function fromApplication(): self
    {
        return new self(new KeyProvider((string) \Joomla\CMS\Factory::getApplication()->get('secret', '')));
    }

    public static function isSealed(mixed $value): bool
    {
        return \is_string($value) && preg_match(self::SEALED, $value) === 1;
    }

    /**
     * The row with every personal field sealed. A value that is already sealed
     * stays as it is; an array (answers) is stored as its JSON text.
     *
     * @param   array<string, mixed>  $row
     *
     * @return  array<string, mixed>
     *
     * @throws  \InvalidArgumentException  Without a UID, or for a value that is no text.
     */
    public function seal(string $uid, array $row): array
    {
        if ($uid === '') {
            throw new \InvalidArgumentException('A booking without UID cannot be sealed.');
        }

        foreach (self::COLUMNS as $column) {
            if (!\array_key_exists($column, $row) || $row[$column] === null || self::isSealed($row[$column])) {
                continue;
            }

            $value = $row[$column];

            if (\is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }

            if (!\is_scalar($value)) {
                throw new \InvalidArgumentException('A booking field must be text.');
            }

            $row[$column] = self::PREFIX . base64_encode(CipherSuite::encrypt($this->key, (string) $value, self::data($uid, $column)));
        }

        return $row;
    }

    /**
     * The row with every personal field in clear text.
     *
     * @param   array<string, mixed>  $row  A booking row with its `uid`.
     *
     * @return  array<string, mixed>
     *
     * @throws  \UnexpectedValueException  When a sealed value does not open; the message names the column, never a value.
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
     * Like {@see self::open()}, but a value that does not open becomes `null`
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
        $uid = (string) ($row['uid'] ?? '');

        foreach (self::COLUMNS as $column) {
            if (!\array_key_exists($column, $row) || !self::isSealed($row[$column])) {
                continue;
            }

            $raw = $uid === '' ? false : base64_decode(substr($row[$column], \strlen(self::PREFIX)), true);
            $plain = $raw === false ? null : CipherSuite::decrypt($this->key, $raw, self::data($uid, $column));

            if ($plain === null) {
                $failed[] = $column;
            }

            $row[$column] = $plain;
        }

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

    private static function data(string $uid, string $column): string
    {
        return CipherSuite::associatedData('booking', $uid, $column);
    }
}
