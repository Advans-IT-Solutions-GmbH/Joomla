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

    /** Bookings the last findByEmail() could not open. */
    private int $unreadable = 0;

    public function __construct(private DatabaseInterface $db, KeyProvider $keys)
    {
        $this->data = BookingData::forToken($keys, self::token($db));
    }

    /**
     * Open the migration of an update: when rows with clear personal fields
     * exist, write a migration token (keyed, valid {@see BookingData::MIGRATION_DAYS}
     * days) into the component options. Only while it is valid are clear values
     * read as data and encrypted by {@see self::migrate()}. Called once by the
     * update, before the first migrate(); does nothing on a new installation.
     */
    public static function begin(DatabaseInterface $db, KeyProvider $keys): void
    {
        if (self::pending($db) > 0 && !BookingData::migrationOpen(self::token($db), $keys, time())) {
            self::setToken($db, BookingData::migrationToken($keys, time() + BookingData::MIGRATION_DAYS * 86400));
        }
    }

    /**
     * Encrypt the personal fields of rows written before 1.4.0, one batch,
     * only while the migration token is valid: a clear value a database write
     * put in later is never encrypted into a valid one. Idempotent: a value
     * that is already sealed is left alone. Each row is updated with a
     * compare-and-set on its old values, so a concurrent change is never
     * overwritten (that row comes again in the next call). When no row is
     * left, the token is deleted: from then on a clear value is never read as
     * data again.
     *
     * @return  int  Rows taken up in this call; 0 when nothing is left or no migration is open.
     */
    public static function migrate(DatabaseInterface $db, KeyProvider $keys, int $batch): int
    {
        if (!BookingData::migrationOpen(self::token($db), $keys, time())) {
            return 0;
        }

        $data = new BookingData($keys, true);
        $columns = BookingData::COLUMNS;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(array_merge(['id', 'uid'], $columns)))->from($db->quoteName('#__appointments_bookings'))
            ->where(self::clearCondition($db))->where($db->quoteName('uid') . ' <> ' . $db->quote(''))
            ->order($db->quoteName('id'))->setLimit(max(1, min(1000, $batch)));
        $rows = $db->setQuery($query)->loadAssocList();

        if ($rows === []) {
            self::setToken($db, null);

            return 0;
        }

        foreach ($rows as $row) {
            // Only the clear values: seal() encrypts everything it is given.
            $clear = array_filter(array_intersect_key($row, array_flip($columns)),
                static fn ($value): bool => $value !== null && !BookingData::isSealed($value));

            if ($clear === []) {
                continue;
            }

            $sealed = $data->seal((string) $row['uid'], $clear);
            $update = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $update->update($db->quoteName('#__appointments_bookings'))->where($db->quoteName('id') . ' = ' . (int) $row['id']);

            foreach ($clear as $column => $value) {
                $update->set($db->quoteName($column) . ' = ' . $db->quote($sealed[$column]))
                    ->where($db->quoteName($column) . ' <=> ' . $db->quote($value));
            }

            $db->setQuery($update)->execute();
        }

        return \count($rows);
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

    /** A value is clear unless it starts with `v1:`, compared case-sensitively. */
    private static function clearCondition(DatabaseInterface $db): string
    {
        return '(' . implode(' OR ', array_map(static fn (string $column): string => '(' . $db->quoteName($column) . ' IS NOT NULL AND NOT ('
            . $db->quoteName($column) . ' COLLATE utf8mb4_bin LIKE ' . $db->quote('v1:%') . '))', BookingData::COLUMNS)) . ')';
    }

    /** The migration token stored in the component options, read from the database (not a cached copy). */
    private static function token(DatabaseInterface $db): string
    {
        $params = self::params($db);

        return \is_array($params) ? (string) ($params[BookingData::MIGRATION_OPTION] ?? '') : '';
    }

    /** @return array<string, mixed>|null */
    private static function params(DatabaseInterface $db): ?array
    {
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_appointments'));
        $params = json_decode((string) $db->setQuery($query)->loadResult(), true);

        return \is_array($params) ? $params : null;
    }

    /** Write or (null) delete the migration token. */
    private static function setToken(DatabaseInterface $db, ?string $token): void
    {
        $params = self::params($db);

        if ($params === null || ($token === null && !\array_key_exists(BookingData::MIGRATION_OPTION, $params))) {
            return;
        }

        if ($token === null) {
            unset($params[BookingData::MIGRATION_OPTION]);
        } else {
            $params[BookingData::MIGRATION_OPTION] = $token;
        }

        $update = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $update->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params, JSON_THROW_ON_ERROR)))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_appointments'));
        $db->setQuery($update)->execute();
    }
    /**
     * Bookings in which an address occurs, as invitee (role 1) or as an
     * additional guest (role 2), exactly after trimming and Unicode lower case.
     * Anonymised bookings hold no address. A booking whose address fields
     * cannot be opened is skipped; {@see self::unreadable()} says how many, so
     * the privacy request can say that it was not complete.
     *
     * @return  iterable<array{id: int, role: int}>
     */
    public function findByEmail(string $email, int $batch): iterable
    {
        $wanted = PrivacyAddress::normalise($email);
        $this->unreadable = 0;
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
                $failed = [];
                $row = $this->data->openLenient($row, $failed);
                $this->unreadable += $failed === [] ? 0 : 1;

                if (\is_string($row['invitee_email']) && self::same($row['invitee_email'], $wanted)) {
                    yield ['id' => $last, 'role' => 1];
                }

                if (PrivacyAddress::isGuest($row['guest_emails'], $wanted)) {
                    yield ['id' => $last, 'role' => 2];
                }
            }
        } while (\count($rows) === $batch);
    }

    /** Bookings whose address fields the last {@see self::findByEmail()} could not open. */
    public function unreadable(): int
    {
        return $this->unreadable;
    }

    /**
     * Ids of the bookings of a list query whose name or address contains the
     * needle (case-insensitive), newest first, from at most
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
        // A unique order, so paging the candidates neither skips nor repeats a row.
        $query->clear('select')->clear('order')->select($db->quoteName(['b.id', 'b.uid', 'b.invitee_name', 'b.invitee_first_name',
            'b.invitee_last_name', 'b.invitee_email']))->order($db->quoteName('b.id') . ' DESC');
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
     * in memory, from the newest {@see self::SEARCH_LIMIT} rows.
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
            ->order($db->quoteName('b.id') . ' DESC');
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
