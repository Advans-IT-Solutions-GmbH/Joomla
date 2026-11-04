<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\BookingData;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Booking rows whose personal fields are encrypted at rest (team-calendars.md,
 * section 5.10): the migration of existing rows, and every search that used
 * to be a SQL condition on a personal field. There is no index of addresses or
 * names, not even a keyed one: a search opens the candidates in batches and
 * compares in memory. Nothing found is ever logged.
 *
 * @since  1.4.0
 */
final class BookingRecords
{
    /** Candidates a search of the booking list opens at most; beyond that the list says the search was cut. */
    public const SEARCH_LIMIT = 20000;

    private BookingData $data;

    public function __construct(private DatabaseInterface $db, KeyProvider $keys)
    {
        $this->data = new BookingData($keys);
    }

    /**
     * Encrypt the personal fields of rows written before 1.4.0, one batch.
     * Idempotent: a value that is already sealed is left alone. Each row is
     * updated with a compare-and-set on its old values, so a concurrent change
     * is never overwritten (that row is picked up by the next call).
     *
     * @return  int  Rows encrypted in this call; 0 when nothing is left.
     */
    public static function migrate(DatabaseInterface $db, KeyProvider $keys, int $batch): int
    {
        $data = new BookingData($keys);
        $columns = BookingData::COLUMNS;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(array_merge(['id', 'uid'], $columns)))->from($db->quoteName('#__appointments_bookings'))
            ->where(self::clearCondition($db))->order($db->quoteName('id'))->setLimit(max(1, min(1000, $batch)));
        $done = 0;

        foreach ($db->setQuery($query)->loadAssocList() as $row) {
            if ((string) $row['uid'] === '') {
                continue;
            }

            $sealed = $data->seal((string) $row['uid'], $row);
            $update = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $update->update($db->quoteName('#__appointments_bookings'))->where($db->quoteName('id') . ' = ' . (int) $row['id']);
            $changed = false;

            foreach ($columns as $column) {
                if ($row[$column] === null || $sealed[$column] === $row[$column]) {
                    continue;
                }

                $changed = true;
                $update->set($db->quoteName($column) . ' = ' . $db->quote($sealed[$column]))
                    ->where($db->quoteName($column) . ' <=> ' . $db->quote($row[$column]));
            }

            if ($changed) {
                $db->setQuery($update)->execute();
                $done += $db->getAffectedRows() > 0 ? 1 : 0;
            }
        }

        return $done;
    }

    /**
     * Bookings, not anonymised, that still hold a personal field in clear text
     * (for the system check while the migration is not finished).
     */
    public static function pending(DatabaseInterface $db): int
    {
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select('COUNT(*)')->from($db->quoteName('#__appointments_bookings'))
            ->where($db->quoteName('anonymised_utc') . ' IS NULL')
            ->where(self::clearCondition($db));

        return (int) $db->setQuery($query)->loadResult();
    }

    private static function clearCondition(DatabaseInterface $db): string
    {
        return '(' . implode(' OR ', array_map(static fn (string $column): string => '(' . $db->quoteName($column) . ' IS NOT NULL AND '
            . $db->quoteName($column) . ' NOT LIKE ' . $db->quote('v1:%') . ')', BookingData::COLUMNS)) . ')';
    }

    /**
     * Bookings in which an address occurs, as invitee (role 1) or as an
     * additional guest (role 2), exactly after trimming and Unicode lower case.
     * Anonymised bookings hold no address. A booking whose fields cannot be
     * opened is skipped (and cannot hold the address in readable form).
     *
     * @return  iterable<array{id: int, role: int}>
     */
    public function findByEmail(string $email, int $batch): iterable
    {
        $wanted = PrivacyAddress::normalise($email);
        $db = $this->db;
        $last = 0;
        $batch = max(1, min(1000, $batch));

        do {
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName(['id', 'uid', 'invitee_email', 'guest_emails']))->from($db->quoteName('#__appointments_bookings'))
                ->where($db->quoteName('id') . ' > ' . $last)
                ->where($db->quoteName('anonymised_utc') . ' IS NULL')
                ->where('(' . $db->quoteName('invitee_email') . ' IS NOT NULL OR ' . $db->quoteName('guest_emails') . ' IS NOT NULL)')
                ->order($db->quoteName('id'))->setLimit($batch);
            $rows = $db->setQuery($query)->loadAssocList();

            foreach ($rows as $row) {
                $last = (int) $row['id'];
                $row = $this->data->openLenient($row);

                if (\is_string($row['invitee_email']) && self::same($row['invitee_email'], $wanted)) {
                    yield ['id' => $last, 'role' => 1];
                }

                if (PrivacyAddress::isGuest($row['guest_emails'], $wanted)) {
                    yield ['id' => $last, 'role' => 2];
                }
            }
        } while (\count($rows) === $batch);
    }

    /**
     * Ids of the bookings of a list query whose name or address contains the
     * needle (case-insensitive), in the order of the query, from at most
     * {@see self::SEARCH_LIMIT} candidates.
     *
     * @param   mixed  $query  The list query with every filter except the search; its select list is replaced.
     *
     * @return  array{ids: int[], cut: bool}
     */
    public function search($query, string $needle): array
    {
        $db = $this->db;
        $needle = mb_strtolower(trim($needle), 'UTF-8');
        $query = clone $query;
        $query->clear('select')->select($db->quoteName(['b.id', 'b.uid', 'b.invitee_name', 'b.invitee_first_name',
            'b.invitee_last_name', 'b.invitee_email']));
        $ids = [];
        $offset = 0;
        $batch = 500;

        do {
            $query->setLimit($batch, $offset);
            $rows = $db->setQuery($query)->loadAssocList();
            $offset += \count($rows);

            foreach ($rows as $row) {
                $row = $this->data->openLenient($row);

                foreach (['invitee_name', 'invitee_first_name', 'invitee_last_name', 'invitee_email'] as $column) {
                    if (\is_string($row[$column]) && $needle !== '' && str_contains(mb_strtolower($row[$column], 'UTF-8'), $needle)) {
                        $ids[] = (int) $row['id'];

                        break;
                    }
                }
            }
        } while (\count($rows) === $batch && $offset < self::SEARCH_LIMIT);

        return ['ids' => $ids, 'cut' => \count($rows) === $batch && $offset >= self::SEARCH_LIMIT];
    }

    /**
     * Ids of a list query ordered by the invitee's last and first name, opened
     * in memory, from at most {@see self::SEARCH_LIMIT} rows.
     *
     * @param   mixed  $query  The list query with every filter; its select list and order are replaced.
     *
     * @return  int[]
     */
    public function orderedByName($query, bool $descending): array
    {
        $db = $this->db;
        $query = clone $query;
        $query->clear('select')->clear('order')->select($db->quoteName(['b.id', 'b.uid', 'b.invitee_first_name', 'b.invitee_last_name', 'b.invitee_name']))
            ->order($db->quoteName('b.id'));
        $rows = [];

        $query->setLimit(self::SEARCH_LIMIT);

        foreach ($db->setQuery($query)->loadAssocList() as $row) {
            $row = $this->data->openLenient($row);
            $last = (string) ($row['invitee_last_name'] ?? '');
            $rows[] = [mb_strtolower($last !== '' ? $last : (string) ($row['invitee_name'] ?? ''), 'UTF-8'),
                mb_strtolower((string) ($row['invitee_first_name'] ?? ''), 'UTF-8'), (int) $row['id']];
        }

        usort($rows, static fn (array $a, array $b): int => $descending ? [$b[0], $b[1], $b[2]] <=> [$a[0], $a[1], $a[2]] : $a <=> $b);

        return array_column($rows, 2);
    }

    private static function same(string $stored, string $wanted): bool
    {
        try {
            return PrivacyAddress::normalise($stored) === $wanted;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }
}
