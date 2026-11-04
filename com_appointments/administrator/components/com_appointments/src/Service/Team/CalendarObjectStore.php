<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Advans\Component\Appointments\Administrator\Helper\Team\ChangeSet;
use Advans\Component\Appointments\Administrator\Helper\Team\StoredObject;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Helper\Team\VendorAutoloader;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Component\VCalendar;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Reader;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Encrypted calendar objects and the sync journal (work package A); the
 * contract is {@see CalendarObjectStoreInterface}. No e-mail address of an
 * object is stored, not even as a hash.
 *
 * The store never decides access and never holds a user key: the caller passes
 * an opened keyring for a write by a key holder, and the booking flow writes
 * sealed to the calendar's public key. Every change locks the calendar row
 * (`SELECT … FOR UPDATE`), so the sync token rises by exactly one per change and
 * the journal has one row per token; ETag preconditions are checked again inside
 * that lock. The ETag is random per write, so it says nothing about the content.
 *
 * Only `synctoken` of `#__appointments_calendars` is written here; it is not
 * part of the calendar's row MAC (specification 5.2).
 *
 * @since  1.4.0
 */
final class CalendarObjectStore implements CalendarObjectStoreInterface
{
    /** Largest object in bytes. */
    public const MAX_BYTES = 1048576;

    /**
     * Objects and bytes one calendar holds at most through CalDAV writes, so no
     * writer can make a full calendar query (every object decrypted at once) fail
     * for everybody. Listings and sync read no payload. Booking objects are always
     * written.
     */
    public const MAX_OBJECTS_PER_CALENDAR = 20000;
    public const MAX_BYTES_PER_CALENDAR = 16777216;

    private const COLUMNS = ['id', 'calendar_id', 'uri', 'etag', 'size', 'enc_scheme', 'key_version', 'payload', 'timing', 'booking_id', 'modified_utc'];

    public function __construct(private readonly TeamServices $services)
    {
    }

    /**
     * Whether a client-chosen object address is acceptable: 1 to 255 characters
     * of `[A-Za-z0-9._@+=-]`, ending in `.ics`, not only dots.
     */
    public static function validUri(string $uri): bool
    {
        return \strlen($uri) > 4
            && \strlen($uri) <= 255
            && preg_match('/^[A-Za-z0-9._@+=-]+$/', $uri) === 1
            && str_ends_with($uri, '.ics')
            && trim($uri, '.') !== 'ics';
    }

    /**
     * Checks one calendar object: one VCALENDAR with at least one VEVENT, only
     * VEVENT and VTIMEZONE components, every VEVENT with the same UID.
     *
     * @return  string  The UID.
     *
     * @throws  TeamException  400 when the object breaks a rule, 413 above the size limit.
     */
    public static function inspect(string $ics): string
    {
        if (\strlen($ics) > self::MAX_BYTES) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_A_OBJECT_TOO_LARGE', 413);
        }

        if (!VendorAutoloader::register()) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
        }

        // The reader stops after the first calendar; a second one, or anything after
        // its end, would be stored without being checked.
        if (preg_match_all('/^BEGIN:VCALENDAR\r?$/mi', $ics) !== 1 || preg_match('/^END:VCALENDAR\s*\z/mi', $ics) !== 1) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_A_OBJECT_INVALID', 400);
        }

        try {
            $calendar = Reader::read($ics);
        } catch (\Throwable $e) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_A_OBJECT_INVALID', 400);
        }

        try {
            if (!$calendar instanceof VCalendar) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_A_OBJECT_INVALID', 400);
            }

            $uid = null;

            foreach ($calendar->getComponents() as $component) {
                if ($component->name === 'VTIMEZONE') {
                    continue;
                }

                if ($component->name !== 'VEVENT') {
                    throw new TeamException('COM_APPOINTMENTS_TEAM_A_OBJECT_INVALID', 400);
                }

                $value = isset($component->UID) ? trim((string) $component->UID) : '';

                if ($value === '' || \strlen($value) > 255 || ($uid !== null && $value !== $uid)) {
                    throw new TeamException('COM_APPOINTMENTS_TEAM_A_OBJECT_INVALID', 400);
                }

                $uid = $value;
            }

            if ($uid === null) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_A_OBJECT_INVALID', 400);
            }

            return $uid;
        } finally {
            $calendar->destroy();
        }
    }

    /**
     * Whether an ETag precondition holds: `*` for any existing object, otherwise
     * the exact ETag (quotes and a weak prefix ignored).
     */
    public static function etagMatches(string $expected, ?string $current): bool
    {
        if ($current === null) {
            return false;
        }

        $expected = trim($expected);

        if ($expected === '*') {
            return true;
        }

        return hash_equals($current, trim((string) preg_replace('/^W\//', '', $expected), '"'));
    }

    /**
     * Collapses journal rows after a token into what the client must do: an
     * address added after the token and not deleted is «added», one deleted at
     * the end is «deleted», any other is «modified». An address added after the
     * token and deleted again is left out: the client never had it.
     *
     * @param   array<int, array{uri: string, operation: int}>  $rows  In token order.
     *
     * @return  array{added: string[], modified: string[], deleted: string[]}
     */
    public static function collapse(array $rows): array
    {
        $first = [];
        $last = [];

        foreach ($rows as $row) {
            $uri = (string) $row['uri'];
            $first[$uri] ??= (int) $row['operation'];
            $last[$uri] = (int) $row['operation'];
        }

        $result = ['added' => [], 'modified' => [], 'deleted' => []];

        foreach ($last as $uri => $operation) {
            if ($operation === TeamStates::CHANGE_DELETED && $first[$uri] === TeamStates::CHANGE_ADDED) {
                continue;
            }

            if ($operation === TeamStates::CHANGE_DELETED) {
                $result['deleted'][] = (string) $uri;
            } elseif ($first[$uri] === TeamStates::CHANGE_ADDED) {
                $result['added'][] = (string) $uri;
            } else {
                $result['modified'][] = (string) $uri;
            }
        }

        return $result;
    }

    public function all(int $calendarId): array
    {
        $db = $this->db();
        $query = $this->select()
            ->where($db->quoteName('calendar_id') . ' = ' . (int) $calendarId)
            ->order($db->quoteName('uri'));

        return array_map([$this, 'toObject'], $db->setQuery($query)->loadAssocList() ?: []);
    }

    public function listing(int $calendarId): array
    {
        $db = $this->db();
        $columns = array_map(static fn (string $column): string => $column === 'payload' ? "'' AS " . $db->quoteName('payload') : $db->quoteName($column), self::COLUMNS);
        $query = $this->query()
            ->select($columns)
            ->from($db->quoteName('#__appointments_calendar_objects'))
            ->where($db->quoteName('calendar_id') . ' = ' . (int) $calendarId)
            ->order($db->quoteName('uri'));

        return array_map([$this, 'toObject'], $db->setQuery($query)->loadAssocList() ?: []);
    }

    public function many(int $calendarId, array $uris): array
    {
        $uris = array_values(array_unique(array_filter(array_map('strval', $uris), [self::class, 'validUri'])));

        if ($uris === []) {
            return [];
        }

        $db = $this->db();
        $query = $this->select()
            ->where($db->quoteName('calendar_id') . ' = ' . (int) $calendarId)
            ->whereIn($db->quoteName('uri'), $uris, ParameterType::STRING)
            ->order($db->quoteName('uri'));

        return array_map([$this, 'toObject'], $db->setQuery($query)->loadAssocList() ?: []);
    }

    public function get(int $calendarId, string $uri): ?StoredObject
    {
        if (!self::validUri($uri)) {
            return null;
        }

        $db = $this->db();
        $query = $this->select()
            ->where($db->quoteName('calendar_id') . ' = ' . (int) $calendarId)
            ->where($db->quoteName('uri') . ' = :uri')
            ->bind(':uri', $uri);
        $row = $db->setQuery($query)->loadAssoc();

        return $row ? $this->toObject($row) : null;
    }

    public function put(CalendarKeyring $keyring, string $uri, string $ics, ?string $ifMatch, bool $ifNoneMatch, ?int $actorDeviceId): string
    {
        $calendarId = $keyring->calendarId();

        if (!self::validUri($uri)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_A_OBJECT_URI_INVALID', 400);
        }

        $uid = self::inspect($ics);
        $keys = $this->services->keys();
        $uidHash = $keys->uidHash($calendarId, $uid);
        $timing = $keys->sealTiming($calendarId, $uri, $this->services->timing()->extract($ics));
        $key = $keyring->current();
        $payload = $keys->encryptObject($key, $uri, $ics);

        $db = $this->db();
        $db->transactionStart();

        try {
            $synctoken = $this->lockCalendar($calendarId);
            $existing = $this->lockObject($calendarId, $uri);

            if ($ifNoneMatch && $existing !== null) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_A_PRECONDITION_FAILED', 412);
            }

            if ($ifMatch !== null && !self::etagMatches($ifMatch, $existing['etag'] ?? null)) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_A_PRECONDITION_FAILED', 412);
            }

            // RFC 4791 no-uid-conflict: no other object has the UID, and a replaced object keeps its own.
            if ($this->uidTakenElsewhere($calendarId, $uidHash, $uri) || ($existing !== null && !hash_equals($existing['uid_hash'], $uidHash))) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_A_UID_CONFLICT', 409);
            }

            $this->assertRoom($calendarId, $existing === null ? 1 : 0, \strlen($ics), $existing['id'] ?? null);

            $etag = bin2hex(random_bytes(16));
            $objectId = $this->store($calendarId, $uri, $existing, [
                'uid_hash'       => $uidHash,
                'etag'           => $etag,
                'size'           => \strlen($ics),
                'enc_scheme'     => TeamStates::SCHEME_CALENDAR_KEY,
                'key_version'    => $key->version(),
                'payload'        => $payload,
                'timing'         => $timing,
            ], $existing['booking_id'] ?? null);
            $this->journal($calendarId, $synctoken + 1, $uri, $existing === null ? TeamStates::CHANGE_ADDED : TeamStates::CHANGE_MODIFIED);

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }

        $this->log($existing === null ? AuditEvent::OBJECT_CREATED : AuditEvent::OBJECT_UPDATED, $calendarId, $objectId, $actorDeviceId);

        return $etag;
    }

    public function putSealed(int $calendarId, string $ics, int $bookingId): string
    {
        $uid = self::inspect($ics);
        $keys = $this->services->keys();
        $uidHash = $keys->uidHash($calendarId, $uid);
        $skeleton = $this->services->timing()->extract($ics);

        $db = $this->db();
        $db->transactionStart();

        try {
            $synctoken = $this->lockCalendar($calendarId);

            // The same UID already in this calendar (a team member accepted the
            // booking mail's ICS there): that object becomes the booking's, so the
            // event never shows twice.
            $query = $this->query()
                ->select($db->quoteName('uri'))
                ->from($db->quoteName('#__appointments_calendar_objects'))
                ->where($db->quoteName('calendar_id') . ' = ' . (int) $calendarId)
                ->where($db->quoteName('uid_hash') . ' = ' . $db->quoteBinary($uidHash));
            $uri = (string) ($db->setQuery((string) $query . ' FOR UPDATE')->loadResult() ?: $this->bookingUri($calendarId, $uid));
            $existing = $this->lockObject($calendarId, $uri);
            $sealed = $keys->sealObject($calendarId, $uri, $ics);
            $etag = bin2hex(random_bytes(16));
            $objectId = $this->store($calendarId, $uri, $existing, [
                'uid_hash'       => $uidHash,
                'etag'           => $etag,
                'size'           => \strlen($ics),
                'enc_scheme'     => (int) $sealed['scheme'],
                'key_version'    => (int) $sealed['version'],
                'payload'        => (string) $sealed['payload'],
                'timing'         => $keys->sealTiming($calendarId, $uri, $skeleton),
            ], $bookingId);
            $this->journal($calendarId, $synctoken + 1, $uri, $existing === null ? TeamStates::CHANGE_ADDED : TeamStates::CHANGE_MODIFIED);

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }

        $this->log(AuditEvent::BOOKING_WRITTEN, $calendarId, $objectId, null, ['booking_id' => $bookingId]);

        return $etag;
    }

    public function delete(int $calendarId, string $uri, ?string $ifMatch, ?int $actorDeviceId): void
    {
        if (!self::validUri($uri)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_A_NOT_FOUND', 404);
        }

        $objectId = $this->deleteOne($calendarId, $uri, $ifMatch);

        if ($objectId === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_A_NOT_FOUND', 404);
        }

        $this->log(AuditEvent::OBJECT_DELETED, $calendarId, $objectId, $actorDeviceId);
    }

    public function deleteForBooking(int $bookingId): int
    {
        $db = $this->db();
        $query = $this->query()
            ->select($db->quoteName(['id', 'calendar_id', 'uri']))
            ->from($db->quoteName('#__appointments_calendar_objects'))
            ->where($db->quoteName('booking_id') . ' = ' . (int) $bookingId);
        $removed = 0;

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            // Under the lock it must still be this row of this booking, not a later object at the same address.
            $objectId = $this->deleteOne((int) $row['calendar_id'], (string) $row['uri'], null, static fn (array $o): bool => $o['id'] === (int) $row['id'] && $o['booking_id'] === $bookingId);

            if ($objectId !== null) {
                $removed++;
                $this->log(AuditEvent::BOOKING_REMOVED, (int) $row['calendar_id'], $objectId, null, ['booking_id' => $bookingId]);
            }
        }

        return $removed;
    }

    public function reencrypt(CalendarKeyring $keyring, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $db = $this->db();
        $calendarId = $keyring->calendarId();
        $current = $keyring->current();
        $query = $this->select()
            ->where($db->quoteName('calendar_id') . ' = ' . (int) $calendarId)
            ->where('(' . $db->quoteName('enc_scheme') . ' <> ' . TeamStates::SCHEME_CALENDAR_KEY
                . ' OR ' . $db->quoteName('key_version') . ' <> ' . (int) $current->version() . ')')
            // In random order: rows that can never be opened must not fill every batch and starve the rest.
            ->order('RAND()');
        $done = 0;
        $refused = 0;

        foreach ($db->setQuery($query->setLimit($limit))->loadAssocList() ?: [] as $row) {
            $object = $this->toObject($row);
            $ics = $this->services->keys()->decryptObject($keyring, $object);

            if ($ics === null) {
                // A sealed envelope without a valid MAC is never opened (specification 5.2); it stays as it is.
                $refused += $object->scheme === TeamStates::SCHEME_SEALED ? 1 : 0;
                continue;
            }

            $payload = $this->services->keys()->encryptObject($current, $object->uri, $ics);
            // bind() takes a reference, which a readonly property cannot give.
            $etag = $object->etag;
            $scheme = TeamStates::SCHEME_CALENDAR_KEY;
            $version = $current->version();

            // Only when the object is still the one that was read: a concurrent write wins.
            $update = $this->query()
                ->update($db->quoteName('#__appointments_calendar_objects'))
                ->set($db->quoteName('payload') . ' = :payload')
                ->set($db->quoteName('enc_scheme') . ' = :scheme')
                ->set($db->quoteName('key_version') . ' = :version')
                ->where($db->quoteName('id') . ' = ' . (int) $object->id)
                ->where($db->quoteName('etag') . ' = :etag')
                ->where($db->quoteName('enc_scheme') . ' = ' . (int) $object->scheme)
                ->where($db->quoteName('key_version') . ' = ' . (int) $object->keyVersion)
                ->bind(':payload', $payload)
                ->bind(':scheme', $scheme, ParameterType::INTEGER)
                ->bind(':version', $version, ParameterType::INTEGER)
                ->bind(':etag', $etag);
            $db->setQuery($update)->execute();
            $done += $db->getAffectedRows() > 0 ? 1 : 0;
        }

        if ($refused > 0) {
            $this->log(AuditEvent::ROW_MAC_FAILURE, $calendarId, null, null, ['table' => 'calendar_objects', 'reason' => 'sealed_envelope', 'count' => $refused]);
        }

        return $done;
    }

    public function deleteEndedBefore(int $calendarId, \DateTimeImmutable $beforeUtc): int
    {
        $before = $beforeUtc->setTimezone(new \DateTimeZone('UTC'));
        $deleted = 0;

        foreach ($this->listing($calendarId) as $object) {
            // Times without a zone count as UTC: the retention works in days, hours do not matter.
            try {
                $skeleton = $this->services->keys()->openTiming($calendarId, $object->uri, $object->timing);
                $end = $skeleton === null ? null : $this->services->timing()->lastEnd($skeleton, new \DateTimeZone('UTC'));
            } catch (TeamException $e) {
                $end = null;
            }

            if ($end === null || $end >= $before) {
                continue;
            }

            // Only the version that was judged: a change in the meantime may have moved the event.
            if ($this->deleteOne($calendarId, $object->uri, null, static fn (array $o): bool => $o['id'] === $object->id && hash_equals($o['etag'], $object->etag)) !== null) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->log(AuditEvent::OBJECT_RETENTION, $calendarId, null, null, ['count' => $deleted]);
        }

        return $deleted;
    }

    public function syncToken(int $calendarId): int
    {
        $db = $this->db();
        $query = $this->query()
            ->select($db->quoteName('synctoken'))
            ->from($db->quoteName('#__appointments_calendars'))
            ->where($db->quoteName('id') . ' = ' . (int) $calendarId);
        $token = $db->setQuery($query)->loadResult();

        if ($token === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_A_NOT_FOUND', 404);
        }

        return (int) $token;
    }

    public function changesSince(int $calendarId, ?string $syncToken, ?int $limit): ChangeSet
    {
        $db = $this->db();
        $current = $this->syncToken($calendarId);
        $token = $syncToken === null ? '' : trim($syncToken);

        // No token, or one this server never issued: the full set, as added.
        if ($token === '' || preg_match('/^\d{1,19}$/', $token) !== 1 || (int) $token > $current) {
            return new ChangeSet((string) $current, array_map(static fn (StoredObject $o): string => $o->uri, $this->listing($calendarId)), [], []);
        }

        $since = (int) $token;

        if ($since === $current) {
            return new ChangeSet((string) $current, [], [], []);
        }

        // Each change raised the token by one; a token below the oldest journal row is gone.
        $query = $this->query()
            ->select('MIN(' . $db->quoteName('synctoken') . ')')
            ->from($db->quoteName('#__appointments_calendar_changes'))
            ->where($db->quoteName('calendar_id') . ' = ' . (int) $calendarId);
        $oldest = $db->setQuery($query)->loadResult();

        if ($oldest === null || $since < (int) $oldest - 1) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_A_SYNC_TOKEN_INVALID', 403);
        }

        $query = $this->query()
            ->select($db->quoteName(['synctoken', 'uri', 'operation']))
            ->from($db->quoteName('#__appointments_calendar_changes'))
            ->where($db->quoteName('calendar_id') . ' = ' . (int) $calendarId)
            ->where($db->quoteName('synctoken') . ' > ' . $since)
            ->where($db->quoteName('synctoken') . ' <= ' . $current)
            ->order([$db->quoteName('synctoken'), $db->quoteName('id')]);
        $rows = $limit !== null && $limit > 0
            ? ($db->setQuery($query->setLimit($limit))->loadAssocList() ?: [])
            : ($db->setQuery($query)->loadAssocList() ?: []);

        $newToken = $limit !== null && $limit > 0 && \count($rows) === $limit ? (int) end($rows)['synctoken'] : $current;
        $changes = self::collapse($rows);

        return new ChangeSet((string) $newToken, $changes['added'], $changes['modified'], $changes['deleted']);
    }

    public function skeletons(int $calendarId): iterable
    {
        $db = $this->db();
        $query = $this->query()
            ->select($db->quoteName(['uri', 'timing', 'booking_id']))
            ->from($db->quoteName('#__appointments_calendar_objects'))
            ->where($db->quoteName('calendar_id') . ' = ' . (int) $calendarId)
            ->order($db->quoteName('id'));
        $keys = $this->services->keys();

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $skeleton = $keys->openTiming($calendarId, (string) $row['uri'], (string) $row['timing']);

            if ($skeleton !== null) {
                yield [
                    'uri'        => (string) $row['uri'],
                    'skeleton'   => $skeleton,
                    'booking_id' => $row['booking_id'] === null ? null : (int) $row['booking_id'],
                ];
            }
        }
    }

    /**
     * Deletes one object with its journal row; null when it does not exist or,
     * with $still, when the locked row is no longer the one the caller meant.
     *
     * @param   (\Closure(array{id: int, etag: string, booking_id: int|null, uid_hash: string}): bool)|null  $still
     *
     * @throws  TeamException  412 when the precondition fails.
     */
    private function deleteOne(int $calendarId, string $uri, ?string $ifMatch, ?\Closure $still = null): ?int
    {
        $db = $this->db();
        $db->transactionStart();

        try {
            $synctoken = $this->lockCalendar($calendarId);
            $existing = $this->lockObject($calendarId, $uri);

            if ($existing === null || ($still !== null && !$still($existing))) {
                $db->transactionCommit();

                return null;
            }

            if ($ifMatch !== null && !self::etagMatches($ifMatch, $existing['etag'])) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_A_PRECONDITION_FAILED', 412);
            }

            $db->setQuery(
                $this->query()
                    ->delete($db->quoteName('#__appointments_calendar_objects'))
                    ->where($db->quoteName('id') . ' = ' . (int) $existing['id'])
            )->execute();
            $this->journal($calendarId, $synctoken + 1, $uri, TeamStates::CHANGE_DELETED);

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }

        return (int) $existing['id'];
    }

    /**
     * Locks the calendar row for the change and returns its sync token.
     *
     * @throws  TeamException  404 for an unknown calendar.
     */
    private function lockCalendar(int $calendarId): int
    {
        $db = $this->db();
        $query = $this->query()
            ->select($db->quoteName('synctoken'))
            ->from($db->quoteName('#__appointments_calendars'))
            ->where($db->quoteName('id') . ' = ' . (int) $calendarId);
        $token = $db->setQuery((string) $query . ' FOR UPDATE')->loadResult();

        if ($token === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_A_NOT_FOUND', 404);
        }

        return (int) $token;
    }

    /**
     * @return  array{id: int, etag: string, booking_id: int|null, uid_hash: string}|null
     */
    private function lockObject(int $calendarId, string $uri): ?array
    {
        $db = $this->db();
        $query = $this->query()
            ->select($db->quoteName(['id', 'etag', 'booking_id', 'uid_hash']))
            ->from($db->quoteName('#__appointments_calendar_objects'))
            ->where($db->quoteName('calendar_id') . ' = ' . (int) $calendarId)
            ->where($db->quoteName('uri') . ' = ' . $db->quote($uri));
        $row = $db->setQuery((string) $query . ' FOR UPDATE')->loadAssoc();

        return $row ? [
            'id'         => (int) $row['id'],
            'etag'       => (string) $row['etag'],
            'booking_id' => $row['booking_id'] === null ? null : (int) $row['booking_id'],
            'uid_hash'   => (string) $row['uid_hash'],
        ] : null;
    }

    /**
     * @throws  TeamException  507 when the write would exceed the calendar's limits.
     */
    private function assertRoom(int $calendarId, int $newObjects, int $newBytes, ?int $replacedId): void
    {
        $db = $this->db();
        $query = $this->query()
            ->select(['COUNT(*) AS ' . $db->quoteName('objects'), 'COALESCE(SUM(' . $db->quoteName('size') . '), 0) AS ' . $db->quoteName('bytes')])
            ->from($db->quoteName('#__appointments_calendar_objects'))
            ->where($db->quoteName('calendar_id') . ' = ' . (int) $calendarId);

        if ($replacedId !== null) {
            $query->where($db->quoteName('id') . ' <> ' . (int) $replacedId);
        }

        $usage = $db->setQuery($query)->loadAssoc() ?: ['objects' => 0, 'bytes' => 0];

        if ((int) $usage['objects'] + $newObjects + ($replacedId !== null ? 1 : 0) > self::MAX_OBJECTS_PER_CALENDAR
            || (int) $usage['bytes'] + $newBytes > self::MAX_BYTES_PER_CALENDAR) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_A_CALENDAR_FULL', 507);
        }
    }

    private function uidTakenElsewhere(int $calendarId, string $uidHash, string $uri): bool
    {
        $db = $this->db();
        $query = $this->query()
            ->select('COUNT(*)')
            ->from($db->quoteName('#__appointments_calendar_objects'))
            ->where($db->quoteName('calendar_id') . ' = ' . (int) $calendarId)
            ->where($db->quoteName('uid_hash') . ' = ' . $db->quoteBinary($uidHash))
            ->where($db->quoteName('uri') . ' <> ' . $db->quote($uri));

        return (int) $db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Inserts or replaces the object row; returns its id.
     *
     * @param   array{id: int, etag: string, booking_id: int|null}|null  $existing
     * @param   array<string, string|int>                                $fields
     */
    private function store(int $calendarId, string $uri, ?array $existing, array $fields, ?int $bookingId): int
    {
        $db = $this->db();
        $now = $this->services->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $query = $this->query();
        $values = $fields + ['booking_id' => $bookingId, 'modified_utc' => $now];

        if ($existing === null) {
            $values += ['calendar_id' => $calendarId, 'uri' => $uri, 'component' => 'VEVENT', 'created_utc' => $now];
            $query->insert($db->quoteName('#__appointments_calendar_objects'))->columns($db->quoteName(array_keys($values)));
            $placeholders = [];

            foreach ($values as $column => $value) {
                $placeholders[] = ':' . $column;
            }

            $query->values(implode(', ', $placeholders));
        } else {
            $query->update($db->quoteName('#__appointments_calendar_objects'))
                ->where($db->quoteName('id') . ' = ' . (int) $existing['id']);

            foreach (array_keys($values) as $column) {
                $query->set($db->quoteName($column) . ' = :' . $column);
            }
        }

        foreach ($values as $column => $value) {
            $type = match (true) {
                $value === null => ParameterType::NULL,
                \is_int($value) => ParameterType::INTEGER,
                // Binary columns too: both drivers send a string parameter byte for byte.
                default => ParameterType::STRING,
            };
            $query->bind(':' . $column, $values[$column], $type);
        }

        $db->setQuery($query)->execute();

        return $existing === null ? (int) $db->insertid() : $existing['id'];
    }

    private function journal(int $calendarId, int $synctoken, string $uri, int $operation): void
    {
        $db = $this->db();
        $db->setQuery(
            $this->query()
                ->update($db->quoteName('#__appointments_calendars'))
                ->set($db->quoteName('synctoken') . ' = ' . $synctoken)
                ->where($db->quoteName('id') . ' = ' . (int) $calendarId)
        )->execute();

        $db->setQuery(
            $this->query()
                ->insert($db->quoteName('#__appointments_calendar_changes'))
                ->columns($db->quoteName(['calendar_id', 'synctoken', 'uri', 'operation']))
                ->values((int) $calendarId . ', ' . $synctoken . ', ' . $db->quote($uri) . ', ' . (int) $operation)
        )->execute();
    }

    /**
     * The address of a booking's object when the calendar has no object with its
     * UID yet: a keyed hash, so neither the UID nor the booking shows in it.
     */
    private function bookingUri(int $calendarId, string $uid): string
    {
        $mac = hash_hmac(
            'sha256',
            'com_appointments/booking-uri/v1|' . $calendarId . "\0" . $uid,
            $this->services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_UID)
        );

        return 'booking-' . substr($mac, 0, 40) . '.ics';
    }

    /**
     * @param   array<string, scalar>  $details
     */
    private function log(string $event, int $calendarId, ?int $objectId, ?int $actorDeviceId, array $details = []): void
    {
        try {
            $context = ['calendar_id' => $calendarId, 'actor_device_id' => $actorDeviceId];

            if ($objectId !== null) {
                $context['object_ref'] = 'o' . $objectId;
            }

            if ($details !== []) {
                $context['details'] = $details;
            }

            $this->services->audit()->log($event, $context);
        } catch (\Throwable $e) {
            // Logging never fails the change it records.
        }
    }

    /**
     * @param   array<string, mixed>  $row
     */
    private function toObject(array $row): StoredObject
    {
        return new StoredObject(
            (int) $row['id'],
            (int) $row['calendar_id'],
            (string) $row['uri'],
            (string) $row['etag'],
            (int) $row['size'],
            (int) $row['enc_scheme'],
            (int) $row['key_version'],
            (string) $row['payload'],
            (string) $row['timing'],
            $row['booking_id'] === null ? null : (int) $row['booking_id'],
            new \DateTimeImmutable((string) $row['modified_utc'], new \DateTimeZone('UTC'))
        );
    }

    private function select(string $alias = ''): \Joomla\Database\QueryInterface
    {
        $db = $this->db();
        $prefix = $alias === '' ? '' : $alias . '.';

        return $this->query()
            ->select($db->quoteName(array_map(static fn (string $c): string => $prefix . $c, self::COLUMNS)))
            ->from($db->quoteName('#__appointments_calendar_objects', $alias === '' ? null : $alias));
    }

    private function query(): \Joomla\Database\QueryInterface
    {
        $db = $this->db();

        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    private function db(): DatabaseInterface
    {
        return $this->services->db();
    }
}
