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
            'invitee_name' => 'Private Invitee', 'invitee_first_name' => 'Private', 'invitee_last_name' => 'Invitee',
            'invitee_email' => 'invitee@example.test',
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

    public function testTheOriginOfABookingNeverReachesAMailUnlessTheHostCopyFillsIt(): void
    {
        $composer = new MailComposer(new IcsWriter());
        $booking = $this->booking() + ['attribution' => '{"version":1,"booking_ip":"198.51.100.7","user_agent":"CANARY-AGENT"}'];
        $template = ['subject' => 'Termin', 'body' => "Hallo\n{attribution}\nEnde {attribution}"];
        $now = new \DateTimeImmutable('2026-10-14 10:00:00', new \DateTimeZone('UTC'));

        self::assertSame('', $composer->placeholders($booking, $this->event())['{attribution}'], 'The stored JSON is no placeholder value');
        $invitee = $composer->compose($template, $booking, $this->event(), [], $now);
        self::assertSame("Hallo\nEnde ", $invitee['body'], 'An empty origin block leaves no line');
        self::assertStringNotContainsString('198.51.100.7', $invitee['body']);
        self::assertStringNotContainsString('CANARY-AGENT', $invitee['body']);
        $host = $composer->compose($template, $booking, $this->event(), [], $now, ['{attribution}' => "Origin:\nIP: 198.51.100.7"]);
        self::assertSame("Hallo\nOrigin:\nIP: 198.51.100.7\nEnde Origin:\nIP: 198.51.100.7", $host['body']);
    }

    public function testDetailLinesWithAnEmptyValueAreLeftOut(): void
    {
        $body = "Guten Tag,\nOrt: {location}\nTelefon: {invitee_phone}\nE-Mail: {invitee_email}\nHinweis: {location} folgt\n{conference_url}\nEnde";
        $placeholders = ['{location}' => '', '{invitee_phone}' => ' ', '{invitee_email}' => 'a@example.test', '{conference_url}' => ''];

        self::assertSame("Guten Tag,\nE-Mail: {invitee_email}\nHinweis: {location} folgt\n{conference_url}\nEnde",
            MailComposer::withoutEmptyDetails($body, $placeholders));
        self::assertSame("Ort: {unknown}\n", MailComposer::withoutEmptyDetails("Ort: {unknown}\n", $placeholders), 'An unknown token is kept');
    }

    public function testAllBookingFixedAndCustomFieldsHavePlaceholders(): void
    {
        $composer = new MailComposer(new IcsWriter());
        $fields = [
            'id', 'uid', 'sequence', 'eventtype_id', 'host_user_id', 'start_utc', 'end_utc', 'state',
            'invitee_name', 'invitee_first_name', 'invitee_last_name', 'invitee_email', 'invitee_phone', 'invitee_timezone', 'invitee_language',
            'guest_emails', 'custom_fields', 'consent_utc', 'consent_version', 'conference_provider', 'conference_url', 'conference_room',
            'conference_state', 'conference_error', 'cancel_reason', 'reminder_sent_utc',
            'anonymised_utc', 'created_utc', 'modified_utc',
        ];
        $placeholders = $composer->placeholders($this->booking(), $this->event());
        self::assertSame('Private', $placeholders['{invitee_first_name}']);
        self::assertSame('Invitee', $placeholders['{invitee_last_name}']);
        self::assertSame('Private Invitee', $placeholders['{invitee_name}']);
        self::assertSame('', $placeholders['{guest_email}']);
        self::assertSame('', $placeholders['{guest_remove_url}']);

        foreach ($fields as $field) {
            self::assertArrayHasKey('{' . $field . '}', $placeholders);
        }

        foreach (['event_title', 'description', 'location', 'location_text', 'location_type', 'host_name', 'host_email', 'reschedule_url', 'cancel_url', 'erase_url',
            'guest_email', 'guest_remove_url'] as $field) {
            self::assertArrayHasKey('{' . $field . '}', $placeholders);
        }

        self::assertSame('', $placeholders['{anonymised_utc}']);
        self::assertSame('', $placeholders['{consent_utc}']);
        self::assertSame('', $placeholders['{consent_version}']);
        self::assertSame('Answer {host_email}', $placeholders['{custom_fields.question}']);
        $booking = array_replace($this->booking(), ['custom_fields' => '{"question":"stored JSON"}']);
        self::assertSame('stored JSON', $composer->placeholders($booking, $this->event())['{custom_fields.question}']);
        self::assertSame(
            'Stored address',
            $composer->placeholders($booking, $this->event() + ['location_text' => 'Stored address'])['{location}']
        );
    }

    public function testGuestTemplateGreetsNeutrallyAndCarriesTheGuestLinkOnly(): void
    {
        $mail = (new MailComposer(new IcsWriter()))->compose(
            ['subject' => 'Invitation: {event_title}', 'body' => "Hello\n\n{invitee_name} invited {guest_email}.\n{guest_remove_url}\n{erase_url}"],
            $this->booking(), $this->event(),
            ['guest_email' => 'guest@example.test', 'guest_remove_url' => 'https://example.test/?task=guest.confirm&signature=example'],
            new \DateTimeImmutable('2026-10-14 10:00:00', new \DateTimeZone('UTC'))
        );
        self::assertSame("Hello\n\nPrivate Invitee invited guest@example.test.\nhttps://example.test/?task=guest.confirm&signature=example\n", $mail['body']);
    }

    public function testEveryStateAndRecipientReceivesExactlyOneInvitation(): void
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
                // One calendar, sent as the text/calendar alternative of the mail; no attachment list,
                // so no second copy (Outlook showed two «invite.ics»).
                self::assertArrayNotHasKey('attachments', $mail);
                self::assertSame($state === 2 ? 'CANCEL' : 'REQUEST', $mail['method']);
                self::assertStringContainsString('METHOD:' . $mail['method'] . "\r\n", $mail['calendar']);
                self::assertStringContainsString('UID:stable-uid', $mail['calendar']);
            }
        }
    }

    /** The host's calendar entry lists the booking, {description} of the mail stays the event's own text. */
    public function testCalendarDescriptionDoesNotChangeTheDescriptionPlaceholder(): void
    {
        $mail = (new MailComposer(new IcsWriter()))->compose(
            ['subject' => 'Booked: {description}', 'body' => '{description}'],
            $this->booking(), $this->event() + ['calendar_description' => "Details\n\nName: Private Invitee\nTopic: Network"], [],
            new \DateTimeImmutable('2026-10-14 10:00:00', new \DateTimeZone('UTC'))
        );

        self::assertSame('Booked: Details', $mail['subject']);
        self::assertSame('Details', $mail['body']);
        self::assertStringContainsString('DESCRIPTION:Details\\n\\nName: Private Invitee\\nTopic: Network', str_replace("\r\n ", '', $mail['calendar']));
    }

    public function testExtraTokensAreReplacedInTheSamePassAndAnEmptyBlockLeavesNoLine(): void
    {
        $composer = new MailComposer(new IcsWriter());
        $now = new \DateTimeImmutable('2026-10-14 10:00:00', new \DateTimeZone('UTC'));
        $template = ['subject' => 'S', 'body' => "Von: 1\nZeit beim Besucher: {invitee_time}\nGäste: {guest_emails}\n{field_answers}\nOrt: x"];
        $mail = $composer->compose($template, $this->booking(), $this->event(), [], $now,
            ['{field_answers}' => "Frage: {invitee_email}\nWunsch: keine Angabe", '{invitee_time}' => '15.10.2026 06:00 (America/New_York)']);

        self::assertSame("Von: 1\nZeit beim Besucher: 15.10.2026 06:00 (America/New_York)\nGäste: guest@example.test\n"
            . "Frage: {invitee_email}\nWunsch: keine Angabe\nOrt: x", $mail['body'], 'An answer is never a directive');
        self::assertSame("Von: 1\nGäste: guest@example.test\nOrt: x", $composer->compose($template, $this->booking(), $this->event(), [], $now)['body'],
            'Without answers and without another zone both lines disappear');
    }

    public function testDeletionNotificationBodyAndBothAttachmentsContainNoFormerInviteeData(): void
    {
        $anonymiser = new Anonymiser();
        $now = new \DateTimeImmutable('2026-10-14 10:00:00', new \DateTimeZone('UTC'));
        $erased = $anonymiser->anonymise($this->booking(), $now);
        $mail = (new MailComposer(new IcsWriter()))->compose(
            ['subject' => '{event_title}', 'body' => '{start_utc}: {invitee_name} {invitee_first_name} {invitee_last_name} {invitee_email} {cancel_reason} {custom_fields} {guest_emails}'],
            $anonymiser->cancellationPayload($erased), $this->event(), [], $now
        );
        self::assertStringNotContainsString('Private', json_encode($mail));
        self::assertStringNotContainsString('invitee@example.test', json_encode($mail));
        self::assertStringNotContainsString('private-reason', json_encode($mail));
        self::assertStringNotContainsString('guest@example.test', json_encode($mail));
        self::assertStringNotContainsString('ATTENDEE', $mail['calendar']);
        self::assertStringContainsString('METHOD:CANCEL', $mail['calendar']);
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
