<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\FeedCalendar;
use Advans\Component\Appointments\Administrator\Helper\FeedToken;
use Advans\Component\Appointments\Administrator\Helper\IcsWriter;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

final class FeedService
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * Deliberately load and serialize the same calendar for correct, wrong and
     * malformed credentials of a scope/id. Do not turn authentication into a
     * shortcut around the expensive work (or expose its contents on failure).
     *
     * @return array{body: string, etag: string, filename: string}|null
     */
    public function download(string $scope, int $id, string $token, \DateTimeImmutable $now): ?array
    {
        $validScope = in_array($scope, ['event', 'host'], true);
        $scope = $validScope ? $scope : 'event';
        $id = $validScope && $id > 0 ? $id : 0;
        $credential = $this->credential($scope, $id);
        $events = $this->events($scope, $id);
        $invalid = null;
        try {
            $horizons = [];
            $end = $now->modify('+1 second');
            foreach ($events as $event) {
                $horizon = FeedCalendar::horizon($event, $event['timezone'], $now);
                $horizons[(int) $event['id']] = $horizon->format('Y-m-d H:i:s');
                $end = max($end, $horizon);
            }
            $entries = $this->entries($events, $horizons, $now, $end);
            $body = (new IcsWriter())->feed($entries, (string) ($credential['title'] ?? ''), $now, $end);
            $etag = FeedCalendar::etag($body, $horizons);
        } catch (\InvalidArgumentException $e) {
            $invalid = $e;
        }
        $authenticated = FeedCalendar::verify((string) ($credential['feed_token'] ?? ''), $token);

        if (!$authenticated || !$validScope || $id === 0) {
            return null;
        }
        if ($invalid !== null) {
            throw new \DomainException('COM_APPOINTMENTS_FEEDS_ERROR_DATA', 500, $invalid);
        }

        return ['body' => $body, 'etag' => $etag, 'filename' => 'appointments-' . $scope . '-' . $id . '.ics'];
    }

    /** Caller must require component manage/options and event edit where applicable. */
    public function rotate(string $scope, int $id, \DateTimeImmutable $now): void
    {
        if (!in_array($scope, ['event', 'host'], true) || $id < 1) {
            throw new \DomainException('COM_APPOINTMENTS_FEEDS_ERROR_NOT_FOUND');
        }

        if ($scope === 'host') {
            if ($this->events('host', $id) === []) {
                throw new \DomainException('COM_APPOINTMENTS_FEEDS_ERROR_NOT_FOUND');
            }
            (new HostStore($this->db))->ensure($id, $now);
        }

        $db = $this->db;
        $table = $scope === 'event' ? '#__appointments_eventtypes' : '#__appointments_hosts';
        $column = $scope === 'event' ? 'id' : 'user_id';
        $db->transactionStart();
        try {
            $query = $this->query()->select($db->quoteName('feed_token'))
                ->from($db->quoteName($table))->where($db->quoteName($column) . ' = ' . $id);
            $stored = $db->setQuery((string) $query . ' FOR UPDATE')->loadResult();
            if ($stored === null) {
                throw new \DomainException('COM_APPOINTMENTS_FEEDS_ERROR_NOT_FOUND');
            }
            $query = $this->query()->update($db->quoteName($table))
                ->set($db->quoteName('feed_token') . ' = ' . $db->quote(FeedToken::rotate((string) $stored)))
                ->set($db->quoteName('modified_utc') . ' = ' . $db->quote($now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')))
                ->where($db->quoteName($column) . ' = ' . $id);
            $db->setQuery($query)->execute();
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }
    }

    /** No credentials are fetched until the caller has filtered the event IDs by ACL. */
    public function eventCredentials(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));
        $db = $this->db;
        $query = $this->query()->select($db->quoteName(['id', 'title', 'feed_token']))
            ->from($db->quoteName('#__appointments_eventtypes'))
            ->where($ids === [] ? '1 = 0' : $db->quoteName('id') . ' IN (' . implode(',', $ids) . ')')
            ->order($db->quoteName('title'));

        return $db->setQuery($query)->loadAssocList();
    }

    /** Caller must require component manage/options before invoking this method. */
    public function hostCredentials(\DateTimeImmutable $now): array
    {
        $db = $this->db;
        $query = $this->query()->select('DISTINCT ' . $db->quoteName('host_user_id'))
            ->from($db->quoteName('#__appointments_eventtypes'))->order($db->quoteName('host_user_id'));
        $ids = $db->setQuery($query)->loadColumn();
        foreach ($ids as $id) {
            (new HostStore($db))->ensure((int) $id, $now);
        }
        $query = $this->query()->select($db->quoteName(['h.user_id', 'h.feed_token']))
            ->select($db->quoteName('u.name', 'title'))
            ->from($db->quoteName('#__appointments_hosts', 'h'))
            ->join('INNER', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('h.user_id'))
            ->where($ids === [] ? '1 = 0' : $db->quoteName('h.user_id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')')
            ->order($db->quoteName('u.name'));

        return $db->setQuery($query)->loadAssocList();
    }

    public function bookingEventId(int $id): int
    {
        $db = $this->db;
        $query = $this->query()->select($db->quoteName('eventtype_id'))
            ->from($db->quoteName('#__appointments_bookings'))->where($db->quoteName('id') . ' = ' . $id);

        return (int) $db->setQuery($query)->loadResult();
    }

    /** Caller authorises the booking's event before fetching its personal fields. */
    public function export(int $id): array
    {
        $db = $this->db;
        $query = $this->bookingQuery()->where($db->quoteName('b.id') . ' = ' . $id);
        $row = $db->setQuery($query)->loadAssoc();
        if (!$row) {
            throw new \DomainException('COM_APPOINTMENTS_FEEDS_ERROR_NOT_FOUND', 404);
        }
        $event = $this->events('event', (int) $row['eventtype_id'])[0] ?? null;
        if ($event === null) {
            throw new \DomainException('COM_APPOINTMENTS_FEEDS_ERROR_NOT_FOUND', 404);
        }
        $event['description'] = $this->plainDescription((string) $event['description']);
        $writer = new IcsWriter();
        $stamp = \Advans\Component\Appointments\Administrator\Helper\UtcTimestamp::parse($row['modified_utc']);
        if ($stamp === null) {
            throw new \DomainException('COM_APPOINTMENTS_FEEDS_ERROR_DATA');
        }

        return ['body' => $writer->attachment($row, $event, $stamp), 'method' => $writer->method($row),
            'filename' => 'appointment-' . $id . '.ics'];
    }

    private function credential(string $scope, int $id): ?array
    {
        $db = $this->db;
        if ($scope === 'event') {
            $query = $this->query()->select($db->quoteName(['feed_token', 'title']))
                ->from($db->quoteName('#__appointments_eventtypes'))->where($db->quoteName('id') . ' = ' . $id);
        } else {
            $query = $this->query()->select($db->quoteName('h.feed_token'))->select($db->quoteName('u.name', 'title'))
                ->from($db->quoteName('#__appointments_hosts', 'h'))
                ->join('INNER', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('h.user_id'))
                ->where($db->quoteName('h.user_id') . ' = ' . $id);
        }

        return $db->setQuery($query)->loadAssoc() ?: null;
    }

    private function events(string $scope, int $id): array
    {
        $db = $this->db;
        $query = $this->query()->select($db->quoteName(['e.id', 'e.title', 'e.description', 'e.duration_minutes',
            'e.slot_step_minutes', 'e.buffer_before_minutes', 'e.buffer_after_minutes', 'e.min_notice_minutes',
            'e.horizon_days', 'e.horizon_start_utc', 'e.horizon_end_utc', 'e.max_per_day', 'e.max_per_week',
            'e.location_type', 'e.location_text', 's.timezone']))
            ->select($db->quoteName('u.email', 'host_email'))->select($db->quoteName('u.name', 'host_name'))
            ->from($db->quoteName('#__appointments_eventtypes', 'e'))
            ->join('INNER', $db->quoteName('#__appointments_schedules', 's'), $db->quoteName('s.id') . ' = ' . $db->quoteName('e.schedule_id'))
            ->join('INNER', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('e.host_user_id'))
            ->where($db->quoteName($scope === 'event' ? 'e.id' : 'e.host_user_id') . ' = ' . $id)
            ->order($db->quoteName('e.id'));

        return $db->setQuery($query)->loadAssocList();
    }

    private function entries(array $events, array $horizons, \DateTimeImmutable $now, \DateTimeImmutable $end): array
    {
        $db = $this->db;
        $map = array_column($events, null, 'id');
        $query = $this->bookingQuery()
            ->where($map === [] ? '1 = 0' : $db->quoteName('b.eventtype_id') . ' IN (' . implode(',', array_map('intval', array_keys($map))) . ')')
            ->where($db->quoteName('b.end_utc') . ' > ' . $db->quote($now->modify('-90 days')->format('Y-m-d H:i:s')))
            ->where($db->quoteName('b.start_utc') . ' < ' . $db->quote($end->format('Y-m-d H:i:s')))
            ->where('(' . $db->quoteName('b.state') . ' IN (0,1) OR (' . $db->quoteName('b.state') . ' = 2 AND '
                . $db->quoteName('b.modified_utc') . ' >= ' . $db->quote($now->modify('-30 days')->format('Y-m-d H:i:s')) . '))');
        $rows = $db->setQuery($query)->loadAssocList();
        $entries = [];
        foreach ($rows as $row) {
            if ($row['start_utc'] >= $horizons[$row['eventtype_id']]) {
                continue;
            }
            $event = $map[$row['eventtype_id']];
            $event['description'] = $this->plainDescription((string) $event['description']);
            $entries[] = ['booking' => $row, 'event' => $event];
        }

        return $entries;
    }

    private function bookingQuery()
    {
        $db = $this->db;

        return $this->query()->select($db->quoteName(['b.id', 'b.eventtype_id', 'b.uid', 'b.sequence', 'b.state',
            'b.start_utc', 'b.end_utc', 'b.modified_utc', 'b.invitee_name', 'b.invitee_email', 'b.guest_emails', 'b.conference_url']))
            ->from($db->quoteName('#__appointments_bookings', 'b'));
    }

    private function plainDescription(string $description): string
    {
        $description = preg_replace('/<\s*(?:br\s*\/?|\/p|\/div|\/li)\s*>/i', "\n", $description);

        return trim(html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function query()
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }
}
