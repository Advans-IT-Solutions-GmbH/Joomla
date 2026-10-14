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
    public function placeholders(array $booking, string $language, string $timezone): array
    {
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Invalid notification time zone.');
        }

        $zone = new \DateTimeZone($timezone);
        $format = match ($language) {
            'de-DE' => 'd.m.Y H:i',
            'fr-FR' => 'd/m/Y H:i',
            default => 'Y-m-d H:i',
        };
        $start = UtcTimestamp::parse((string) ($booking['start_utc'] ?? ''));
        $end = UtcTimestamp::parse((string) ($booking['end_utc'] ?? ''));

        if (!$start || !$end || $end <= $start) {
            throw new \InvalidArgumentException('Invalid notification appointment bounds.');
        }

        return [
            '{start}' => $start->setTimezone($zone)->format($format),
            '{end}' => $end->setTimezone($zone)->format($format),
            '{timezone}' => $timezone,
        ];
    }
}
