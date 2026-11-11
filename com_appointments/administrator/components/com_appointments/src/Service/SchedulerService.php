<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\ReminderOffsets;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

final class SchedulerService
{
    /**
     * Days the booking retention waits at most for a booking that is queued for the CRM
     * transfer and not delivered yet. After that the booking is erased anyway and recorded
     * in {@see self::CLOSE_MISSED} for the dashboard warning.
     */
    public const CLOSE_WAIT_DAYS = 30;

    /** Bookings erased without having reached the CRM: booking id and time only, kept 30 days. */
    public const CLOSE_MISSED = '#__appointments_close_missed';

    public function __construct(private DatabaseInterface $db, private Registry $params)
    {
    }

    /**
     * Whether the booking retention waits for the CRM transfer: a booking with a delivery
     * record that is not delivered (`done`) waits, at most CLOSE_WAIT_DAYS after its own
     * retention ended. Without a delivery record (no transfer configured for it) nothing
     * waits. Pure; the retention query applies the same rule.
     */
    public static function waitsForClose(?string $deliveryState, \DateTimeImmutable $endUtc, int $retentionDays, \DateTimeImmutable $nowUtc): bool
    {
        if ($deliveryState === null || $deliveryState === 'done') {
            return false;
        }

        return $endUtc >= $nowUtc->modify('-' . ($retentionDays + self::CLOSE_WAIT_DAYS) . ' days');
    }

    /**
     * For the dashboard: bookings past their retention that wait for the CRM transfer, the
     * first day one of them is erased anyway, and bookings erased without transfer in the
     * last CLOSE_WAIT_DAYS days. Counts and a date only.
     *
     * @return  array{waiting: int, first_forced: ?string, missed: int}
     */
    public function closeWaiting(\DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $days = $this->number('retention_days', 365, 0, 65535);
        $db = $this->db;
        $retention = 'COALESCE(' . $db->quoteName('e.retention_days') . ',' . $days . ')';
        $row = $db->setQuery($this->query()
            ->select('COUNT(*) AS ' . $db->quoteName('waiting'))
            ->select('MIN(DATE_ADD(' . $db->quoteName('b.end_utc') . ', INTERVAL ' . $retention . ' + ' . self::CLOSE_WAIT_DAYS
                . ' DAY)) AS ' . $db->quoteName('first_forced'))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e') . ' ON ' . $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->join('INNER', $db->quoteName('#__appointments_close_delivery', 'd') . ' ON ' . $db->quoteName('d.booking_id') . ' = ' . $db->quoteName('b.id'))
            ->where($db->quoteName('b.anonymised_utc') . ' IS NULL')
            ->where($db->quoteName('d.state') . ' <> ' . $db->quote('done'))
            ->where($db->quoteName('b.end_utc') . ' < DATE_SUB(' . $db->quote($this->time($now)) . ', INTERVAL ' . $retention . ' DAY)'))
            ->loadAssoc() ?: [];
        $missed = (int) $db->setQuery($this->query()->select('COUNT(*)')->from($db->quoteName(self::CLOSE_MISSED))
            ->where($db->quoteName('erased_utc') . ' >= ' . $db->quote($this->time($now->modify('-' . self::CLOSE_WAIT_DAYS . ' days')))))
            ->loadResult();

        return ['waiting' => (int) ($row['waiting'] ?? 0), 'first_forced' => $row['first_forced'] ?? null, 'missed' => $missed];
    }

    public function reminders(\DateTimeImmutable $now, int $batchSize = 100): array
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $this->batchSize($batchSize);
        $offsets = ReminderOffsets::parse((string) $this->params->get('reminder_offsets', '1440,60'));
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        if (!$offsets) {
            return $result;
        }
        $db = $this->db;
        $conditions = [];
        foreach ($offsets as $offset) {
            $latest = $now->modify('+' . $offset . ' minutes');
            $latestValue = (int) $latest->format('Y') > 9999 ? '9999-12-31 23:59:59' : $this->time($latest);
            $conditions[] = '(' . $db->quoteName('start_utc') . ' <= ' . $db->quote($latestValue)
                . ' AND (' . $db->quoteName('reminder_sent_utc') . ' IS NULL OR JSON_EXTRACT('
                . $db->quoteName('reminder_sent_utc') . ',' . $db->quote('$."' . $offset . '"') . ') IS NULL))';
        }
        $query = $this->query()->select($db->quoteName(['id', 'host_user_id']))
            ->from($db->quoteName('#__appointments_bookings'))
            ->where($db->quoteName('state') . ' = 1')
            ->where($db->quoteName('anonymised_utc') . ' IS NULL')
            ->where($db->quoteName('start_utc') . ' > ' . $db->quote($this->time($now)))
            ->where('(' . implode(' OR ', $conditions) . ')')
            ->order($db->quoteName('start_utc') . ' ASC')->order($db->quoteName('id') . ' ASC')->setLimit($batchSize);
        $candidates = $db->setQuery($query)->loadAssocList();
        $store = new BookingStore($db);
        $mail = new NotificationService($db);

        foreach ($candidates as $candidate) {
            // Use the lifecycle's host-first order. Cancellation/rescheduling cannot
            // invalidate a reminder between its current-state check and delivery.
            $db->transactionStart();
            try {
                $store->lockHost((int) $candidate['host_user_id']);
                $bookingQuery = $this->query()->select('*')->from($db->quoteName('#__appointments_bookings'))
                    ->where($db->quoteName('id') . ' = ' . (int) $candidate['id']);
                $booking = $db->setQuery((string) $bookingQuery . ' FOR UPDATE')->loadAssoc();
                if (!$booking || (int) $booking['state'] !== 1 || $booking['anonymised_utc'] !== null) {
                    $db->transactionCommit();
                    $result['skipped']++;
                    continue;
                }
                $markers = ReminderOffsets::markers($booking['reminder_sent_utc']);
                $due = ReminderOffsets::due($booking['start_utc'], $now, $offsets, $markers);
                foreach ($due as $offset) {
                    try {
                        $mail->send($booking, 'reminder', $now);
                    } catch (NotificationException $e) {
                        $result['failed']++;
                        break;
                    }
                    $markers[$offset] = $this->time($now);
                    $row = (object) [
                        'id' => (int) $booking['id'],
                        'reminder_sent_utc' => json_encode($markers, JSON_THROW_ON_ERROR),
                    ];
                    $db->updateObject('#__appointments_bookings', $row, 'id');
                    $result['sent']++;
                }
                $db->transactionCommit();
                if (!$due) {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $db->transactionRollback();
                throw $e;
            }
        }

        return $result;
    }

    public function retention(\DateTimeImmutable $now, int $batchSize = 100): array
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $this->batchSize($batchSize);
        $days = $this->number('retention_days', 365, 0, 65535);
        $years = $this->number('audit_retention_years', 3, 1, 100);
        $mode = (string) $this->params->get('erase_mode', 'anonymise');
        if (!in_array($mode, ['anonymise', 'delete'], true)) {
            throw new \InvalidArgumentException('Invalid appointment erasure mode.');
        }
        $db = $this->db;
        $retention = 'COALESCE(' . $db->quoteName('e.retention_days') . ',' . $days . ')';
        // A booking queued for the CRM transfer and not delivered yet waits, at most
        // CLOSE_WAIT_DAYS longer (see waitsForClose()); without a delivery record nothing waits.
        $query = $this->query()->select($db->quoteName(['b.id', 'd.state']))->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e') . ' ON '
                . $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->join('LEFT', $db->quoteName('#__appointments_close_delivery', 'd') . ' ON '
                . $db->quoteName('d.booking_id') . ' = ' . $db->quoteName('b.id'))
            ->where($db->quoteName('b.anonymised_utc') . ' IS NULL')
            ->where($db->quoteName('b.end_utc') . ' < DATE_SUB(' . $db->quote($this->time($now))
                . ', INTERVAL ' . $retention . ' DAY)')
            ->where('(' . $db->quoteName('d.booking_id') . ' IS NULL OR ' . $db->quoteName('d.state') . ' = ' . $db->quote('done')
                . ' OR ' . $db->quoteName('b.end_utc') . ' < DATE_SUB(' . $db->quote($this->time($now))
                . ', INTERVAL ' . $retention . ' + ' . self::CLOSE_WAIT_DAYS . ' DAY))')
            ->order($db->quoteName('b.end_utc') . ' ASC')->order($db->quoteName('b.id') . ' ASC')->setLimit($batchSize);
        $candidates = $db->setQuery($query)->loadAssocList();
        $result = ['bookings' => 0, 'audit' => 0, 'rate_limits' => 0, 'failed' => 0, 'close_undelivered' => 0];
        $store = new BookingStore($db);
        foreach ($candidates as $candidate) {
            $id = (int) $candidate['id'];
            $erasure = $store->erase($id, $now, 'retention', $mode === 'delete', retentionDays: $days);
            $result['bookings'] += $erasure['changed'] ? 1 : 0;
            $result['failed'] += !empty($erasure['_conference_cleanup_error']) ? 1 : 0;

            if ($erasure['changed'] && $candidate['state'] !== null && $candidate['state'] !== 'done') {
                // Erased after the longest wait without reaching the CRM: the dashboard says so.
                $db->setQuery('INSERT IGNORE INTO ' . $db->quoteName(self::CLOSE_MISSED) . ' ('
                    . implode(', ', $db->quoteName(['booking_id', 'erased_utc'])) . ') VALUES (' . $id . ', '
                    . $db->quote($this->time($now)) . ')')->execute();
                $result['close_undelivered']++;
            }
        }

        if ($result['close_undelivered'] > 0) {
            Log::add('Appointment retention: ' . $result['close_undelivered'] . ' bookings erased before they reached Close.',
                Log::WARNING, 'com_appointments');
        }

        $db->setQuery('DELETE FROM ' . $db->quoteName(self::CLOSE_MISSED) . ' WHERE ' . $db->quoteName('erased_utc') . ' < '
            . $db->quote($this->time($now->modify('-' . self::CLOSE_WAIT_DAYS . ' days'))))->execute();

        $cutoff = $now->modify('-' . $years . ' years');
        $auditCutoff = (int) $cutoff->format('Y') < 1000 ? '1000-01-01 00:00:00' : $this->time($cutoff);
        $result['audit'] = $this->purge('#__appointments_audit', 'id', 'occurred_utc', $auditCutoff, $batchSize);
        $result['rate_limits'] = $this->purge('#__appointments_ratelimit', 'bucket', 'window_utc',
            $this->time($now->modify('-1 day')), $batchSize);

        Log::add('Appointment retention: ' . $result['bookings'] . ' bookings, ' . $result['audit']
            . ' audit rows, ' . $result['rate_limits'] . ' rate-limit rows.', Log::INFO, 'com_appointments');

        return $result;
    }

    private function purge(string $table, string $key, string $timeColumn, string $cutoff, int $limit): int
    {
        $db = $this->db;
        // Rate-limit buckets have a composite primary key; delete exactly the
        // selected expired windows rather than a bucket's still-current windows.
        $columns = $table === '#__appointments_ratelimit' ? [$key, $timeColumn] : [$key];
        $query = $this->query()->select($db->quoteName($columns))->from($db->quoteName($table))
            ->where($db->quoteName($timeColumn) . ' < ' . $db->quote($cutoff))
            ->order($db->quoteName($timeColumn) . ' ASC')->order($db->quoteName($key) . ' ASC')->setLimit($limit);
        $rows = $db->setQuery($query)->loadAssocList();
        $removed = 0;
        foreach ($rows as $row) {
            $delete = $this->query()->delete($db->quoteName($table))
                ->where($db->quoteName($key) . ' = ' . $db->quote($row[$key]));
            if (count($columns) === 2) {
                $delete->where($db->quoteName($timeColumn) . ' = ' . $db->quote($row[$timeColumn]));
            }
            $db->setQuery($delete)->execute();
            $removed += $db->getAffectedRows();
        }

        return $removed;
    }

    private function number(string $key, int $default, int $min, int $max): int
    {
        $value = $this->params->get($key, $default);
        if ((!is_int($value) && (!is_string($value) || !ctype_digit($value))) || (int) $value < $min || (int) $value > $max) {
            throw new \InvalidArgumentException('Invalid appointment retention configuration.');
        }

        return (int) $value;
    }

    private function batchSize(int $value): void
    {
        if ($value < 1 || $value > 1000) {
            throw new \InvalidArgumentException('The Scheduler batch size must be between 1 and 1000.');
        }
    }

    private function time(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function query(): QueryInterface
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }
}
