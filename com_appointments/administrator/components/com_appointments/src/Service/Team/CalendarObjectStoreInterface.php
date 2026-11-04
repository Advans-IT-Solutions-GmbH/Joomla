<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Advans\Component\Appointments\Administrator\Helper\Team\ChangeSet;
use Advans\Component\Appointments\Administrator\Helper\Team\StoredObject;

defined('_JEXEC') or die;

/**
 * Encrypted calendar objects, sync tokens and the change journal (work
 * package A). This store does not decide access: the CalDAV backend asks the
 * grant service first and only then calls it. Every write runs in one
 * transaction that also raises `#__appointments_calendars.synctoken`, appends
 * to `#__appointments_calendar_changes` and stores the time skeleton
 * ({@see TimingIndexInterface::extract()}) encrypted with the server key, and
 * the blind index of its e-mail addresses ({@see TimingIndexInterface::emails()},
 * `hash_hmac('sha256', address, key(PURPOSE_EMAIL_INDEX), true)`) in
 * `#__appointments_calendar_object_emails`. When the index cannot be built, the
 * object is stored with `emails_indexed = 0` and the privacy plugin lists it.
 *
 * The blind index lets the privacy plugin find and delete objects without a user key.
 *
 * Limits: an object is one VCALENDAR with VEVENT components of one UID, at
 * most 1 MiB; a URI is 1 to 255 characters of `[A-Za-z0-9._@+=-]` and ends in
 * `.ics`. Anything else is refused with 400 or 413.
 *
 * @since  1.4.0
 */
interface CalendarObjectStoreInterface
{
    /**
     * @return  StoredObject[]  Every object of the calendar, still encrypted.
     */
    public function all(int $calendarId): array;

    /**
     * @param   string[]  $uris
     *
     * @return  StoredObject[]  The existing ones among them (calendar-multiget).
     */
    public function many(int $calendarId, array $uris): array;

    public function get(int $calendarId, string $uri): ?StoredObject;

    /**
     * Create or replace an object written by a key holder.
     *
     * @param   string|null  $ifMatch      ETag the client expects, `*`, or null.
     * @param   bool         $ifNoneMatch  True for `If-None-Match: *` (create only).
     *
     * @return  string  The new ETag.
     *
     * @throws  \Advans\Component\Appointments\Administrator\Helper\Team\TeamException  412 on a failed precondition, 409 on a UID used by another URI.
     */
    public function put(CalendarKeyring $keyring, string $uri, string $ics, ?string $ifMatch, bool $ifNoneMatch, ?int $actorDeviceId): string;

    /**
     * Create or replace the object of a booking, sealed to the calendar's public
     * key (no user key involved). The URI is derived from the booking's UID.
     *
     * @return  string  The new ETag.
     */
    public function putSealed(int $calendarId, string $ics, int $bookingId): string;

    public function delete(int $calendarId, string $uri, ?string $ifMatch, ?int $actorDeviceId): void;

    /**
     * Remove every object written for a booking, in every calendar.
     *
     * @return  int  Objects removed.
     */
    public function deleteForBooking(int $bookingId): int;

    /**
     * Re-encrypt objects of older key versions and sealed objects with the
     * current calendar key, a batch at a time, inside a key holder's request.
     * The ETag and the sync token do not change, because the content does not.
     *
     * @return  int  Objects re-encrypted.
     */
    public function reencrypt(CalendarKeyring $keyring, int $limit): int;

    /**
     * Retention of one calendar (`retention_days`): delete every object whose
     * last occurrence ended before the given instant, read from the time
     * skeleton; an open-ended series is never deleted. Each deletion is a
     * normal change (sync token, journal). Logged once per run as
     * `object.retention` with the count, never with content.
     *
     * @return  int  Objects deleted.
     */
    public function deleteEndedBefore(int $calendarId, \DateTimeImmutable $beforeUtc): int;

    /**
     * Objects in any calendar whose blind index holds this address hash.
     *
     * @return  StoredObject[]
     */
    public function findByEmailHash(string $emailHash): array;

    /**
     * Calendars that hold objects without a usable e-mail index (`emails_indexed = 0`),
     * so a privacy request can name them instead of being silently incomplete.
     *
     * @return  int[]  Calendar ids.
     */
    public function calendarsWithUnindexedObjects(): array;

    public function syncToken(int $calendarId): int;

    /**
     * Changes since a token (RFC 6578). A null or unknown token yields the full
     * set as added; a token older than the journal's retention yields a 403
     * `valid-sync-token` condition through the exception code 403.
     */
    public function changesSince(int $calendarId, ?string $syncToken, ?int $limit): ChangeSet;

    /**
     * Decrypted time skeletons of the calendar, for busy times and the free/busy role.
     *
     * @return  iterable<array{uri: string, skeleton: string, booking_id: int|null}>
     */
    public function skeletons(int $calendarId): iterable;
}
