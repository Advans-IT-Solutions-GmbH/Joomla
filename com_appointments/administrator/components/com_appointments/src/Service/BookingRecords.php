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
     * Open the migration of the update to 1.4.0: when rows with clear personal
     * fields exist and no row is sealed yet, write a migration token (keyed,
     * holding the update time, valid {@see BookingData::MIGRATION_DAYS} days and
     * renewed by every migrate() that finds rows) into the component options.
     * Only while it is valid, and only in rows created before the update, are
     * clear values read as data and encrypted by {@see self::migrate()}. Called
     * by every update, before the first migrate();
     * once a sealed row exists (any booking made with 1.4.0) it never opens a
     * migration again, so clear text a database write plants later is never
     * encrypted into trusted data. Does nothing on a new installation.
     */
    public static function begin(DatabaseInterface $db, KeyProvider $keys): void
    {
        $token = self::token($db);

        // Once finished (or a new installation), never again: the end marker is keyed, and it
        // survives the deletion of every booking, unlike the absence of sealed rows.
        if (BookingData::migrationDone($token, $keys)) {
            return;
        }

        // A migration already opened keeps its update time, also once its token has expired or
        // passed the limit: a later update never moves the cutoff (only encryptRemaining() ends it then).
        if (BookingData::tokenCutoff($token, $keys) !== null) {
            return;
        }

        if (self::pending($db) > 0 && !self::anySealed($db)) {
            self::setToken($db, BookingData::migrationToken($keys, time() + BookingData::MIGRATION_DAYS * 86400, time()), $token);
        }
    }

    /**
     * Encrypt the personal fields of rows written before 1.4.0, one batch,
     * only while the migration token is valid and only in rows created before
     * the update: a clear value a database write put in later is never
     * encrypted into a valid one. Each call that finds rows renews the token's
     * expiry, never beyond {@see BookingData::MIGRATION_LIMIT_DAYS} after the update;
     * once the token has expired, this does nothing and only the administrator
     * action {@see self::encryptRemaining()} encrypts the remaining rows (they stay
     * readable as «not verified»). The Scheduler routine calls it until it returns 0. A token
     * an Options form wrote back after the end is deleted again. Idempotent: a value
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
        $token = self::token($db);
        // Only a valid token: after the window only the administrator action encrypts clear text.
        $cutoff = BookingData::migrationCutoff($token, $keys, time());

        if ($cutoff === null) {
            // A new installation, a database without clear rows or an expired token with none left: over.
            if (!BookingData::migrationDone($token, $keys) && self::pending($db) === 0) {
                self::setToken($db, BookingData::migrationDoneMarker($keys), $token);
            }

            return 0;
        }

        $found = self::sealBatch($db, $keys, $cutoff, $batch);

        if ($found['rows'] === 0) {
            self::setToken($db, BookingData::migrationDoneMarker($keys), $token);

            return 0;
        }

        // Renewed, but never beyond the limit after the update.
        self::setToken($db, BookingData::migrationToken($keys,
            min(time() + BookingData::MIGRATION_DAYS * 86400, $cutoff + BookingData::MIGRATION_LIMIT_DAYS * 86400), $cutoff), $token);

        return $found['rows'];
    }

    /**
     * The migration for `$seconds` at most, in batches: called by the update
     * and at an administrator's visit of the component, so it never depends on
     * the Scheduler. Within the window only, like {@see self::migrate()}.
     *
     * @return  int  Rows taken up.
     */
    public static function resume(DatabaseInterface $db, KeyProvider $keys, int $seconds): int
    {
        $until = microtime(true) + max(1, $seconds);
        $total = 0;

        do {
            $rows = self::migrate($db, $keys, 200);
            $total += $rows;
        } while ($rows > 0 && microtime(true) < $until);

        return $total;
    }

    /** Whether the migration is over (the keyed end marker): a single read of the component options. */
    public static function finished(DatabaseInterface $db, KeyProvider $keys): bool
    {
        return BookingData::migrationDone(self::token($db), $keys);
    }

    /**
     * Rows of 1.3 still in clear text after the migration window: readable, but
     * shown as «not verified» until an administrator encrypts them (for the
     * system check). 0 while the window is open, after the end and without any
     * migration.
     */
    public static function unverified(DatabaseInterface $db, KeyProvider $keys): int
    {
        $token = self::token($db);
        $cutoff = BookingData::tokenCutoff($token, $keys);

        if ($cutoff === null || BookingData::migrationCutoff($token, $keys, time()) !== null) {
            return 0;
        }

        $query = self::rowsOf13($db, $cutoff)->select('COUNT(*)')->where($db->quoteName('anonymised_utc') . ' IS NULL');

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * The administrator action «encrypt now»: encrypt every remaining row of 1.3
     * (created before the update, no sealed field), also after the migration
     * window, record each in the audit log and finish the migration with the end
     * marker. The only way clear text is encrypted after the window: the
     * administrator decides that these rows are genuine. Each batch and its
     * audit records commit together, so a failed batch can be retried.
     *
     * @return  int  Bookings encrypted.
     */
    public static function encryptRemaining(DatabaseInterface $db, KeyProvider $keys, int $actor): int
    {
        $token = self::token($db);
        $cutoff = BookingData::tokenCutoff($token, $keys);

        if ($cutoff === null) {
            return 0;
        }

        $total = 0;
        $time = gmdate('Y-m-d H:i:s');

        for ($round = 0; $round < 10000; $round++) {
            $db->transactionStart(true);

            try {
                $found = self::sealBatch($db, $keys, $cutoff, 200);

                foreach ($found['sealed'] as $id) {
                    $audit = (object) ['booking_id' => $id, 'action' => 'encrypt', 'path' => 'admin',
                        'actor_user_id' => $actor > 0 ? $actor : null, 'occurred_utc' => $time];
                    $db->insertObject('#__appointments_audit', $audit);
                }

                if ($found['rows'] === 0) {
                    self::setToken($db, BookingData::migrationDoneMarker($keys), $token);
                }

                $db->transactionCommit(true);
            } catch (\Throwable $e) {
                $db->transactionRollback(true);

                throw $e;
            }

            $total += \count($found['sealed']);

            // Rows found but none written (each changed meanwhile): stop, the next action takes them.
            if ($found['rows'] > 0 && $found['sealed'] === []) {
                break;
            }

            if ($found['rows'] === 0) {
                break;
            }
        }

        return $total;
    }

    /** The rows of 1.3 created before `$cutoff`: no sealed field, at least one clear one. */
    private static function rowsOf13(DatabaseInterface $db, int $cutoff)
    {
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);

        return $query->from($db->quoteName('#__appointments_bookings'))
            ->where(self::clearCondition($db))->where('NOT ' . self::anySealedCondition($db))->where($db->quoteName('uid') . ' <> ' . $db->quote(''))
            ->where($db->quoteName('created_utc') . ' < ' . $db->quote(gmdate('Y-m-d H:i:s', $cutoff)));
    }

    /**
     * Seal one batch of rows of 1.3, each with a compare-and-set on the whole row.
     *
     * @return  array{rows: int, sealed: int[]}  Rows found, and the ids of those written.
     */
    private static function sealBatch(DatabaseInterface $db, KeyProvider $keys, int $cutoff, int $batch): array
    {
        $data = new BookingData($keys, $cutoff);
        $columns = BookingData::COLUMNS;
        $query = self::rowsOf13($db, $cutoff)->select($db->quoteName(BookingData::readColumns()))
            ->order($db->quoteName('id'))->setLimit(max(1, min(1000, $batch)));
        $rows = $db->setQuery($query)->loadAssocList();
        $written = [];

        foreach ($rows as $row) {
            // A row of 1.3 holds no sealed field (the query takes no other), so every value present is clear.
            $fields = array_intersect_key($row, array_flip($columns));
            $clear = array_filter($fields, static fn ($value): bool => $value !== null);

            if ($clear === [] || array_filter($clear, [BookingData::class, 'isSealed']) !== []) {
                continue;
            }

            $sealed = $data->seal($row);
            $update = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $update->update($db->quoteName('#__appointments_bookings'))->where($db->quoteName('id') . ' = ' . (int) $row['id'])
                ->where($db->quoteName('uid') . ' = ' . $db->quote((string) $row['uid']))
                ->where($db->quoteName('eventtype_id') . ' = ' . (int) $row['eventtype_id'])
                ->where($db->quoteName('host_user_id') . ' = ' . (int) $row['host_user_id'])
                ->where($db->quoteName('created_utc') . ' = ' . $db->quote((string) $row['created_utc']));

            foreach ($clear as $column => $value) {
                $update->set($db->quoteName($column) . ' = ' . $db->quote($sealed[$column]));
            }

            // Compare-and-set on the whole row as read: a field that changed meanwhile (also one
            // that was null, or one sealed by a concurrent write) leaves the row for the next call.
            foreach ($fields as $column => $value) {
                $update->where($db->quoteName($column) . ' COLLATE utf8mb4_bin <=> ' . ($value === null ? 'NULL' : $db->quote($value)));
            }

            $db->setQuery($update)->execute();

            if ($db->getAffectedRows() > 0) {
                $written[] = (int) $row['id'];
            }
        }

        return ['rows' => \count($rows), 'sealed' => $written];
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
            ->where(self::clearCondition($db))->where('NOT ' . self::anySealedCondition($db));

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * True for a row that holds any sealed field (never NULL in SQL, so it can be negated). Such
     * a row is no row of 1.3: a clear value in it was put in later and is neither read nor migrated.
     */
    private static function anySealedCondition(DatabaseInterface $db): string
    {
        return '(' . implode(' OR ', array_map(static fn (string $column): string => '(' . $db->quoteName($column) . ' IS NOT NULL AND '
            . self::sealedCondition($db, $column) . ')', BookingData::COLUMNS)) . ')';
    }

    /** A value is clear unless it has the form of a sealed value (the pattern of BookingData::isSealed(), case-sensitive). */
    private static function clearCondition(DatabaseInterface $db): string
    {
        return '(' . implode(' OR ', array_map(static fn (string $column): string => '(' . $db->quoteName($column) . ' IS NOT NULL AND NOT ('
            . self::sealedCondition($db, $column) . '))', BookingData::COLUMNS)) . ')';
    }

    private static function sealedCondition(DatabaseInterface $db, string $column): string
    {
        return $db->quoteName($column) . ' COLLATE utf8mb4_bin REGEXP ' . $db->quote('^v[12]:[A-Za-z0-9+/]{54,}={0,2}$');
    }

    /** Whether any booking holds a sealed field: then the migration of 1.3 rows is over. */
    private static function anySealed(DatabaseInterface $db): bool
    {
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select('1')->from($db->quoteName('#__appointments_bookings'))->where(self::anySealedCondition($db))->setLimit(1);

        return (bool) $db->setQuery($query)->loadResult();
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

    /**
     * Write the migration option (a token or the end marker), and only that
     * property, in one statement: no other option an administrator saves at the
     * same moment is overwritten.
     */
    private static function setToken(DatabaseInterface $db, string $value, string $expected): void
    {
        if (self::token($db) === $value) {
            return;
        }

        $params = $db->quoteName('params');
        $update = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $update->update($db->quoteName('#__extensions'))
            ->set($params . ' = JSON_SET(COALESCE(NULLIF(TRIM(' . $params . '), ' . $db->quote('') . '), ' . $db->quote('{}') . '), '
                . $db->quote('$.' . BookingData::MIGRATION_OPTION) . ', ' . $db->quote($value) . ')')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_appointments'))
            // Only from the value this caller read: a concurrent run that already wrote the end marker
            // (or a newer token) is never overwritten, so a finished migration stays finished.
            ->where('COALESCE(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(TRIM(' . $params . '), ' . $db->quote('') . '), ' . $db->quote('{}') . '), '
                . $db->quote('$.' . BookingData::MIGRATION_OPTION) . ')), ' . $db->quote('') . ') = ' . $db->quote($expected));
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
            $query->select($db->quoteName(array_merge(['id'], BookingData::readColumns())))->from($db->quoteName('#__appointments_bookings'))
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
        $query->clear('select')->clear('order')->select($db->quoteName(array_merge(['b.id'], BookingData::readColumns('b'))))->order($db->quoteName('b.id') . ' DESC');
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
        $query->clear('select')->clear('order')->select($db->quoteName(array_merge(['b.id'], BookingData::readColumns('b'))))
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
