<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\Anonymiser;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\SignedLink;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/Anonymiser.php';
require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/SignedLink.php';

final class AnonymiserTest extends TestCase
{
    private function booking(): array
    {
        return [
            'id' => 7, 'uid' => 'stable-uid', 'sequence' => 3,
            'eventtype_id' => 2, 'host_user_id' => 3,
            'start_utc' => '2026-10-15 10:00:00', 'end_utc' => '2026-10-15 10:30:00',
            'state' => 1, 'invitee_name' => 'Private Person',
            'invitee_email' => 'private@example.test', 'invitee_phone' => 'private-phone',
            'invitee_timezone' => 'Europe/Zurich', 'invitee_language' => 'de-DE',
            'guest_emails' => 'guest@example.test', 'custom_fields' => '{"question":"private-answer"}',
            'cancel_reason' => 'private-reason', 'conference_provider' => 'jitsi',
            'conference_url' => 'https://example.test/private-room', 'conference_room' => 'private-room',
            'conference_state' => 'failed', 'conference_error' => 'private-diagnostic',
            'reminder_sent_utc' => '[]', 'anonymised_utc' => null,
            'created_utc' => '2026-10-10 10:00:00', 'modified_utc' => '2026-10-14 10:00:00',
        ];
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-14 10:00:00', new \DateTimeZone('UTC'));
    }

    public function testExactErasedAndRetainedFieldsAndFutureCancellation(): void
    {
        $original = $this->booking();
        $result = (new Anonymiser())->anonymise($original, $this->now());
        $erased = [
            'invitee_name', 'invitee_email', 'invitee_phone', 'invitee_timezone',
            'invitee_language', 'guest_emails', 'custom_fields', 'cancel_reason',
            'conference_provider', 'conference_url', 'conference_room', 'conference_state', 'conference_error',
        ];
        self::assertSame($erased, Anonymiser::ERASED_FIELDS);

        foreach ($erased as $field) {
            self::assertNull($result[$field], $field);
        }

        foreach (['id', 'uid', 'eventtype_id', 'host_user_id', 'start_utc', 'end_utc', 'created_utc', 'reminder_sent_utc'] as $field) {
            self::assertSame($original[$field], $result[$field], $field);
        }

        self::assertSame(array_keys($original), array_keys($result));
        self::assertSame(2, $result['state']);
        self::assertSame(4, $result['sequence']);
        self::assertSame('2026-10-14 10:00:00', $result['anonymised_utc']);
        self::assertSame('2026-10-14 10:00:01', $result['modified_utc']);
        self::assertSame('Private Person', $original['invitee_name']);
    }

    public function testIdempotenceAndUsedSignedLinkInvalidation(): void
    {
        $helper = new Anonymiser();
        $booking = $this->booking();
        $links = new SignedLink(new KeyProvider('secret'));
        $signature = $links->sign(7, 'erase', $booking['modified_utc']);
        $erased = $helper->anonymise($booking, $this->now());
        self::assertFalse($links->verify(7, 'erase', $erased['modified_utc'], $signature));
        self::assertSame($erased, $helper->anonymise($erased, $this->now()->modify('+1 day')));
    }

    public function testPastAndAlreadyCancelledBookingsKeepStateAndSequence(): void
    {
        foreach ([
            ['start_utc' => '2026-10-13 10:00:00', 'state' => 1],
            ['start_utc' => '2026-10-15 10:00:00', 'state' => 2],
            ['start_utc' => '2026-10-14 10:00:00', 'state' => 0],
        ] as $changes) {
            $booking = array_replace($this->booking(), $changes);
            $erased = (new Anonymiser())->anonymise($booking, $this->now());
            self::assertSame($booking['state'], $erased['state']);
            self::assertSame($booking['sequence'], $erased['sequence']);
        }
    }

    public function testPendingFutureBookingReleasesSlot(): void
    {
        $booking = array_replace($this->booking(), ['state' => 0]);
        $erased = (new Anonymiser())->anonymise($booking, $this->now());
        self::assertSame(2, $erased['state']);
        self::assertSame(4, $erased['sequence']);
    }

    public function testHostPayloadIsAllowlistedEvenForAnOriginalSensitiveRow(): void
    {
        $booking = array_replace($this->booking(), ['state' => 2, 'unexpected_free_text' => 'private-extra']);
        $payload = (new Anonymiser())->cancellationPayload($booking);
        self::assertSame([
            'id', 'uid', 'sequence', 'eventtype_id', 'host_user_id',
            'start_utc', 'end_utc', 'state', 'modified_utc',
        ], array_keys($payload));
        self::assertStringNotContainsString('private', json_encode($payload));
    }

    public function testHostPayloadRefusesActiveBooking(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Anonymiser())->cancellationPayload($this->booking());
    }
}
