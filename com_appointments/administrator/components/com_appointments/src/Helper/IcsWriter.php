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

final class IcsWriter
{
    /**
     * Booking: uid, sequence, state (0/1/2), start_utc, end_utc, optional
     * invitee_name/email, guest_emails (newline separated), conference_url.
     * Event: title, host_email, optional host_name, plain-text description,
     * location (rendered text) or location_text (stored text), location_type.
     * A privacy cancellation may omit all attendees. Caller strips markup from
     * rich event descriptions before handing them to this plain-text writer.
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $event
     */
    public function attachment(array $booking, array $event, \DateTimeImmutable $now): string
    {
        return $this->calendar(
            $this->method($booking),
            $this->event($booking, $event, $now)
        );
    }

    /**
     * Entries carry their own event metadata so a host feed can span event types.
     * History includes meetings overlapping the preceding 90 days; cancellations
     * survive for 30 days from modified_utc. Horizon end is exclusive.
     *
     * @param list<array{booking: array<string, mixed>, event: array<string, mixed>}> $entries
     */
    public function feed(
        array $entries,
        string $calendarName,
        \DateTimeImmutable $now,
        \DateTimeImmutable $horizonEnd
    ): string {
        $selected = $this->feedEntries($entries, $now, $horizonEnd);
        $lines = [
            'X-WR-CALNAME:' . self::escapeText($calendarName),
            'X-PUBLISHED-TTL:PT15M',
            'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
        ];
        $uids = [];

        foreach ($selected as $entry) {
            $booking = $entry['booking'];
            $uid = (string) ($booking['uid'] ?? '');

            if (isset($uids[$uid])) {
                throw new \InvalidArgumentException('Duplicate calendar UID.');
            }

            $uids[$uid] = true;
            $lines = array_merge($lines, $this->event(
                $booking,
                $entry['event'],
                $this->timestamp($booking['modified_utc'] ?? '')
            ));
        }

        return $this->calendar('PUBLISH', $lines);
    }

    /** Quote this strong ETag verbatim in the HTTP response. */
    public function feedEtag(
        array $entries,
        \DateTimeImmutable $now,
        \DateTimeImmutable $horizonEnd
    ): string {
        $versions = [];

        foreach ($this->feedEntries($entries, $now, $horizonEnd) as $entry) {
            $booking = $entry['booking'];

            if (!isset($booking['id']) || (int) $booking['id'] < 1) {
                throw new \InvalidArgumentException('Missing feed booking ID.');
            }

            $versions[] = [
                (int) $booking['id'],
                $this->timestamp($booking['modified_utc'] ?? '')->format('Y-m-d H:i:s'),
            ];
        }

        sort($versions);

        return '"' . hash('sha256', json_encode($versions, JSON_THROW_ON_ERROR)) . '"';
    }

    public function method(array $booking): string
    {
        $state = $booking['state'] ?? null;

        if (!in_array($state, [0, 1, 2, '0', '1', '2'], true)) {
            throw new \InvalidArgumentException('Invalid calendar booking state.');
        }

        return (int) $state === 2 ? 'CANCEL' : 'REQUEST';
    }

    public static function escapeText(string $value): string
    {
        self::validateText($value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }

    /** RFC 5545 counts octets, including the continuation space, not characters. */
    public static function fold(string $line): string
    {
        self::validateText($line);

        if (strpbrk($line, "\r\n") !== false) {
            throw new \InvalidArgumentException('A content line cannot contain a newline.');
        }

        $parts = [];
        $limit = 75;

        while (strlen($line) > $limit) {
            $length = $limit;

            while ((ord($line[$length]) & 0xC0) === 0x80) {
                --$length;
            }

            $parts[] = substr($line, 0, $length);
            $line = substr($line, $length);
            $limit = 74;
        }

        $parts[] = $line;

        return implode("\r\n ", $parts);
    }

    private function calendar(string $method, array $lines): string
    {
        $lines = array_merge([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Advans IT Solutions GmbH//Appointments//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
        ], $lines, ['END:VCALENDAR']);

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    private function event(array $booking, array $event, \DateTimeImmutable $stamp): array
    {
        $this->method($booking);
        $uid = (string) ($booking['uid'] ?? '');
        $sequence = $booking['sequence'] ?? null;

        if ($uid === '' || filter_var($sequence, FILTER_VALIDATE_INT) === false || (int) $sequence < 0) {
            throw new \InvalidArgumentException('Invalid calendar UID or sequence.');
        }

        $start = $this->timestamp($booking['start_utc'] ?? '');
        $end = $this->timestamp($booking['end_utc'] ?? '');

        if ($end <= $start) {
            throw new \InvalidArgumentException('Invalid calendar interval.');
        }

        $description = (string) ($event['description'] ?? '');
        $location = (string) ($event['location'] ?? $event['location_text'] ?? '');
        $conference = (string) ($booking['conference_url'] ?? '');

        if ($conference !== '') {
            $description = rtrim($description) . ($description === '' ? '' : "\n") . $conference;

            if (($event['location_type'] ?? '') === 'conference') {
                $location = $conference;
            }
        }

        $lines = [
            'BEGIN:VEVENT',
            'UID:' . self::escapeText($uid),
            'DTSTAMP:' . $stamp->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            'DTSTART:' . $start->format('Ymd\THis\Z'),
            'DTEND:' . $end->format('Ymd\THis\Z'),
            'SUMMARY:' . self::escapeText((string) ($event['title'] ?? '')),
            'DESCRIPTION:' . self::escapeText($description),
            $this->address('ORGANIZER', (string) ($event['host_email'] ?? ''), (string) ($event['host_name'] ?? '')),
        ];

        $invitee = (string) ($booking['invitee_email'] ?? '');

        if ($invitee !== '') {
            $lines[] = $this->address('ATTENDEE', $invitee, (string) ($booking['invitee_name'] ?? ''));
        }

        $guests = preg_split('/\r\n|\r|\n/', (string) ($booking['guest_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

        foreach (array_unique($guests) as $guest) {
            if ($guest !== $invitee) {
                $lines[] = $this->address('ATTENDEE', $guest, '');
            }
        }

        return array_merge($lines, [
            'LOCATION:' . self::escapeText($location),
            'SEQUENCE:' . (int) $sequence,
            'STATUS:' . [0 => 'TENTATIVE', 1 => 'CONFIRMED', 2 => 'CANCELLED'][(int) $booking['state']],
            'END:VEVENT',
        ]);
    }

    private function address(string $property, string $email, string $name): string
    {
        // URI values are not TEXT. Never allow delimiters to introduce parameters
        // or additional recipients; quoted display names use RFC 6868 encoding.
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\x00-\x20\x7f";:,\\\\]/', $email)) {
            throw new \InvalidArgumentException('Invalid calendar participant address.');
        }

        self::validateText($name);
        $name = str_replace(["\r\n", "\r"], "\n", $name);
        $name = str_replace(['^', '"', "\n"], ['^^', "^'", '^n'], $name);
        $parameter = $name === '' ? '' : ';CN="' . $name . '"';

        return $property . $parameter . ':mailto:' . $email;
    }

    private function timestamp(mixed $value): \DateTimeImmutable
    {
        $parsed = is_string($value) ? UtcTimestamp::parse($value) : null;

        if ($parsed === null) {
            throw new \InvalidArgumentException('Invalid calendar timestamp.');
        }

        return $parsed;
    }

    private function feedEntries(array $entries, \DateTimeImmutable $now, \DateTimeImmutable $horizonEnd): array
    {
        if ($horizonEnd <= $now) {
            throw new \InvalidArgumentException('Invalid feed horizon.');
        }

        $utcNow = $now->setTimezone(new \DateTimeZone('UTC'));
        $history = $utcNow->modify('-90 days');
        $cancelCutoff = $utcNow->modify('-30 days');
        $selected = [];

        foreach ($entries as $entry) {
            $booking = $entry['booking'];
            $method = $this->method($booking);
            $start = $this->timestamp($booking['start_utc'] ?? '');
            $end = $this->timestamp($booking['end_utc'] ?? '');
            $modified = $this->timestamp($booking['modified_utc'] ?? '');

            if ($end > $history && $start < $horizonEnd && ($method !== 'CANCEL' || $modified >= $cancelCutoff)) {
                $selected[] = $entry;
            }
        }

        usort($selected, static fn (array $a, array $b): int => strcmp($a['booking']['uid'], $b['booking']['uid']));

        return $selected;
    }

    private static function validateText(string $value): void
    {
        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new \InvalidArgumentException('Invalid calendar text.');
        }
    }
}
