<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class MailComposer
{
    public function __construct(private IcsWriter $ics)
    {
    }

    /**
     * Caller selects the translated plain-text subject/body for purpose and
     * recipient, including its translated signed-link footer for invitees.
     * Tokens use {field}, {custom_fields.ID}, and the fixed keys listed below.
     * Replacement is single-pass, so answers cannot become template directives.
     *
     * @param array{subject: string, body: string} $template
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $event IcsWriter event metadata.
     * @param array{reschedule_url?: string, cancel_url?: string, erase_url?: string} $links
     * @return array{subject: string, body: string, attachments: list<array{filename: string, content: string, content_type: string}>}
     */
    public function compose(
        array $template,
        array $booking,
        array $event,
        array $links,
        \DateTimeImmutable $now
    ): array {
        $placeholders = $this->placeholders($booking, $event, $links);
        $subject = strtr($template['subject'], $placeholders);

        if (preg_match('/[\x00-\x1f\x7f]/', $subject)) {
            throw new \InvalidArgumentException('Invalid mail subject.');
        }

        $content = $this->ics->attachment($booking, $event, $now);
        $method = $this->ics->method($booking);

        return [
            'subject' => $subject,
            'body' => strtr($template['body'], $placeholders),
            'attachments' => [
                [
                    'filename' => 'invite.ics',
                    'content' => $content,
                    'content_type' => 'text/calendar; charset=UTF-8; method=' . $method,
                ],
                [
                    'filename' => 'invite.ics',
                    'content' => $content,
                    'content_type' => 'application/ics',
                ],
            ],
        ];
    }

    /** All schema fields, including null/JSON fields, have a documented token. */
    public function placeholders(array $booking, array $event, array $links = []): array
    {
        $fields = [
            'id', 'uid', 'sequence', 'eventtype_id', 'host_user_id', 'start_utc', 'end_utc', 'state',
            'invitee_name', 'invitee_email', 'invitee_phone', 'invitee_timezone', 'invitee_language',
            'guest_emails', 'custom_fields', 'conference_provider', 'conference_url', 'conference_room',
            'conference_state', 'conference_error', 'cancel_reason', 'reminder_sent_utc',
            'anonymised_utc', 'created_utc', 'modified_utc',
        ];
        $result = [];

        foreach ($fields as $field) {
            $result['{' . $field . '}'] = $this->value($booking[$field] ?? null);
        }

        foreach (['title', 'description', 'location', 'location_text', 'location_type', 'host_name', 'host_email'] as $field) {
            $result['{' . ($field === 'title' ? 'event_title' : $field) . '}'] = $this->value($event[$field] ?? null);
        }

        $result['{location}'] = $this->value($event['location'] ?? $event['location_text'] ?? null);

        foreach (['reschedule_url', 'cancel_url', 'erase_url'] as $field) {
            $result['{' . $field . '}'] = $this->value($links[$field] ?? null);
        }

        $custom = $booking['custom_fields'] ?? [];

        if (is_string($custom)) {
            $custom = json_decode($custom, true, 512, JSON_THROW_ON_ERROR);
        }

        if ($custom !== null && !is_array($custom)) {
            throw new \InvalidArgumentException('Invalid custom field answers.');
        }

        foreach ($custom ?? [] as $id => $value) {
            if (!preg_match('/\A[a-zA-Z0-9_-]+\z/D', (string) $id)) {
                throw new \InvalidArgumentException('Invalid custom field ID.');
            }

            $result['{custom_fields.' . $id . '}'] = $this->value($value);
        }

        return $result;
    }

    private function value(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        throw new \InvalidArgumentException('Invalid mail placeholder value.');
    }
}
