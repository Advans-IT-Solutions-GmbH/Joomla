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
 * opened idempotently, refused when moved or changed.
 */
final class BookingDataTest extends TestCase
{
    private function row(): array
    {
        return ['id' => 7, 'uid' => str_repeat('ab', 32), 'state' => 1, 'invitee_name' => 'Anna Muster', 'invitee_first_name' => 'Anna',
            'invitee_last_name' => 'Muster', 'invitee_email' => 'anna@example.test', 'invitee_phone' => '+41 00 000 00 00',
            'guest_emails' => "one@example.test\ntwo@example.test", 'custom_fields' => '{"3":"Ja"}', 'cancel_reason' => null,
            'conference_url' => 'https://meet.example.test/room'];
    }

    public function testEveryPersonalFieldIsSealedAndOpensAgain(): void
    {
        $data = new BookingData(new KeyProvider('unit-site-secret'));
        $row = $this->row();
        $sealed = $data->seal($row['uid'], $row);

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
        self::assertSame($row, $data->open($sealed));
        self::assertSame($row, $data->open($data->open($sealed)), 'Opening an opened row changes nothing');
        self::assertSame($sealed, $data->seal($row['uid'], $sealed), 'Sealing a sealed row changes nothing');
        self::assertNotSame($sealed['invitee_email'], $data->seal($row['uid'], $row)['invitee_email'], 'A fresh nonce every time');
        self::assertSame('["a",1]', $data->open($data->seal($row['uid'], ['uid' => $row['uid'], 'custom_fields' => ['a', 1]]))['custom_fields']);
    }

    public function testAValueMovedToAnotherBookingOrColumnOrSiteDoesNotOpen(): void
    {
        $data = new BookingData(new KeyProvider('unit-site-secret'));
        $row = $this->row();
        $sealed = $data->seal($row['uid'], $row);

        $moved = $sealed;
        $moved['uid'] = str_repeat('cd', 32);
        $failed = [];
        $opened = $data->openLenient($moved, $failed);
        self::assertSame(['invitee_name', 'invitee_first_name', 'invitee_last_name', 'invitee_email', 'invitee_phone', 'guest_emails',
            'custom_fields', 'conference_url'], $failed);
        self::assertNull($opened['invitee_email']);

        $swapped = $sealed;
        $swapped['invitee_email'] = $sealed['invitee_name'];
        $data->openLenient($swapped, $failed);
        self::assertSame(['invitee_email'], $failed);

        $other = new BookingData(new KeyProvider('another-site-secret'));
        $other->openLenient($sealed, $failed);
        self::assertCount(8, $failed);

        $tampered = $sealed;
        $tampered['invitee_phone'][10] = $tampered['invitee_phone'][10] === 'A' ? 'B' : 'A';
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('invitee_phone');
        $data->open($tampered);
    }

    public function testClearTextOfRowsNotYetMigratedIsReadAsItIs(): void
    {
        $data = new BookingData(new KeyProvider('unit-site-secret'));

        self::assertSame($this->row(), $data->open($this->row()));
        self::assertFalse(BookingData::isSealed('v1:short'));
        self::assertFalse(BookingData::isSealed('anna@example.test'));
    }

    public function testNoUidNoSeal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BookingData(new KeyProvider('unit-site-secret')))->seal('', $this->row());
    }
}
