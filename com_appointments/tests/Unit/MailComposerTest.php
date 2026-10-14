<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\Anonymiser;
use Advans\Component\Appointments\Administrator\Helper\IcsWriter;
use Advans\Component\Appointments\Administrator\Helper\MailComposer;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/IcsWriter.php';
require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/MailComposer.php';
require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/Anonymiser.php';

final class MailComposerTest extends TestCase
{
    private function booking(): array
    {
        return [
            'id' => 1, 'uid' => 'stable-uid', 'sequence' => 0, 'state' => 1,
            'start_utc' => '2026-10-15 10:00:00', 'end_utc' => '2026-10-15 10:30:00',
            'modified_utc' => '2026-10-14 10:00:00',
            'invitee_name' => 'Private Invitee', 'invitee_email' => 'invitee@example.test',
            'custom_fields' => ['question' => 'Answer {host_email}', 12 => 'ä,;'],
            'guest_emails' => 'guest@example.test', 'cancel_reason' => 'private-reason',
        ];
    }

    private function event(): array
    {
        return ['title' => 'Meeting', 'host_email' => 'host@example.test', 'host_name' => 'Host', 'description' => 'Details'];
    }

    public function testTranslatedCallerTemplateAndSinglePassCustomPlaceholders(): void
    {
        $composer = new MailComposer(new IcsWriter());
        $mail = $composer->compose(
            ['subject' => 'Termin: {event_title}', 'body' => '{invitee_name}: {custom_fields.question} / {custom_fields.12}' . "\n{erase_url}"],
            $this->booking(), $this->event(), ['erase_url' => 'https://example.test/erase?signature=example'],
            new \DateTimeImmutable('2026-10-14 10:00:00', new \DateTimeZone('UTC'))
        );
        self::assertSame('Termin: Meeting', $mail['subject']);
        self::assertSame("Private Invitee: Answer {host_email} / ä,;\nhttps://example.test/erase?signature=example", $mail['body']);
    }

    public function testAllBookingFixedAndCustomFieldsHavePlaceholders(): void
    {
        $composer = new MailComposer(new IcsWriter());
        $fields = [
            'id', 'uid', 'sequence', 'eventtype_id', 'host_user_id', 'start_utc', 'end_utc', 'state',
            'invitee_name', 'invitee_email', 'invitee_phone', 'invitee_timezone', 'invitee_language',
            'guest_emails', 'custom_fields', 'conference_provider', 'conference_url', 'conference_room',
            'conference_state', 'conference_error', 'cancel_reason', 'reminder_sent_utc',
            'anonymised_utc', 'created_utc', 'modified_utc',
        ];
        $placeholders = $composer->placeholders($this->booking(), $this->event());

        foreach ($fields as $field) {
            self::assertArrayHasKey('{' . $field . '}', $placeholders);
        }

        foreach (['event_title', 'description', 'location', 'location_text', 'location_type', 'host_name', 'host_email', 'reschedule_url', 'cancel_url', 'erase_url'] as $field) {
            self::assertArrayHasKey('{' . $field . '}', $placeholders);
        }

        self::assertSame('', $placeholders['{anonymised_utc}']);
        self::assertSame('Answer {host_email}', $placeholders['{custom_fields.question}']);
        $booking = array_replace($this->booking(), ['custom_fields' => '{"question":"stored JSON"}']);
        self::assertSame('stored JSON', $composer->placeholders($booking, $this->event())['{custom_fields.question}']);
        self::assertSame(
            'Stored address',
            $composer->placeholders($booking, $this->event() + ['location_text' => 'Stored address'])['{location}']
        );
    }

    public function testEveryStateAndRecipientReceivesBothIcsParts(): void
    {
        $composer = new MailComposer(new IcsWriter());
        $now = new \DateTimeImmutable('2026-10-14 10:00:00', new \DateTimeZone('UTC'));

        foreach (['new', 'pending', 'approval', 'reschedule', 'cancel', 'reminder'] as $purpose) {
            foreach (['invitee', 'guest', 'host'] as $recipient) {
                $state = $purpose === 'cancel' ? 2 : ($purpose === 'pending' ? 0 : 1);
                $mail = $composer->compose(
                    ['subject' => "$purpose $recipient", 'body' => 'Translated body'],
                    array_replace($this->booking(), ['state' => $state]), $this->event(), [], $now
                );
                self::assertCount(2, $mail['attachments']);
                self::assertSame('text/calendar; charset=UTF-8; method=' . ($state === 2 ? 'CANCEL' : 'REQUEST'), $mail['attachments'][0]['content_type']);
                self::assertSame('application/ics', $mail['attachments'][1]['content_type']);
                self::assertSame('invite.ics', $mail['attachments'][0]['filename']);
                self::assertSame('invite.ics', $mail['attachments'][1]['filename']);
                self::assertSame($mail['attachments'][0]['content'], $mail['attachments'][1]['content']);
                self::assertStringContainsString('UID:stable-uid', $mail['attachments'][0]['content']);
            }
        }
    }

    public function testDeletionNotificationBodyAndBothAttachmentsContainNoFormerInviteeData(): void
    {
        $anonymiser = new Anonymiser();
        $now = new \DateTimeImmutable('2026-10-14 10:00:00', new \DateTimeZone('UTC'));
        $erased = $anonymiser->anonymise($this->booking(), $now);
        $mail = (new MailComposer(new IcsWriter()))->compose(
            ['subject' => '{event_title}', 'body' => '{start_utc}: {invitee_name} {invitee_email} {cancel_reason} {custom_fields} {guest_emails}'],
            $anonymiser->cancellationPayload($erased), $this->event(), [], $now
        );
        self::assertStringNotContainsString('Private', json_encode($mail));
        self::assertStringNotContainsString('invitee@example.test', json_encode($mail));
        self::assertStringNotContainsString('private-reason', json_encode($mail));
        self::assertStringNotContainsString('guest@example.test', json_encode($mail));
        self::assertStringNotContainsString('ATTENDEE', $mail['attachments'][0]['content']);
        self::assertStringContainsString('METHOD:CANCEL', $mail['attachments'][0]['content']);
    }

    public function testSubjectInjectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MailComposer(new IcsWriter()))->compose(
            ['subject' => '{invitee_name}', 'body' => 'Body'],
            array_replace($this->booking(), ['invitee_name' => "Name\r\nBcc: other@example.test"]),
            $this->event(), [], new \DateTimeImmutable('2026-10-14', new \DateTimeZone('UTC'))
        );
    }
}
