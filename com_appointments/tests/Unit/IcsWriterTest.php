<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\IcsWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/IcsWriter.php';

final class IcsWriterTest extends TestCase
{
    private function booking(array $overrides = []): array
    {
        return array_replace([
            'id' => 7, 'uid' => 'stable-uid', 'sequence' => 0, 'state' => 1,
            'start_utc' => '2026-10-15 10:00:00', 'end_utc' => '2026-10-15 10:30:00',
            'modified_utc' => '2026-10-14 09:00:00', 'invitee_name' => 'Invitee',
            'invitee_email' => 'invitee@example.test',
            'guest_emails' => "guest@example.test\nsecond@example.test",
        ], $overrides);
    }

    private function event(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Meeting', 'description' => 'Details',
            'host_email' => 'host@example.test', 'host_name' => 'Host',
            'location' => 'Office',
        ], $overrides);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-14 10:00:00', new \DateTimeZone('Europe/Zurich'));
    }

    private function unfold(string $ics): string
    {
        return str_replace("\r\n ", '', $ics);
    }

    public static function states(): array
    {
        return [
            'pending' => [0, 0, 'REQUEST', 'TENTATIVE'],
            'confirmed' => [1, 0, 'REQUEST', 'CONFIRMED'],
            'approval' => [1, 1, 'REQUEST', 'CONFIRMED'],
            'reschedule' => [1, 2, 'REQUEST', 'CONFIRMED'],
            'cancel' => [2, 3, 'CANCEL', 'CANCELLED'],
        ];
    }

    #[DataProvider('states')]
    public function testAttachmentStateContract(int $state, int $sequence, string $method, string $status): void
    {
        $ics = (new IcsWriter())->attachment($this->booking(['state' => $state, 'sequence' => $sequence]), $this->event(), $this->now());
        self::assertStringContainsString("METHOD:$method\r\n", $ics);
        self::assertStringContainsString("STATUS:$status\r\n", $ics);
        self::assertStringContainsString("SEQUENCE:$sequence\r\n", $ics);
        self::assertStringContainsString("UID:stable-uid\r\n", $ics);
        self::assertStringContainsString("DTSTAMP:20261014T080000Z\r\n", $ics);
        self::assertStringContainsString("DTSTART:20261015T100000Z\r\n", $ics);
        self::assertStringContainsString("DTEND:20261015T103000Z\r\n", $ics);
        self::assertSame(1, substr_count($ics, "BEGIN:VEVENT\r\n"));
        self::assertSame(3, substr_count($ics, 'ATTENDEE'));
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $ics));
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
    }

    public function testRescheduleChangesTimeButNotUid(): void
    {
        $writer = new IcsWriter();
        $original = $writer->attachment($this->booking(), $this->event(), $this->now());
        $changed = $writer->attachment($this->booking([
            'sequence' => 1, 'start_utc' => '2026-10-16 10:00:00', 'end_utc' => '2026-10-16 10:30:00',
        ]), $this->event(), $this->now());
        self::assertStringContainsString('UID:stable-uid', $original);
        self::assertStringContainsString('UID:stable-uid', $changed);
        self::assertStringContainsString('SEQUENCE:1', $changed);
        self::assertStringContainsString('DTSTART:20261016T100000Z', $changed);
    }

    public function testTextEscapingAndParameterInjectionDefenses(): void
    {
        $name = "Name\";ROLE=CHAIR:^hello\r\nATTENDEE:evil@example.test";
        $ics = $this->unfold((new IcsWriter())->attachment(
            $this->booking(['invitee_name' => $name]),
            $this->event(['title' => "One;two,three\\four\r\nSTATUS:CANCELLED", 'host_name' => $name]),
            $this->now()
        ));
        self::assertStringContainsString('SUMMARY:One\\;two\\,three\\\\four\\nSTATUS:CANCELLED', $ics);
        self::assertStringContainsString('CN="Name^\';ROLE=CHAIR:^^hello^nATTENDEE:evil@example.test"', $ics);
        self::assertSame(3, substr_count($ics, "\r\nATTENDEE"));
        self::assertSame(1, substr_count($ics, "\r\nSTATUS:"));
    }

    public function testUtf8FoldingPreservesEveryByteAndEveryPhysicalLineIsAtMost75Octets(): void
    {
        foreach ([str_repeat('a', 75), str_repeat('a', 74) . 'é', str_repeat('🙂é漢', 60)] as $line) {
            $folded = IcsWriter::fold($line);
            self::assertSame($line, str_replace("\r\n ", '', $folded));

            foreach (explode("\r\n", $folded) as $index => $physical) {
                self::assertLessThanOrEqual(75, strlen($physical));
                self::assertSame(1, preg_match('//u', $physical));

                if ($index > 0) {
                    self::assertStringStartsWith(' ', $physical);
                }
            }
        }

        self::assertSame(str_repeat('a', 75), IcsWriter::fold(str_repeat('a', 75)));
    }

    public function testConferenceLinkHasItsOwnDescriptionLineAndLocation(): void
    {
        $ics = $this->unfold((new IcsWriter())->attachment(
            $this->booking(['conference_url' => 'https://example.test/room']),
            $this->event(['location_type' => 'conference']),
            $this->now()
        ));
        self::assertStringContainsString('DESCRIPTION:Details\\nhttps://example.test/room', $ics);
        self::assertStringContainsString('LOCATION:https://example.test/room', $ics);
    }

    public function testStoredLocationAndLongMultibytePropertiesAreRenderedWithoutDataLoss(): void
    {
        $title = str_repeat('漢🙂é', 60);
        $description = "a;b,c\\d\rline\r\nnext\nlast";
        $writer = new IcsWriter();
        $ics = $writer->attachment(
            $this->booking(),
            $this->event(['title' => $title, 'description' => $description, 'location' => null, 'location_text' => 'Stored address']),
            $this->now()
        );

        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
            self::assertLessThanOrEqual(75, strlen($line));
            self::assertSame(1, preg_match('//u', $line));
        }

        $unfolded = $this->unfold($ics);
        self::assertStringContainsString('SUMMARY:' . $title, $unfolded);
        self::assertStringContainsString('DESCRIPTION:a\\;b\\,c\\\\d\\nline\\nnext\\nlast', $unfolded);
        self::assertStringContainsString('LOCATION:Stored address', $unfolded);
    }

    public function testRawContentLineInjectionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IcsWriter::fold("SUMMARY:Test\r\nSTATUS:CANCELLED");
    }

    public static function invalidInputs(): array
    {
        return [
            'email newline' => [['invitee_email' => "person@example.test\r\nSTATUS:CANCELLED"], []],
            'email params' => [[], ['host_email' => 'host@example.test;ROLE=CHAIR']],
            'no organizer' => [[], ['host_email' => '']],
            'uid empty' => [['uid' => ''], []],
            'bad state' => [['state' => 3], []],
            'bad sequence' => [['sequence' => -1], []],
            'missing sequence' => [['sequence' => null], []],
            'bad date' => [['start_utc' => '2026-02-30 10:00:00'], []],
            'empty interval' => [['end_utc' => '2026-10-15 10:00:00'], []],
            'invalid utf8' => [[], ['title' => "\xff"]],
            'control character' => [[], ['title' => "text\0"]],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function testRefusesMalformedValues(array $booking, array $event): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new IcsWriter())->attachment($this->booking($booking), $this->event($event), $this->now());
    }

    public function testFeedWindowTtlCancellationRetentionAndOrderIndependentEtag(): void
    {
        $writer = new IcsWriter();
        $now = new \DateTimeImmutable('2026-10-14 09:00:00', new \DateTimeZone('UTC'));
        $horizon = $now->modify('+30 days');
        $entries = [];

        foreach ([
            ['uid' => 'active', 'id' => 1],
            ['uid' => 'cancelled', 'id' => 2, 'state' => 2, 'sequence' => 1, 'modified_utc' => '2026-09-14 09:00:00'],
            ['uid' => 'expired-cancel', 'id' => 3, 'state' => 2, 'modified_utc' => '2026-09-14 08:59:59'],
            ['uid' => 'old', 'id' => 4, 'start_utc' => '2026-07-16 08:00:00', 'end_utc' => '2026-07-16 09:00:00'],
            ['uid' => 'beyond', 'id' => 5, 'start_utc' => '2026-11-13 09:00:00', 'end_utc' => '2026-11-13 10:00:00'],
        ] as $booking) {
            $entries[] = ['booking' => $this->booking($booking), 'event' => $this->event()];
        }

        $feed = $writer->feed($entries, 'Host; Calendar', $now, $horizon);
        self::assertStringContainsString('METHOD:PUBLISH', $feed);
        self::assertStringContainsString('X-WR-CALNAME:Host\\; Calendar', $feed);
        self::assertStringContainsString('X-PUBLISHED-TTL:PT15M', $feed);
        self::assertStringContainsString('REFRESH-INTERVAL;VALUE=DURATION:PT15M', $feed);
        self::assertSame(2, substr_count($feed, 'BEGIN:VEVENT'));
        self::assertStringContainsString('UID:cancelled', $feed);
        self::assertStringContainsString('STATUS:CANCELLED', $feed);
        $etag = $writer->feedEtag($entries, $now, $horizon);
        self::assertSame($etag, $writer->feedEtag(array_reverse($entries), $now, $horizon));
        self::assertSame($feed, $writer->feed(array_reverse($entries), 'Host; Calendar', $now, $horizon));
        self::assertMatchesRegularExpression('/\A"[a-f0-9]{64}"\z/', $etag);
        $entries[0]['booking']['modified_utc'] = '2026-10-14 09:00:01';
        self::assertNotSame($etag, $writer->feedEtag($entries, $now, $horizon));
        // Time-driven removal must change the ETag even without a row update.
        self::assertNotSame(
            $writer->feedEtag($entries, $now, $horizon),
            $writer->feedEtag($entries, $now->modify('+1 second'), $horizon)
        );
    }

    public function testHostFeedUsesIndividualEventMetadata(): void
    {
        $entries = [
            ['booking' => $this->booking(['id' => 1, 'uid' => 'one']), 'event' => $this->event(['title' => 'First type'])],
            ['booking' => $this->booking(['id' => 2, 'uid' => 'two']), 'event' => $this->event(['title' => 'Second type'])],
        ];
        $feed = (new IcsWriter())->feed($entries, 'Host', $this->now(), $this->now()->modify('+30 days'));
        self::assertStringContainsString('SUMMARY:First type', $feed);
        self::assertStringContainsString('SUMMARY:Second type', $feed);
        self::assertSame(2, substr_count($feed, 'BEGIN:VEVENT'));
    }

    public function testDuplicateFeedUidIsRejected(): void
    {
        $entry = ['booking' => $this->booking(), 'event' => $this->event()];
        $this->expectException(\InvalidArgumentException::class);
        (new IcsWriter())->feed([$entry, $entry], 'Host', $this->now(), $this->now()->modify('+30 days'));
    }

    public function testNoAttendeesRemainInAnonymisedCancellation(): void
    {
        $booking = $this->booking(['state' => 2, 'sequence' => 1]);
        unset($booking['invitee_name'], $booking['invitee_email'], $booking['guest_emails']);
        $ics = (new IcsWriter())->attachment($booking, $this->event(), $this->now());
        self::assertStringNotContainsString('ATTENDEE', $ics);
        self::assertStringContainsString('METHOD:CANCEL', $ics);
        self::assertStringContainsString('ORGANIZER', $ics);
    }
}
