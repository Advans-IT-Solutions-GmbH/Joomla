<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\DeviceSession;
use Advans\Component\Appointments\Administrator\Helper\Team\StoredObject;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\CleanupServiceInterface;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\Forbidden;
use Advans\Component\Appointments\Vendor\Sabre\HTTP\RequestInterface;

defined('_JEXEC') or die;

/**
 * Everything one authenticated CalDAV request knows: the device session, the
 * services, the calendars the device sees and the calendar keys opened for this
 * request. Lives for one request; {@see wipe()} overwrites the keys at the end.
 *
 * @since  1.4.0
 */
final class DavContext
{
    /** Objects re-encrypted per calendar and request, so a sync never stalls. */
    private const REENCRYPT_BATCH = 25;

    /**
     * @var  array<string, DavCalendar>|null  By calendar URI.
     */
    private ?array $calendars = null;

    /**
     * @var  array<int, CalendarKeyring>
     */
    private array $keyrings = [];

    /**
     * @var  array<int, array<string, StoredObject>>  Free/busy URI to object, per calendar.
     */
    private array $freeBusyMaps = [];

    /**
     * @var  array<int, true>  Calendars whose empty view this request served.
     */
    private array $emptyServed = [];

    private bool $homeHidden = false;

    private ?string $busySummary = null;

    private ?RequestInterface $request = null;

    /**
     * @param   \Closure(int): string           $busySummary  Text of a redacted busy block in the user's language.
     * @param   \Closure(\Throwable): void|null  $onError      Records an unexpected error without failing the request.
     */
    public function __construct(
        public readonly DavServices $services,
        public readonly DeviceSession $session,
        private readonly \Closure $busySummaryProvider,
        private readonly ?\Closure $onError = null
    ) {
    }

    public function principal(): string
    {
        return 'principals/' . $this->session->principal();
    }

    public function setRequest(RequestInterface $request): void
    {
        $this->request = $request;
    }

    public function request(): ?RequestInterface
    {
        return $this->request;
    }

    /**
     * Whether calendar data belongs into object lists (a REPORT), so a query
     * decrypts each object once instead of fetching it again.
     */
    public function wantsData(): bool
    {
        return $this->request !== null && $this->request->getMethod() === 'REPORT';
    }

    /**
     * The calendars of the calendar home, by URI, in the view the clean-up
     * service decides: active device → every effective access that is not
     * hidden; clean-up «empty» → the calendars the device knew, without objects;
     * clean-up «hidden» → none.
     *
     * @return  array<string, DavCalendar>
     */
    public function calendars(): array
    {
        if ($this->calendars !== null) {
            return $this->calendars;
        }

        $this->calendars = [];
        $services = $this->services;

        try {
            if ($this->session->state === TeamStates::DEVICE_CLEANUP_HIDDEN) {
                $this->homeHidden = true;

                return $this->calendars;
            }

            if ($this->session->state === TeamStates::DEVICE_CLEANUP_EMPTY) {
                foreach ($services->cleanup->calendarsInCleanup($this->session) as $calendarId) {
                    $row = $services->calendars->get((int) $calendarId);

                    if ($row === null || !isset($row->id, $row->uri)) {
                        continue;
                    }

                    $calendar = new DavCalendar(
                        (int) $row->id,
                        (string) $row->uri,
                        (string) ($row->title ?? ''),
                        (string) ($row->colour ?? ''),
                        null,
                        (int) ($row->synctoken ?? 0),
                        $this->roleInCleanup((int) $row->id),
                        CleanupServiceInterface::VIEW_EMPTY,
                        false
                    );
                    $this->calendars[$calendar->uri] = $calendar;
                }

                return $this->calendars;
            }

            if (!$this->session->isActive()) {
                return $this->calendars;
            }

            foreach ($services->grants->calendarsFor($this->session->userId) as $access) {
                if ($access->userId !== $this->session->userId) {
                    continue;
                }

                $view = $services->cleanup->viewFor($this->session, $access->calendarId);

                if ($view === CleanupServiceInterface::VIEW_HIDDEN) {
                    $this->homeHidden = true;
                    continue;
                }

                $props = $access->clientProps;
                $colour = isset($props['colour']) && \is_string($props['colour']) ? $props['colour'] : $access->colour;
                $order = isset($props['order']) && \is_int($props['order']) ? $props['order'] : null;

                $calendar = new DavCalendar(
                    $access->calendarId,
                    $access->calendarUri,
                    $access->title,
                    $colour,
                    $order,
                    $access->synctoken,
                    $access->role,
                    $view === CleanupServiceInterface::VIEW_EMPTY ? CleanupServiceInterface::VIEW_EMPTY : CleanupServiceInterface::VIEW_NORMAL,
                    true
                );
                $this->calendars[$calendar->uri] = $calendar;
            }
        } catch (TeamException $e) {
            $this->calendars = [];

            throw DavErrors::fromTeam($e);
        }

        return $this->calendars;
    }

    public function calendarById(int $calendarId): ?DavCalendar
    {
        foreach ($this->calendars() as $calendar) {
            if ($calendar->id === $calendarId) {
                return $calendar;
            }
        }

        return null;
    }

    public function calendarByUri(string $uri): ?DavCalendar
    {
        return $this->calendars()[$uri] ?? null;
    }

    /**
     * The calendar keys of a key-holding calendar for this request. Opening them
     * also completes pending key wraps and re-encrypts a batch of sealed or
     * older objects (specification 5.4 and 5.5); a failure there never fails
     * the request.
     *
     * @throws  Forbidden  When the device holds no key for the calendar.
     */
    public function keyring(DavCalendar $calendar): CalendarKeyring
    {
        if (isset($this->keyrings[$calendar->id])) {
            return $this->keyrings[$calendar->id];
        }

        if (!$calendar->readsContent() || $this->session->keys === null) {
            throw new Forbidden('Not allowed.');
        }

        try {
            $keyring = $this->services->keys->openKeyring($calendar->id, $this->session->keys);
        } catch (TeamException $e) {
            throw DavErrors::fromTeam($e);
        }

        if ($keyring === null || $keyring->calendarId() !== $calendar->id) {
            throw new Forbidden('Not allowed.');
        }

        $this->keyrings[$calendar->id] = $keyring;

        $this->quietly(fn () => $this->services->keys->completePendingWraps($keyring));
        $this->quietly(fn () => $this->services->objects->reencrypt($keyring, self::REENCRYPT_BATCH));

        return $keyring;
    }

    /**
     * The address of an object in the free/busy view. The original address
     * often carries the UID, which a free/busy grantee must not learn, so the
     * view uses a keyed hash of calendar and address instead.
     */
    public function freeBusyUri(int $calendarId, string $uri): string
    {
        $mac = hash_hmac(
            'sha256',
            'com_appointments/freebusy-uri/v1|' . $calendarId . "\0" . $uri,
            $this->services->keyProvider->key(KeyProvider::PURPOSE_TEAM_UID)
        );

        return 'fb-' . substr($mac, 0, 40) . '.ics';
    }

    /**
     * @return  array<string, StoredObject>  Free/busy URI to stored object.
     */
    public function freeBusyMap(DavCalendar $calendar): array
    {
        if (!isset($this->freeBusyMaps[$calendar->id])) {
            $map = [];

            try {
                foreach ($this->services->objects->all($calendar->id) as $object) {
                    $map[$this->freeBusyUri($calendar->id, $object->uri)] = $object;
                }
            } catch (TeamException $e) {
                throw DavErrors::fromTeam($e);
            }

            $this->freeBusyMaps[$calendar->id] = $map;
        }

        return $this->freeBusyMaps[$calendar->id];
    }

    public function busySummary(): string
    {
        return $this->busySummary ??= ($this->busySummaryProvider)($this->session->userId);
    }

    public function markEmptyServed(int $calendarId): void
    {
        $this->emptyServed[$calendarId] = true;
    }

    /**
     * @return  int[]
     */
    public function emptyServed(): array
    {
        return array_keys($this->emptyServed);
    }

    /**
     * Whether a calendar was left out of the home because it is hidden.
     */
    public function homeHidden(): bool
    {
        return $this->homeHidden;
    }

    /**
     * Runs a maintenance step that must never fail the request.
     */
    public function quietly(\Closure $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            if ($this->onError !== null) {
                ($this->onError)($e);
            }
        }
    }

    public function wipe(): void
    {
        foreach ($this->keyrings as $keyring) {
            $keyring->wipe();
        }

        $this->keyrings = [];
        $this->freeBusyMaps = [];
    }

    /**
     * The role the user held on a calendar of the clean-up, also when the grant
     * is suspended; null when no grant row is left.
     */
    private function roleInCleanup(int $calendarId): ?CalendarRole
    {
        foreach ($this->services->grants->grantsOf($calendarId) as $grant) {
            if ((int) ($grant->user_id ?? 0) === $this->session->userId) {
                return CalendarRole::tryFrom((int) ($grant->role ?? 0));
            }
        }

        return null;
    }
}
