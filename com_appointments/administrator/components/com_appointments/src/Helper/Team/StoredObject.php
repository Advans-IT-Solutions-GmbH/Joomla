<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Team;

defined('_JEXEC') or die;

/**
 * One row of `#__appointments_calendar_objects` as the object store reads it:
 * still encrypted. The key service turns `payload` into iCalendar text, the
 * timing index reads `timing` through the server key.
 *
 * @since  1.4.0
 */
final class StoredObject
{
    public function __construct(
        public readonly int $id,
        public readonly int $calendarId,
        public readonly string $uri,
        public readonly string $etag,
        public readonly int $size,
        public readonly int $scheme,
        public readonly int $keyVersion,
        public readonly string $payload,
        public readonly string $timing,
        public readonly ?int $bookingId,
        public readonly \DateTimeImmutable $modifiedUtc
    ) {
    }
}
