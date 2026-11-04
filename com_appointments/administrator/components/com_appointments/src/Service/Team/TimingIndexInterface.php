<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\AvailabilityWindow;

defined('_JEXEC') or die;

/**
 * The time skeleton of a calendar object (work package E): the only part of an
 * object the server can read without a user key, so the booking page and the
 * free/busy role work without anybody signed in.
 *
 * The skeleton is an iCalendar text that keeps, per VEVENT, exactly: DTSTART,
 * DTEND or DURATION, RRULE, RDATE, EXDATE, RECURRENCE-ID, STATUS, TRANSP,
 * X-MICROSOFT-CDO-BUSYSTATUS and the VTIMEZONE components these refer to.
 * Nothing else: no UID, SUMMARY, DESCRIPTION, LOCATION, ATTENDEE, ORGANIZER,
 * ATTACH or X- property beyond the one named. Parsing uses the bundled
 * sabre/vobject (work package A provides the autoloader).
 *
 * @since  1.4.0
 */
interface TimingIndexInterface
{
    /**
     * @throws  \Advans\Component\Appointments\Administrator\Helper\Team\TeamException  400 when the text is not a valid VCALENDAR with VEVENTs.
     */
    public function extract(string $ics): string;

    /**
     * Busy intervals of a skeleton within `[from, to)`, recurrences expanded,
     * in UTC. Not busy: STATUS:CANCELLED, TRANSP:TRANSPARENT and
     * X-MICROSOFT-CDO-BUSYSTATUS:FREE. All-day and floating times are read in
     * `$floatingZone` (the schedule's zone of the event type asking).
     *
     * @return  AvailabilityWindow[]
     */
    public function expand(string $skeleton, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc, \DateTimeZone $floatingZone): array;

    /**
     * The calendar a free/busy grantee receives for one object: the same
     * times, SUMMARY set to `$summary`, a UID derived from `$uidSeed`, CLASS
     * CONFIDENTIAL, nothing else.
     */
    public function redacted(string $skeleton, string $uidSeed, string $summary): string;

    /**
     * Every e-mail address in the object: ORGANIZER and ATTENDEE (mailto:), and
     * addresses in SUMMARY, LOCATION and DESCRIPTION. Lower case, trimmed,
     * unique. Only their keyed hashes are stored (blind index), never the addresses.
     *
     * @return  string[]
     */
    public function emails(string $ics): array;
}
