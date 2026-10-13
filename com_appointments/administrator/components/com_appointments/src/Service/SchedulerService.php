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
    public function __construct(private DatabaseInterface $db, private Registry $params)
    {
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
        $query = $this->query()->select($db->quoteName('b.id'))->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'),
                $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('b.anonymised_utc') . ' IS NULL')
            ->where($db->quoteName('b.end_utc') . ' < DATE_SUB(' . $db->quote($this->time($now))
                . ', INTERVAL COALESCE(' . $db->quoteName('e.retention_days') . ',' . $days . ') DAY)')
            ->order($db->quoteName('b.end_utc') . ' ASC')->order($db->quoteName('b.id') . ' ASC')->setLimit($batchSize);
        $ids = $db->setQuery($query)->loadColumn();
        $result = ['bookings' => 0, 'audit' => 0, 'rate_limits' => 0];
        $store = new BookingStore($db);
        foreach ($ids as $id) {
            $erasure = $store->erase((int) $id, $now, 'retention', $mode === 'delete', retentionDays: $days);
            $result['bookings'] += $erasure['changed'] ? 1 : 0;
        }

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
