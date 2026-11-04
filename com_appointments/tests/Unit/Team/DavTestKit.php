<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Shared test doubles of the CalDAV tests (Dav*Test.php): autoloading of the
 * team service contracts and the CalDAV plugin, an in-memory object store with
 * the store's own rules, and a world of services configured per test. The
 * object store keeps plaintext as "payload" and "timing"; the key double hands
 * them back, so the tests see exactly what the server serves.
 */

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\AuditVerification;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarAccess;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKey;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\ChangeSet;
use Advans\Component\Appointments\Administrator\Helper\Team\DeviceSession;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\StoredObject;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Helper\Team\VendorAutoloader;
use Advans\Component\Appointments\Administrator\Service\Team\AuditServiceInterface;
use Advans\Component\Appointments\Administrator\Service\Team\CalendarObjectStore;
use Advans\Component\Appointments\Administrator\Service\Team\CalendarObjectStoreInterface;
use Advans\Component\Appointments\Administrator\Service\Team\CleanupServiceInterface;
use Advans\Component\Appointments\Administrator\Service\Team\LockoutServiceInterface;
use Advans\Plugin\System\AppointmentsDav\Dav\DavAddress;
use Advans\Plugin\System\AppointmentsDav\Dav\DavEnvironment;
use Advans\Plugin\System\AppointmentsDav\Dav\DavGateway;
use Advans\Plugin\System\AppointmentsDav\Dav\DavServices;
use Joomla\CMS\Application\CMSApplicationInterface;

spl_autoload_register(static function (string $class): void {
    $roots = [
        'Advans\\Component\\Appointments\\Administrator\\Service\\' => dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/',
        'Advans\\Plugin\\System\\AppointmentsDav\\'                => dirname(__DIR__, 3) . '/plugins/system/appointmentsdav/src/',
    ];

    foreach ($roots as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        }
    }
});

// LockoutServiceInterface names the Joomla application in a parameter type
// only; PHP never loads such a type, so no Joomla class is needed here.
VendorAutoloader::register();

/**
 * In-memory object store with the rules of the real one (URI, inspect, ETag,
 * UID conflict, one token per change, journal collapse).
 */
final class DavMemoryStore implements CalendarObjectStoreInterface
{
    /** @var array<int, int> */
    public array $tokens = [];

    /** @var array<int, array<string, array{id: int, etag: string, ics: string, uid: string, booking: int|null}>> */
    public array $objects = [];

    /** @var array<int, array<int, array{synctoken: int, uri: string, operation: int}>> */
    public array $journal = [];

    /** @var array<int, int> Journal rows pruned below this token. */
    public array $oldest = [];

    public int $reencrypted = 0;

    public int $gets = 0;

    private int $nextId = 1;

    public function addCalendar(int $calendarId, int $token = 1): void
    {
        $this->tokens[$calendarId] = $token;
        $this->objects[$calendarId] = [];
        $this->journal[$calendarId] = [];
    }

    public function seed(int $calendarId, string $uri, string $ics, ?int $bookingId = null): string
    {
        $uid = CalendarObjectStore::inspect($ics);
        $etag = bin2hex(random_bytes(16));
        $isNew = !isset($this->objects[$calendarId][$uri]);
        $this->objects[$calendarId][$uri] = ['id' => $this->nextId++, 'etag' => $etag, 'ics' => $ics, 'uid' => $uid, 'booking' => $bookingId];
        $this->change($calendarId, $uri, $isNew ? TeamStates::CHANGE_ADDED : TeamStates::CHANGE_MODIFIED);

        return $etag;
    }

    public function all(int $calendarId): array
    {
        return array_values(array_map(fn (string $uri) => $this->object($calendarId, $uri), array_keys($this->objects[$calendarId] ?? [])));
    }

    public function listing(int $calendarId): array
    {
        return array_map(static fn (StoredObject $o) => new StoredObject($o->id, $o->calendarId, $o->uri, $o->etag, $o->size, $o->scheme, $o->keyVersion, '', $o->timing, $o->bookingId, $o->modifiedUtc), $this->all($calendarId));
    }

    public function many(int $calendarId, array $uris): array
    {
        return array_values(array_filter(array_map(fn (string $uri) => isset($this->objects[$calendarId][$uri]) ? $this->object($calendarId, $uri) : null, $uris)));
    }

    public function get(int $calendarId, string $uri): ?StoredObject
    {
        $this->gets++;

        return isset($this->objects[$calendarId][$uri]) ? $this->object($calendarId, $uri) : null;
    }

    public function put(CalendarKeyring $keyring, string $uri, string $ics, ?string $ifMatch, bool $ifNoneMatch, ?int $actorDeviceId): string
    {
        $calendarId = $keyring->calendarId();

        if (!CalendarObjectStore::validUri($uri)) {
            throw new TeamException('X', 400);
        }

        $uid = CalendarObjectStore::inspect($ics);
        $existing = $this->objects[$calendarId][$uri] ?? null;

        if (($ifNoneMatch && $existing !== null) || ($ifMatch !== null && !CalendarObjectStore::etagMatches($ifMatch, $existing['etag'] ?? null))) {
            throw new TeamException('X', 412);
        }

        foreach ($this->objects[$calendarId] as $other => $object) {
            if ($other !== $uri && $object['uid'] === $uid) {
                throw new TeamException('X', 409);
            }
        }

        if ($existing !== null && $existing['uid'] !== $uid) {
            throw new TeamException('X', 409);
        }

        return $this->seed($calendarId, $uri, $ics, $existing['booking'] ?? null);
    }

    public function putSealed(int $calendarId, string $ics, int $bookingId): string
    {
        return $this->seed($calendarId, 'booking-' . $bookingId . '.ics', $ics, $bookingId);
    }

    public function delete(int $calendarId, string $uri, ?string $ifMatch, ?int $actorDeviceId): void
    {
        $existing = $this->objects[$calendarId][$uri] ?? null;

        if ($existing === null) {
            throw new TeamException('X', 404);
        }

        if ($ifMatch !== null && !CalendarObjectStore::etagMatches($ifMatch, $existing['etag'])) {
            throw new TeamException('X', 412);
        }

        unset($this->objects[$calendarId][$uri]);
        $this->change($calendarId, $uri, TeamStates::CHANGE_DELETED);
    }

    public function deleteForBooking(int $bookingId): int
    {
        return 0;
    }

    public function reencrypt(CalendarKeyring $keyring, int $limit): int
    {
        $this->reencrypted++;

        return 0;
    }

    public function deleteEndedBefore(int $calendarId, \DateTimeImmutable $beforeUtc): int
    {
        return 0;
    }

    public function syncToken(int $calendarId): int
    {
        return $this->tokens[$calendarId] ?? throw new TeamException('X', 404);
    }

    public function changesSince(int $calendarId, ?string $syncToken, ?int $limit): ChangeSet
    {
        $current = $this->syncToken($calendarId);

        if ($syncToken === null || !ctype_digit($syncToken) || (int) $syncToken > $current) {
            return new ChangeSet((string) $current, array_keys($this->objects[$calendarId]), [], []);
        }

        if ((int) $syncToken < ($this->oldest[$calendarId] ?? 0)) {
            throw new TeamException('X', 403);
        }

        $rows = array_values(array_filter($this->journal[$calendarId], static fn (array $r) => $r['synctoken'] > (int) $syncToken));
        $token = $current;

        if ($limit !== null && count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $token = end($rows)['synctoken'];
        }

        $changes = CalendarObjectStore::collapse($rows);

        return new ChangeSet((string) $token, $changes['added'], $changes['modified'], $changes['deleted']);
    }

    public function skeletons(int $calendarId): iterable
    {
        foreach ($this->objects[$calendarId] as $uri => $object) {
            yield ['uri' => $uri, 'skeleton' => $object['ics'], 'booking_id' => $object['booking']];
        }
    }

    private function change(int $calendarId, string $uri, int $operation): void
    {
        $token = ++$this->tokens[$calendarId];
        $this->journal[$calendarId][] = ['synctoken' => $token, 'uri' => $uri, 'operation' => $operation];
    }

    private function object(int $calendarId, string $uri): StoredObject
    {
        $o = $this->objects[$calendarId][$uri];

        return new StoredObject($o['id'], $calendarId, $uri, $o['etag'], strlen($o['ics']), TeamStates::SCHEME_CALENDAR_KEY, 1, $o['ics'], $o['ics'], $o['booking'], new \DateTimeImmutable('2026-11-04 10:00:00', new \DateTimeZone('UTC')));
    }
}

/**
 * Lock-out double: counts calls, locks on demand.
 */
final class DavLockout implements LockoutServiceInterface
{
    public int $retryAfter = 0;

    public bool $fail = false;

    /** @var string[] */
    public array $calls = [];

    public function retryAfter(string $username, string $ip): int
    {
        if ($this->fail) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
        }

        $this->calls[] = 'retry:' . $username;

        return $this->retryAfter;
    }

    public function guardLogin(CMSApplicationInterface $app): void
    {
    }

    public function wasRefused(string $username, string $ip): bool
    {
        return false;
    }

    public function recordFailure(string $username, string $ip): void
    {
        $this->calls[] = 'failure:' . $username;
    }

    public function recordSuccess(string $username, string $ip): void
    {
        $this->calls[] = 'success:' . $username;
    }

    public function purge(\DateTimeImmutable $nowUtc): int
    {
        return 0;
    }
}

/**
 * Audit double: keeps every event with its context.
 */
final class DavAudit implements AuditServiceInterface
{
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    public array $events = [];

    public function log(string $event, array $context = []): void
    {
        $this->events[] = [$event, $context];
    }

    public function verify(): AuditVerification
    {
        throw new \LogicException('not used');
    }

    public function headHash(): string
    {
        return '';
    }

    public function prune(\DateTimeImmutable $nowUtc): int
    {
        return 0;
    }

    /**
     * @return  string[]
     */
    public function names(): array
    {
        return array_map(static fn (array $e) => $e[0], $this->events);
    }
}

/**
 * One configurable world for a test: users with app passwords, calendars,
 * grants, device states, clean-up views and second factors.
 */
final class DavWorld
{
    public DavMemoryStore $store;
    public DavLockout $lockout;
    public DavAudit $audit;

    /** @var array<string, array{password: string, session: DeviceSession}> By user name. */
    public array $devices = [];

    /** @var array<int, array<int, CalendarRole>> User id → calendar id → role. */
    public array $grants = [];

    /** @var array<int, array{uri: string, title: string, colour: string}> */
    public array $calendars = [];

    /** @var array<int, true> Users with a second factor. */
    public array $mfa = [];

    /** @var array<int, string> Calendar id → clean-up view for active devices. */
    public array $views = [];

    /** @var array<int, array{role: int, synctoken: int}> What the clean-up lists for a device in clean-up. */
    public array $inCleanup = [];

    /** @var array<int, array<string, mixed>> */
    public array $clientProps = [];

    /** @var array<int, int|null> Calendar ids passed to touch(). */
    public array $touched = [];

    /** @var array<int, int|null> Roles passed to touch(). */
    public array $touchedRoles = [];

    /** @var array<int, int|null> Sync tokens passed to touch(). */
    public array $touchedTokens = [];

    /** @var int[] */
    public array $delivered = [];

    public int $homeSeen = 0;

    public int $authenticateCalls = 0;

    public int $keyringOpened = 0;

    public bool $notReady = false;

    public function __construct(private readonly \PHPUnit\Framework\TestCase $test)
    {
        $this->store = new DavMemoryStore();
        $this->lockout = new DavLockout();
        $this->audit = new DavAudit();
    }

    public function calendar(int $id, string $title = 'Team'): string
    {
        $uri = bin2hex(random_bytes(16));
        $this->calendars[$id] = ['uri' => $uri, 'title' => $title, 'colour' => '#3366CC'];
        $this->store->addCalendar($id);

        return $uri;
    }

    /**
     * @return  string  The app password in display form.
     */
    public function device(int $userId, string $username, int $state = TeamStates::DEVICE_ACTIVE, bool $mfa = true): string
    {
        $password = \Advans\Component\Appointments\Administrator\Helper\Team\AppPassword::generate()['display'];
        $keys = $state === TeamStates::DEVICE_ACTIVE ? KeyContext::create($userId, 1, random_bytes(32), random_bytes(32)) : null;
        $this->devices[strtolower($username)] = ['password' => $password, 'session' => new DeviceSession(100 + $userId, $userId, $username, $state, $keys)];

        if ($mfa) {
            $this->mfa[$userId] = true;
        }

        return $password;
    }

    public function grant(int $userId, int $calendarId, CalendarRole $role): void
    {
        $this->grants[$userId][$calendarId] = $role;
    }

    public function services(): DavServices
    {
        $test = $this->test;
        $world = $this;
        $notReady = static function () use ($world): void {
            if ($world->notReady) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
            }
        };

        $devices = $test->getMockBuilder(\Advans\Component\Appointments\Administrator\Service\Team\DeviceServiceInterface::class)->getMock();
        $devices->method('authenticate')->willReturnCallback(function (string $username, string $password) use ($world, $notReady): ?DeviceSession {
            $notReady();
            $world->authenticateCalls++;
            $device = $world->devices[strtolower($username)] ?? null;

            return $device !== null && hash_equals($device['password'], $password) ? $device['session'] : null;
        });
        $devices->method('touch')->willReturnCallback(function (DeviceSession $session, ?int $calendarId, ?int $role = null, ?int $syncToken = null) use ($world): void {
            $world->touched[] = $calendarId;
            $world->touchedRoles[] = $role;
            $world->touchedTokens[] = $syncToken;
        });

        $grants = $test->getMockBuilder(\Advans\Component\Appointments\Administrator\Service\Team\GrantServiceInterface::class)->getMock();
        $grants->method('hasSecondFactor')->willReturnCallback(fn (int $userId): bool => isset($world->mfa[$userId]));
        $grants->method('calendarsFor')->willReturnCallback(function (int $userId) use ($world): array {
            $list = [];

            foreach ($world->grants[$userId] ?? [] as $calendarId => $role) {
                $c = $world->calendars[$calendarId];
                $list[] = new CalendarAccess($calendarId, $c['uri'], $userId, $role, 'team', $c['title'], $c['colour'], $world->store->tokens[$calendarId], $world->clientProps[$calendarId] ?? []);
            }

            return $list;
        });
        $grants->method('grantsOf')->willReturnCallback(function (int $calendarId) use ($world): array {
            $rows = [];

            foreach ($world->grants as $userId => $calendars) {
                if (isset($calendars[$calendarId])) {
                    $rows[] = (object) ['user_id' => $userId, 'role' => $calendars[$calendarId]->value, 'state' => TeamStates::GRANT_SUSPENDED];
                }
            }

            return $rows;
        });
        $grants->method('setClientProps')->willReturnCallback(function (int $userId, int $calendarId, array $props) use ($world): void {
            $world->clientProps[$calendarId] = array_filter(array_merge($world->clientProps[$calendarId] ?? [], $props), static fn ($v) => $v !== null);
        });

        $calendars = $test->getMockBuilder(\Advans\Component\Appointments\Administrator\Service\Team\CalendarServiceInterface::class)->getMock();
        $calendars->method('get')->willReturnCallback(function (int $id) use ($world): ?object {
            $c = $world->calendars[$id] ?? null;

            return $c === null ? null : (object) ['id' => $id, 'uri' => $c['uri'], 'title' => $c['title'], 'colour' => $c['colour'], 'synctoken' => $world->store->tokens[$id]];
        });

        $keys = $test->getMockBuilder(\Advans\Component\Appointments\Administrator\Service\Team\KeyServiceInterface::class)->getMock();
        $keys->method('openKeyring')->willReturnCallback(function (int $calendarId, KeyContext $context) use ($world): ?CalendarKeyring {
            $world->keyringOpened++;
            $role = $world->grants[$context->userId()][$calendarId] ?? null;

            return $role !== null && $role->holdsKey() ? new CalendarKeyring($calendarId, 1, [CalendarKey::create($calendarId, 1, random_bytes(32))]) : null;
        });
        $keys->method('decryptObject')->willReturnCallback(static fn (CalendarKeyring $ring, StoredObject $object): ?string => $ring->calendarId() === $object->calendarId ? $object->payload : null);
        $keys->method('openTiming')->willReturnCallback(static fn (int $calendarId, string $uri, string $timing): ?string => $timing);
        $keys->method('completePendingWraps')->willReturn(0);

        $timing = $test->getMockBuilder(\Advans\Component\Appointments\Administrator\Service\Team\TimingIndexInterface::class)->getMock();
        $timing->method('redacted')->willReturnCallback(static function (string $skeleton, string $seed, string $summary): string {
            preg_match('/DTSTART[^:]*:(\S+)/', $skeleton, $start);
            preg_match('/DTEND[^:]*:(\S+)/', $skeleton, $end);

            return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:" . sha1($seed) . "\r\nDTSTAMP:20261104T100000Z\r\nDTSTART:" . ($start[1] ?? '') . "\r\nDTEND:" . ($end[1] ?? '') . "\r\nSUMMARY:{$summary}\r\nCLASS:CONFIDENTIAL\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        });

        $cleanup = $test->getMockBuilder(CleanupServiceInterface::class)->getMock();
        $cleanup->method('viewFor')->willReturnCallback(fn (DeviceSession $s, int $calendarId): string => $world->views[$calendarId] ?? CleanupServiceInterface::VIEW_NORMAL);
        $cleanup->method('calendarsInCleanup')->willReturnCallback(fn (): array => $world->inCleanup);
        $cleanup->method('markDelivered')->willReturnCallback(function (DeviceSession $s, int $calendarId) use ($world): void {
            $world->delivered[] = $calendarId;
        });
        $cleanup->method('markHomeSeen')->willReturnCallback(function () use ($world): void {
            $world->homeSeen++;
        });

        return new DavServices($devices, $this->lockout, $grants, $calendars, $keys, $cleanup, $this->store, $timing, $this->audit, new KeyProvider(str_repeat('s', 32)));
    }

    public function gateway(bool $https = true, string $setting = ''): DavGateway
    {
        $env = new DavEnvironment(
            $https,
            '192.0.2.10',
            'Test "site"',
            DavAddress::fromSetting($setting, 'https://www.example.org/'),
            static fn (int $userId): string => 'Busy',
            static function (\Throwable $e): void {
            }
        );

        return new DavGateway($this->services(), $env);
    }
}
