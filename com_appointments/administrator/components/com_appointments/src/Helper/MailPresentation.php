<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class MailPresentation
{
    /**
     * {start}, {end} and {timezone} of a mail in $timezone, the zone of the recipient.
     *
     * $alsoIn is the host's zone for a mail to the invitee or a guest: when it differs, {start}
     * and {end} add the time there, «24.11.2026 08:00 (14:00 Europe/Zurich)», with the date when
     * that is another day. '' leaves the times as they are.
     */
    public function placeholders(array $booking, string $language, string $timezone, string $alsoIn = ''): array
    {
        [$start, $end] = $this->bounds($booking);
        $zone = $this->zone($timezone);
        $format = $this->format($language);
        $result = [
            '{start}' => $start->setTimezone($zone)->format($format),
            '{end}' => $end->setTimezone($zone)->format($format),
            '{timezone}' => $timezone,
        ];

        if ($alsoIn !== '' && $alsoIn !== $timezone && in_array($alsoIn, \DateTimeZone::listIdentifiers(), true)) {
            $other = new \DateTimeZone($alsoIn);

            foreach (['{start}' => $start, '{end}' => $end] as $token => $instant) {
                $here = $instant->setTimezone($zone);
                $there = $instant->setTimezone($other);
                $time = $here->format('Y-m-d') === $there->format('Y-m-d') ? $there->format('H:i') : $there->format($format);
                $result[$token] .= ' (' . $time . ' ' . $alsoIn . ')';
            }
        }

        return $result;
    }

    /**
     * {invitee_time} of a host mail: the start at the invitee, «24.11.2026 08:00 (America/New_York)»,
     * or '' when the invitee's zone is unknown or the one the host mail already shows.
     */
    public function inviteeTime(array $booking, string $language, string $hostZone): string
    {
        $invitee = (string) ($booking['invitee_timezone'] ?? '');

        if ($invitee === '' || $invitee === $hostZone || !in_array($invitee, \DateTimeZone::listIdentifiers(), true)) {
            return '';
        }

        [$start] = $this->bounds($booking);

        return $start->setTimezone(new \DateTimeZone($invitee))->format($this->format($language)) . ' (' . $invitee . ')';
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function bounds(array $booking): array
    {
        $start = UtcTimestamp::parse((string) ($booking['start_utc'] ?? ''));
        $end = UtcTimestamp::parse((string) ($booking['end_utc'] ?? ''));

        if (!$start || !$end || $end <= $start) {
            throw new \InvalidArgumentException('Invalid notification appointment bounds.');
        }

        return [$start, $end];
    }

    private function zone(string $timezone): \DateTimeZone
    {
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Invalid notification time zone.');
        }

        return new \DateTimeZone($timezone);
    }

    private function format(string $language): string
    {
        return match ($language) {
            'de-DE' => 'd.m.Y H:i',
            'fr-FR' => 'd/m/Y H:i',
            default => 'Y-m-d H:i',
        };
    }
}
