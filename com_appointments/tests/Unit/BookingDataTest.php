<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\BookingData;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use PHPUnit\Framework\TestCase;

/**
 * Booking rows at rest (team-calendars.md, 5.10): sealed per booking and column,
 * opened once, refused when moved, changed or put in as clear text after the migration.
 */
final class BookingDataTest extends TestCase
{
    private function row(): array
    {
        return ['id' => 7, 'uid' => str_repeat('ab', 32), 'created_utc' => '2026-11-04 09:00:00', 'state' => 1, 'invitee_name' => 'Anna Muster', 'invitee_first_name' => 'Anna',
            'invitee_last_name' => 'Muster', 'invitee_email' => 'anna@example.test', 'invitee_phone' => '+41 00 000 00 00',
            'guest_emails' => "one@example.test\ntwo@example.test", 'custom_fields' => '{"3":"Ja"}', 'cancel_reason' => null,
            'conference_url' => 'https://meet.example.test/room'];
    }

    private function data(?int $clearBefore = null): BookingData
    {
        return new BookingData(new KeyProvider('unit-site-secret'), $clearBefore);
    }

    public function testEveryPersonalFieldIsSealedAndOpensOnce(): void
    {
        $data = $this->data();
        $row = $this->row();
        $sealed = $data->seal($row['uid'], $row['created_utc'], $row);

        foreach (BookingData::COLUMNS as $column) {
            if ($row[$column] === null) {
                self::assertNull($sealed[$column]);

                continue;
            }

            self::assertTrue(BookingData::isSealed($sealed[$column]), $column);
            self::assertStringNotContainsString((string) $row[$column], $sealed[$column]);
        }

        self::assertSame(7, $sealed['id']);
        self::assertSame(1, $sealed['state']);
        $opened = $data->open($sealed);
        self::assertSame($row + [BookingData::OPENED => true], $opened);
        self::assertSame($opened, $data->open($opened), 'Opening an opened row changes nothing');
        self::assertArrayNotHasKey(BookingData::OPENED, $data->seal($row['uid'], $row['created_utc'], $opened), 'The marker is never written');
        self::assertNotSame($sealed['invitee_email'], $data->seal($row['uid'], $row['created_utc'], $row)['invitee_email'], 'A fresh nonce every time');
        self::assertSame('["a",1]', $data->open($data->seal($row['uid'], $row['created_utc'], ['uid' => $row['uid'], 'created_utc' => $row['created_utc'], 'custom_fields' => ['a', 1]]))['custom_fields']);
    }

    public function testInputThatLooksSealedIsEncryptedLikeAnyOther(): void
    {
        $data = $this->data();
        $uid = str_repeat('ab', 32);
        $lookalike = 'v1:' . str_repeat('A', 60);
        $sealed = $data->seal($uid, '2026-11-04 09:00:00', ['uid' => $uid, 'created_utc' => '2026-11-04 09:00:00', 'invitee_name' => $lookalike]);

        self::assertNotSame($lookalike, $sealed['invitee_name']);
        self::assertSame($lookalike, $data->open($sealed)['invitee_name']);
    }

    public function testAValueMovedToAnotherBookingOrColumnOrSiteDoesNotOpen(): void
    {
        $data = $this->data();
        $row = $this->row();
        $sealed = $data->seal($row['uid'], $row['created_utc'], $row);

        $moved = $sealed;
        $moved['uid'] = str_repeat('cd', 32);
        $failed = [];
        $opened = $data->openLenient($moved, $failed);
        self::assertSame(['invitee_name', 'invitee_first_name', 'invitee_last_name', 'invitee_email', 'invitee_phone', 'guest_emails',
            'custom_fields', 'conference_url'], $failed);
        self::assertNull($opened['invitee_email']);

        // UID and personal fields copied into another row with another creation time.
        $copied = $sealed;
        $copied['created_utc'] = '2026-11-05 09:00:00';
        $data->openLenient($copied, $failed);
        self::assertCount(8, $failed);

        $swapped = $sealed;
        $swapped['invitee_email'] = $sealed['invitee_name'];
        $data->openLenient($swapped, $failed);
        self::assertSame(['invitee_email'], $failed);

        (new BookingData(new KeyProvider('another-site-secret')))->openLenient($sealed, $failed);
        self::assertCount(8, $failed);

        $tampered = $sealed;
        $tampered['invitee_phone'][10] = $tampered['invitee_phone'][10] === 'A' ? 'B' : 'A';
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('invitee_phone');
        $data->open($tampered);
    }

    public function testClearTextCountsOnlyWhileTheMigrationRuns(): void
    {
        $failed = [];
        $strict = $this->data()->openLenient($this->row(), $failed);
        self::assertNull($strict['invitee_email'], 'A clear address put in by a database write gets no mail');
        self::assertCount(8, $failed);

        // While the migration runs: clear text of a row created before the update reads as it is, of a later row not.
        $update = (new \DateTimeImmutable('2026-11-04 10:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        self::assertSame($this->row() + [BookingData::OPENED => true], $this->data($update)->open($this->row()));
        $later = array_replace($this->row(), ['created_utc' => '2026-11-04 11:00:00']);
        $this->data($update)->openLenient($later, $failed);
        self::assertCount(8, $failed, 'Clear text in a row made after the update is never data');

        // A 1.4 row backdated and given one clear address: refused, because it holds sealed fields.
        $mixed = array_replace($this->data()->seal($this->row()['uid'], $this->row()['created_utc'], $this->row()), ['invitee_email' => 'planted@example.test']);
        $opened = $this->data($update)->openLenient($mixed, $failed);
        self::assertNull($opened['invitee_email']);
        self::assertContains('invitee_email', $failed);
        self::assertFalse(BookingData::isSealed('v1:short'));
        self::assertFalse(BookingData::isSealed('anna@example.test'));
    }

    public function testTheMigrationTokenIsKeyedAndExpires(): void
    {
        $keys = new KeyProvider('unit-site-secret');
        $now = 1800000000;
        $token = BookingData::migrationToken($keys, $now + 86400, $now - 60);

        self::assertSame($now - 60, BookingData::migrationCutoff($token, $keys, $now));
        self::assertNull(BookingData::migrationCutoff($token, $keys, $now + 86400), 'Expired');
        self::assertSame($now - 60, BookingData::authenticCutoff($token, $keys, $now + 86400), 'An expired token still lets the migration resume');
        self::assertNull(BookingData::migrationCutoff($token, new KeyProvider('another-site-secret'), $now), 'Another site');
        self::assertNull(BookingData::migrationCutoff(($now + 86400) . '.' . ($now - 60) . '.' . str_repeat('0', 64), $keys, $now), 'Forged');
        self::assertNull(BookingData::migrationCutoff(str_replace('.' . ($now - 60) . '.', '.' . ($now - 30) . '.', $token), $keys, $now), 'Update time changed');
        self::assertNull(BookingData::migrationCutoff(BookingData::migrationToken($keys, $now + 30 * 86400, $now), $keys, $now), 'Longer than allowed');
        self::assertNull(BookingData::migrationCutoff(BookingData::migrationToken($keys, $now + 86400, $now + 60), $keys, $now), 'Update in the future');
        self::assertNull(BookingData::migrationCutoff('', $keys, $now));

        $done = BookingData::migrationDoneMarker($keys);
        self::assertTrue(BookingData::migrationDone($done, $keys));
        self::assertFalse(BookingData::migrationDone($done, new KeyProvider('another-site-secret')), 'The end marker is keyed');
        self::assertNull(BookingData::migrationCutoff($done, $keys, $now), 'The end marker opens nothing');
    }

    public function testNoUidNoSeal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->data()->seal('', '2026-11-04 09:00:00', $this->row());
    }
}
