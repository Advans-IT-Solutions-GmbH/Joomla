<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Administrator\Helper\Team\StoredObject;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Service\Team\CalendarObjectStore;
use Advans\Component\Appointments\Vendor\Sabre\CalDAV\Backend\AbstractBackend;
use Advans\Component\Appointments\Vendor\Sabre\CalDAV\Backend\SyncSupport;
use Advans\Component\Appointments\Vendor\Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\Forbidden;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\NotFound;
use Advans\Component\Appointments\Vendor\Sabre\DAV\PropPatch;

defined('_JEXEC') or die;

/**
 * sabre/dav calendar backend of the internet calendars. It decides nothing
 * about access itself: the calendars come from the grant and clean-up services
 * through {@see DavContext}, contents from the object store and the key
 * service. Per role (specification 7):
 *
 * - free/busy: read-only, every object replaced by a redacted busy block under
 *   an opaque address, built from the time skeleton, without a calendar key;
 * - read: read-only, decrypted with the calendar key;
 * - write and manage: read and write;
 * - clean-up «empty»: no objects, read-only, every known address reported
 *   deleted on sync, CTag `<token>-c`.
 *
 * Creating, deleting or sharing calendars is refused (403); PROPPATCH changes
 * only the user's own colour and order.
 *
 * @since  1.4.0
 */
final class CalendarBackend extends AbstractBackend implements SyncSupport
{
    private const NS_APPLE = '{http://apple.com/ns/ical/}';
    private const COLOUR = self::NS_APPLE . 'calendar-color';
    private const ORDER = self::NS_APPLE . 'calendar-order';

    public function __construct(private readonly DavContext $context)
    {
    }

    public function getCalendarsForUser($principalUri)
    {
        if (trim((string) $principalUri, '/') !== $this->context->principal()) {
            return [];
        }

        $list = [];

        foreach ($this->context->calendars() as $calendar) {
            $info = [
                'id'                                                              => $calendar->id,
                'uri'                                                             => $calendar->uri,
                'principaluri'                                                    => $this->context->principal(),
                '{DAV:}displayname'                                               => $calendar->title,
                '{http://calendarserver.org/ns/}getctag'                          => $calendar->token(),
                '{http://sabredav.org/ns}sync-token'                              => $calendar->token(),
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT']),
                '{http://sabredav.org/ns}read-only'                               => !$calendar->isWritable(),
            ];

            if (preg_match('/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/', $calendar->colour) === 1) {
                $info[self::COLOUR] = $calendar->colour;
            }

            if ($calendar->order !== null) {
                $info[self::ORDER] = (string) $calendar->order;
            }

            $list[] = $info;
        }

        return $list;
    }

    public function createCalendar($principalUri, $calendarUri, array $properties)
    {
        throw new Forbidden('Calendars are created in the administrator only.');
    }

    public function deleteCalendar($calendarId)
    {
        throw new Forbidden('Calendars are deleted in the administrator only.');
    }

    /**
     * Only the user's own display colour and order; every other property stays
     * unhandled and is answered with 403.
     */
    public function updateCalendar($calendarId, PropPatch $propPatch)
    {
        $calendar = $this->calendar($calendarId);

        $propPatch->handle([self::COLOUR, self::ORDER], function (array $mutations) use ($calendar): bool {
            if ($calendar->isEmptyView()) {
                return false;
            }

            $props = [];

            foreach ($mutations as $name => $value) {
                if ($name === self::COLOUR) {
                    if ($value !== null && (!\is_string($value) || preg_match('/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/', $value) !== 1)) {
                        return false;
                    }

                    $props['colour'] = $value === null ? null : strtoupper($value);
                } elseif ($name === self::ORDER) {
                    $text = $value === null ? null : trim((string) $value);

                    if ($text !== null && (preg_match('/^\d{1,4}$/', $text) !== 1)) {
                        return false;
                    }

                    $props['order'] = $text === null ? null : (int) $text;
                }
            }

            try {
                $this->context->services->grants->setClientProps($this->context->session->userId, $calendar->id, $props);
            } catch (TeamException $e) {
                return false;
            }

            return true;
        });
    }

    public function getCalendarObjects($calendarId)
    {
        $calendar = $this->calendar($calendarId);

        if ($calendar->isEmptyView()) {
            $this->context->markEmptyServed($calendar->id);

            return [];
        }

        $rows = [];

        if ($calendar->isFreeBusy()) {
            foreach ($this->context->freeBusyMap($calendar) as $uri => $object) {
                $row = $this->freeBusyRow($calendar, $uri, $object);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        $withData = $this->context->wantsData();

        // A listing reads no payload; a REPORT decrypts every object once.
        $objects = $this->store(fn () => $withData ? $this->context->services->objects->all($calendar->id) : $this->context->services->objects->listing($calendar->id));

        foreach ($objects as $object) {
            $row = $this->row($calendar, $object, $withData);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function getCalendarObject($calendarId, $objectUri)
    {
        $calendar = $this->calendar($calendarId);
        $uri = (string) $objectUri;

        if ($calendar->isEmptyView()) {
            return null;
        }

        if ($calendar->isFreeBusy()) {
            $object = $this->context->freeBusyMap($calendar)[$uri] ?? null;

            return $object === null ? null : $this->freeBusyRow($calendar, $uri, $object);
        }

        $object = $this->store(fn () => $this->context->services->objects->get($calendar->id, $uri));

        return $object === null ? null : $this->row($calendar, $object, true);
    }

    public function getMultipleCalendarObjects($calendarId, array $uris)
    {
        $calendar = $this->calendar($calendarId);
        $uris = array_values(array_unique(array_map('strval', $uris)));

        if ($calendar->isEmptyView() || $uris === []) {
            return [];
        }

        $rows = [];

        if ($calendar->isFreeBusy()) {
            $map = $this->context->freeBusyMap($calendar);

            foreach ($uris as $uri) {
                if (isset($map[$uri]) && ($row = $this->freeBusyRow($calendar, $uri, $map[$uri])) !== null) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        foreach ($this->store(fn () => $this->context->services->objects->many($calendar->id, $uris)) as $object) {
            $row = $this->row($calendar, $object, true);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function createCalendarObject($calendarId, $objectUri, $calendarData)
    {
        return $this->write($this->calendar($calendarId), (string) $objectUri, $calendarData);
    }

    public function updateCalendarObject($calendarId, $objectUri, $calendarData)
    {
        return $this->write($this->calendar($calendarId), (string) $objectUri, $calendarData);
    }

    public function deleteCalendarObject($calendarId, $objectUri)
    {
        $calendar = $this->calendar($calendarId);

        if (!$calendar->isWritable()) {
            throw new Forbidden('This calendar is read-only for you.');
        }

        [$ifMatch] = $this->preconditions($calendar, (string) $objectUri);

        try {
            $this->context->services->objects->delete($calendar->id, (string) $objectUri, $ifMatch, $this->context->session->deviceId);
        } catch (TeamException $e) {
            throw DavErrors::fromTeam($e, 'delete');
        }
    }

    public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null)
    {
        $calendar = $this->calendar($calendarId);
        $token = $syncToken === null ? '' : (string) $syncToken;
        $limit = $limit === null ? null : max(1, (int) $limit);

        if ($calendar->isEmptyView()) {
            $this->context->markEmptyServed($calendar->id);

            return [
                'syncToken' => $calendar->token(),
                'added'     => [],
                'modified'  => [],
                'deleted'   => $token === '' ? [] : $this->knownToDevice($calendar, $token),
            ];
        }

        // A token of the empty view (the device was in clean-up before) starts over.
        if (str_ends_with($token, '-c')) {
            $token = '';
        }

        try {
            $changes = $this->context->services->objects->changesSince($calendar->id, $token === '' ? null : $token, $limit)->toSabre();
        } catch (TeamException $e) {
            throw DavErrors::fromTeam($e, 'sync');
        }

        if ($calendar->isFreeBusy()) {
            foreach (['added', 'modified', 'deleted'] as $key) {
                $changes[$key] = array_map(fn (string $uri): string => $this->context->freeBusyUri($calendar->id, $uri), $changes[$key]);
            }
        }

        return $changes;
    }

    /**
     * Every address the device may hold for a calendar of the clean-up: the
     * objects that existed at its token (current ones not added since, plus
     * the ones deleted since), or every current object when the token is no
     * longer in the journal. In the form of the role the device had at its first
     * synchronisation of the calendar: the free/busy addresses for free/busy,
     * the originals for a key-holding role. When no role is recorded, only the
     * free/busy addresses, so an original address (often the UID) never reaches
     * a device that may not have known it.
     *
     * @return  string[]
     */
    private function knownToDevice(DavCalendar $calendar, string $token): array
    {
        try {
            $current = array_map(static fn ($object): string => $object->uri, $this->context->services->objects->listing($calendar->id));
        } catch (TeamException $e) {
            throw DavErrors::fromTeam($e);
        }

        $since = (string) preg_replace('/-c$/', '', $token);
        $known = $current;

        if (preg_match('/^\d{1,19}$/', $since) === 1) {
            try {
                $changes = $this->context->services->objects->changesSince($calendar->id, $since, null);
                $known = array_merge(array_diff($current, $changes->added), $changes->deleted);
            } catch (TeamException $e) {
                // A token beyond the journal: the current objects are what the device can still hold.
            }
        }

        $reported = [];

        foreach (array_unique($known) as $uri) {
            $reported[] = $calendar->role !== null && !$calendar->isFreeBusy()
                ? $uri
                : $this->context->freeBusyUri($calendar->id, $uri);
        }

        return array_values(array_unique($reported));
    }

    /**
     * @param   resource|string  $data
     */
    private function write(DavCalendar $calendar, string $uri, $data): string
    {
        if (!$calendar->isWritable()) {
            throw new Forbidden('This calendar is read-only for you.');
        }

        if (!CalendarObjectStore::validUri($uri)) {
            throw DavErrors::badRequest();
        }

        if (\is_resource($data)) {
            $data = stream_get_contents($data, DavGateway::MAX_BODY + 1);
        }

        if (!\is_string($data)) {
            throw DavErrors::badRequest();
        }

        if (\strlen($data) > DavGateway::MAX_BODY) {
            throw new RequestTooLarge('The calendar object is too large.');
        }

        $keyring = $this->context->keyring($calendar);
        [$ifMatch, $ifNoneMatch] = $this->preconditions($calendar, $uri);

        try {
            $etag = $this->context->services->objects->put($keyring, $uri, $data, $ifMatch, $ifNoneMatch, $this->context->session->deviceId);
        } catch (TeamException $e) {
            throw DavErrors::fromTeam($e, 'put');
        }

        return '"' . $etag . '"';
    }

    /**
     * The conditions of the current request for the store's atomic check. sabre
     * has checked them already against the current state; the store checks
     * again inside its transaction, so two concurrent writes cannot both win.
     * A list of ETags in If-Match is narrowed to the current one (sabre accepted
     * the request, so it is in the list), and the store checks exactly that.
     *
     * @return  array{0: string|null, 1: bool}  If-Match (one ETag or `*`), If-None-Match `*`.
     */
    private function preconditions(DavCalendar $calendar, string $uri): array
    {
        $request = $this->context->request();

        if ($request === null) {
            return [null, false];
        }

        $ifMatch = trim((string) $request->getHeader('If-Match'));
        $ifNoneMatch = trim((string) $request->getHeader('If-None-Match')) === '*';

        if ($ifMatch === '') {
            return [null, $ifNoneMatch];
        }

        if (str_contains($ifMatch, ',')) {
            $current = $this->store(fn () => $this->context->services->objects->get($calendar->id, $uri));
            $listed = array_map(static fn (string $tag): string => trim((string) preg_replace('/^W\//', '', trim($tag)), '"'), explode(',', $ifMatch));

            // Not in the list (any more): a value no object ever has, so the store refuses with 412.
            return [$current !== null && \in_array($current->etag, $listed, true) ? $current->etag : '"-"', $ifNoneMatch];
        }

        return [$ifMatch === '*' ? '*' : trim(preg_replace('/^W\//', '', $ifMatch) ?? '', '"'), $ifNoneMatch];
    }

    private function calendar(mixed $calendarId): DavCalendar
    {
        $calendar = \is_int($calendarId) ? $this->context->calendarById($calendarId) : null;

        if ($calendar === null) {
            throw new NotFound('Not found.');
        }

        return $calendar;
    }

    /**
     * @return  array<string, mixed>|null  Null when the object cannot be decrypted.
     */
    private function row(DavCalendar $calendar, StoredObject $object, bool $withData): ?array
    {
        $row = [
            'id'           => $object->id,
            'uri'          => $object->uri,
            'etag'         => '"' . $object->etag . '"',
            'size'         => $object->size,
            'lastmodified' => $object->modifiedUtc->getTimestamp(),
            'calendarid'   => $calendar->id,
            'component'    => 'vevent',
        ];

        if ($withData) {
            $keyring = $this->context->keyring($calendar);

            try {
                $data = $this->context->services->keys->decryptObject($keyring, $object);
            } catch (TeamException $e) {
                $data = null;
            }

            if ($data === null) {
                return null;
            }

            $row['calendardata'] = $data;
            $row['size'] = \strlen($data);
        }

        return $row;
    }

    /**
     * The redacted busy block of one object: the same times, SUMMARY in the
     * user's language, CLASS CONFIDENTIAL, a UID derived from the opaque address.
     *
     * @return  array<string, mixed>|null
     */
    private function freeBusyRow(DavCalendar $calendar, string $freeBusyUri, StoredObject $object): ?array
    {
        try {
            $skeleton = $this->context->services->keys->openTiming($calendar->id, $object->uri, $object->timing);
            $data = $skeleton === null ? null : $this->context->services->timing->redacted($skeleton, $freeBusyUri, $this->context->busySummary());
        } catch (TeamException $e) {
            $data = null;
        }

        if ($data === null || $data === '') {
            return null;
        }

        return [
            'uri'          => $freeBusyUri,
            'etag'         => '"' . substr(hash('sha256', 'freebusy|' . $object->etag), 0, 32) . '"',
            'size'         => \strlen($data),
            'lastmodified' => $object->modifiedUtc->getTimestamp(),
            'calendarid'   => $calendar->id,
            'component'    => 'vevent',
            'calendardata' => $data,
        ];
    }

    /**
     * @template T
     *
     * @param   \Closure(): T  $call
     *
     * @return  T
     */
    private function store(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (TeamException $e) {
            throw DavErrors::fromTeam($e);
        }
    }
}
