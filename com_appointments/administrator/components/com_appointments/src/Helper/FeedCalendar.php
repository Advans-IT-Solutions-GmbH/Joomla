<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class FeedCalendar
{
    public static function verify(string $stored, string $supplied): bool
    {
        $wellFormed = preg_match('/\A[a-f0-9]{64}\z/D', $supplied) === 1;
        $matches = FeedToken::verify($stored, $wellFormed ? $supplied : str_repeat('0', 64));

        return $wellFormed && $matches;
    }

    public static function horizon(array $event, string $timezone, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $rules = SlotRules::fromEventType($event);

        if ($rules->getHorizonDays() === 0) {
            return $rules->getHorizonEnd();
        }

        $resolver = new ScheduleResolver($timezone, []);
        $local = $now->setTimezone($resolver->getTimezone());
        $calendar = $now->setTimezone(new \DateTimeZone('UTC'))
            ->setDate((int) $local->format('Y'), (int) $local->format('m'), (int) $local->format('d'))
            ->setTime(0, 0);

        return $resolver->localToUtc($calendar->modify('+' . ($rules->getHorizonDays() + 1) . ' days'));
    }

    /** Hash the representation too: event metadata and aged-out cancellations affect it. */
    public static function etag(string $calendar, array $horizons): string
    {
        ksort($horizons);

        return '"' . hash('sha256', $calendar . "\0" . json_encode($horizons, JSON_THROW_ON_ERROR)) . '"';
    }

    public static function matches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $etag || $candidate === 'W/' . $etag) {
                return true;
            }
        }

        return false;
    }
}
