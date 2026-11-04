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
use Advans\Component\Appointments\Administrator\Helper\Team\AuditVerification;
use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;

defined('_JEXEC') or die;

/**
 * The hash-chained log of the internet calendars (work package F).
 *
 * Chain, normative:
 * - `row_hash = HMAC-SHA256(K, prev_hash || canonical(row))` with `K = key(TEAM_LOG)`; the canonical
 *   row is {@see self::canonical()} over every column except `id` and `row_hash`.
 * - `prev_hash` of a row is the seal of its predecessor, `seal(id, row_hash) = HMAC-SHA256(K,
 *   associatedData('teamlog-head', id, row_hash))`, so the predecessor's id is bound too. The first
 *   row of a fresh log links to `genesis = seal(0, 32 zero bytes)`.
 * - The head row keeps `last_id` and `last_hash = seal(last_id, row_hash of last_id)`. Somebody who
 *   can write the database but does not know the site secret can therefore neither cut rows off the
 *   end (the head seal would no longer match) nor insert, alter, reorder or remove a row inside.
 * - Pruning deletes a prefix of rows older than twelve months, stores `pruned_through_id` and
 *   `pruned_through_hash = seal(pruned_through_id, its row_hash)` in the head (the anchor the first
 *   remaining row links to), and appends a `log.pruned` row whose details repeat both. Verification
 *   requires the newest `log.pruned` row to name exactly the head's anchor, so a prefix removed
 *   without the key is detected as well.
 * - Not detectable without an outside reference: replacing the whole log and its head with an older
 *   copy of themselves. That is why the backend shows the checksum (`last_hash`): an operator who
 *   notes it can compare.
 *
 * Content rules: `details` keeps only short scalar values under simple keys, strings with an `@`
 * are replaced (no addresses), the IP address is stored as a keyed hash only, never in clear.
 * Appending locks the head row FOR UPDATE inside a transaction (a savepoint when the caller has one
 * open), so concurrent requests can never append with the same predecessor. {@see self::log()}
 * never throws: a log failure is reported to Joomla's log without detail and never fails the
 * operation it records.
 *
 * @since  1.4.0
 */
final class AuditService implements AuditServiceInterface
{
    /** The id of the single head row. */
    public const HEAD_ID = 1;

    /** Columns that enter the canonical row, in this order. */
    public const HASHED_COLUMNS = [
        'occurred_utc', 'event', 'actor_user_id', 'actor_device_id', 'subject_user_id',
        'calendar_id', 'object_ref', 'details', 'ip_hash', 'prev_hash',
    ];

    /** Limits of one `details` entry. */
    public const DETAILS_MAX_KEYS = 16;
    public const DETAILS_MAX_LENGTH = 128;

    private const BATCH = 1000;

    public function __construct(private readonly TeamServices $services)
    {
    }

    public function log(string $event, array $context = []): void
    {
        try {
            if (!\in_array($event, AuditEvent::all(), true)) {
                Log::add('Team calendars: an unknown log event was not written.', Log::WARNING, 'com_appointments');

                return;
            }

            $this->append($event, $context);
        } catch (\Throwable $e) {
            // No message text: a database error can quote the values.
            Log::add('Team calendars: a log row could not be written (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
        }
    }

    public function verify(): AuditVerification
    {
        $db = $this->db();
        $key = $this->key();
        // One read-only transaction: InnoDB gives every read the same snapshot,
        // so a row appended while the check runs cannot look like a broken head.
        $db->transactionStart(true);

        try {
            $head = $db->setQuery($this->query()->select('*')->from($db->quoteName('#__appointments_teamlog_head'))
                ->where($db->quoteName('id') . ' = ' . self::HEAD_ID))->loadAssoc() ?: null;
            $otherData = $head === null && $this->teamDataExists();
            $result = self::verifyChain($key, $head, $this->rows(), $otherData);
            $db->transactionCommit(true);

            return $result;
        } catch (\Throwable $e) {
            $db->transactionRollback(true);

            throw $e;
        }
    }

    public function headHash(): string
    {
        $db = $this->db();
        $hash = $db->setQuery($this->query()->select($db->quoteName('last_hash'))
            ->from($db->quoteName('#__appointments_teamlog_head'))
            ->where($db->quoteName('id') . ' = ' . self::HEAD_ID))->loadResult();

        return bin2hex(\is_string($hash) && \strlen($hash) === 32 ? $hash : self::genesis($this->key()));
    }

    public function prune(\DateTimeImmutable $nowUtc): int
    {
        $db = $this->db();
        $key = $this->key();
        $cutoff = $nowUtc->setTimezone(new \DateTimeZone('UTC'))
            ->modify('-' . TeamStates::LOG_RETENTION_MONTHS . ' months')->format('Y-m-d H:i:s');
        $table = $db->quoteName('#__appointments_teamlog');
        $db->transactionStart(true);

        try {
            $head = $this->lockHead();
            $prunedThrough = (int) $head['pruned_through_id'];
            $firstYoung = $db->setQuery($this->query()->select('MIN(' . $db->quoteName('id') . ')')->from($table)
                ->where($db->quoteName('occurred_utc') . ' >= ' . $db->quote($cutoff)))->loadResult();
            $query = $this->query()->select('MAX(' . $db->quoteName('id') . ')')->from($table)
                ->where($db->quoteName('id') . ' > ' . $prunedThrough);

            if ($firstYoung !== null) {
                $query->where($db->quoteName('id') . ' < ' . (int) $firstYoung);
            }

            $through = (int) $db->setQuery($query)->loadResult();

            if ($through <= $prunedThrough) {
                $db->transactionCommit(true);

                return 0;
            }

            $rowHash = (string) $db->setQuery($this->query()->select($db->quoteName('row_hash'))->from($table)
                ->where($db->quoteName('id') . ' = ' . $through))->loadResult();
            $anchor = self::seal($key, $through, $rowHash);
            $db->setQuery($this->query()->delete($table)->where($db->quoteName('id') . ' <= ' . $through))->execute();
            $deleted = (int) $db->getAffectedRows();
            $db->setQuery($this->query()->update($db->quoteName('#__appointments_teamlog_head'))
                ->set($db->quoteName('pruned_through_id') . ' = ' . $through)
                ->set($db->quoteName('pruned_through_hash') . ' = ' . $this->binary($anchor))
                ->set($db->quoteName('modified_utc') . ' = ' . $db->quote($this->time()))
                ->where($db->quoteName('id') . ' = ' . self::HEAD_ID))->execute();
            $this->append(AuditEvent::LOG_PRUNED, ['details' => [
                'through_id' => $through,
                'rows'       => $deleted,
                'anchor'     => bin2hex($anchor),
            ]]);
            $db->transactionCommit(true);

            return $deleted;
        } catch (\Throwable $e) {
            $db->transactionRollback(true);

            throw $e;
        }
    }

    /**
     * Check a chain. Pure, so the unit tests run it against rows they altered.
     *
     * @param   string                               $key        key(TEAM_LOG).
     * @param   array<string, mixed>|null            $head       The head row, null when there is none.
     * @param   iterable<array<string, mixed>>       $rows       Every log row, ascending by id.
     * @param   bool                                 $otherData  Whether internet calendar data exists (only used without a head).
     */
    public static function verifyChain(string $key, ?array $head, iterable $rows, bool $otherData = false): AuditVerification
    {
        $genesis = self::genesis($key);

        if ($head === null) {
            foreach ($rows as $row) {
                return new AuditVerification(false, 0, (int) $row['id'], '');
            }

            // A log that was never written has no head. With calendars,
            // devices or grants present, it was written and then removed.
            return $otherData ? new AuditVerification(false, 0, 0, '') : new AuditVerification(true, 0, null, bin2hex($genesis));
        }

        $lastHash = (string) $head['last_hash'];
        $lastId = (int) $head['last_id'];
        $prunedThrough = (int) $head['pruned_through_id'];
        $anchor = $head['pruned_through_hash'] ?? null;
        $broken = static fn (int $id): AuditVerification => new AuditVerification(false, 0, $id, bin2hex($lastHash));

        if ($prunedThrough > 0 && (!\is_string($anchor) || \strlen($anchor) !== 32)) {
            return $broken(0);
        }

        $expected = $prunedThrough > 0 ? $anchor : $genesis;
        $checked = 0;
        $firstId = 0;
        $previousId = $prunedThrough;
        $latestPrune = null;

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $firstId = $firstId ?: $id;

            if ($id <= $previousId || !\is_string($row['prev_hash']) || !\is_string($row['row_hash'])
                || !hash_equals($expected, $row['prev_hash'])
                || !hash_equals(self::rowHash($key, $row), $row['row_hash'])) {
                return $broken($id);
            }

            if ($row['event'] === AuditEvent::LOG_PRUNED) {
                $latestPrune = self::decodeDetails($row['details']);
            }

            $checked++;
            $previousId = $id;
            $expected = self::seal($key, $id, $row['row_hash']);
        }

        if ($checked === 0) {
            // Pruning always leaves its own row, so an empty log is only valid
            // when it was never written.
            $intact = $lastId === 0 && $prunedThrough === 0 && hash_equals($genesis, $lastHash);

            return new AuditVerification($intact, 0, $intact ? null : 0, bin2hex($lastHash));
        }

        if ($lastId !== $previousId || !hash_equals($expected, $lastHash)) {
            return $broken($previousId);
        }

        if ($prunedThrough > 0 && (!\is_array($latestPrune)
            || (int) ($latestPrune['through_id'] ?? 0) !== $prunedThrough
            || !hash_equals(bin2hex($anchor), (string) ($latestPrune['anchor'] ?? '')))) {
            return $broken($firstId);
        }

        return new AuditVerification(true, $checked, null, bin2hex($lastHash));
    }

    /**
     * The canonical bytes of a row: every hashed column, a null told apart
     * from an empty string, `details` re-encoded so that the database's own
     * JSON normalisation (key order, spacing) does not change the hash.
     *
     * @param   array<string, mixed>  $row
     */
    public static function canonical(array $row): string
    {
        $parts = [];

        foreach (self::HASHED_COLUMNS as $column) {
            $value = $row[$column] ?? null;

            if ($column === 'details' && $value !== null) {
                $decoded = self::decodeDetails($value);
                $value = $decoded === null ? 'invalid:' . (string) $value : self::encodeDetails($decoded);
            }

            $parts[] = $value === null ? 'N' : 'V' . (string) $value;
        }

        return CipherSuite::associatedData('teamlog-row', ...$parts);
    }

    /**
     * @param   array<string, mixed>  $row  With `prev_hash` (32 raw bytes).
     */
    public static function rowHash(string $key, array $row): string
    {
        return hash_hmac('sha256', (string) ($row['prev_hash'] ?? '') . self::canonical($row), $key, true);
    }

    public static function seal(string $key, int $id, string $rowHash): string
    {
        return hash_hmac('sha256', CipherSuite::associatedData('teamlog-head', $id, $rowHash), $key, true);
    }

    public static function genesis(string $key): string
    {
        return self::seal($key, 0, str_repeat("\0", 32));
    }

    /**
     * Keep only what may be in the log: simple keys, scalar values, short
     * strings without control characters and without anything that looks like
     * an address.
     *
     * @param   array<mixed>  $details
     *
     * @return  array<string, scalar|null>|null
     */
    public static function sanitiseDetails(array $details): ?array
    {
        $clean = [];

        foreach ($details as $name => $value) {
            if (\count($clean) >= self::DETAILS_MAX_KEYS) {
                break;
            }

            if (!\is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name) !== 1) {
                continue;
            }

            if (\is_float($value)) {
                $value = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
            }

            if (\is_string($value)) {
                $value = self::cleanText($value, self::DETAILS_MAX_LENGTH);
            } elseif (!\is_int($value) && !\is_bool($value) && $value !== null) {
                continue;
            }

            $clean[$name] = $value;
        }

        ksort($clean, SORT_STRING);

        return $clean === [] ? null : $clean;
    }

    /**
     * @param   array<string, scalar|null>  $details
     */
    public static function encodeDetails(array $details): string
    {
        ksort($details, SORT_STRING);

        return json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return  array<string, mixed>|null  Null when the stored value is not a JSON object.
     */
    public static function decodeDetails(mixed $stored): ?array
    {
        if (\is_array($stored)) {
            return $stored;
        }

        if (!\is_string($stored)) {
            return null;
        }

        try {
            $decoded = json_decode($stored, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        return \is_array($decoded) && ($decoded === [] || !array_is_list($decoded)) ? $decoded : null;
    }

    /**
     * Keyed hash of an address for the log. The address itself is never stored.
     */
    public static function addressHash(string $key, string $ip): ?string
    {
        $ip = trim($ip);

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return hash_hmac('sha256', CipherSuite::associatedData('teamlog-ip', inet_ntop(inet_pton($ip))), $key, true);
    }

    private static function cleanText(string $value, int $length): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';

        if (str_contains($value, '@')) {
            return '[redacted]';
        }

        return mb_substr(trim($value), 0, $length, 'UTF-8');
    }

    /**
     * Append one row. Throws on failure; {@see self::log()} is the caller
     * that swallows, prune() lets it roll the prune back.
     *
     * @param   array<string, mixed>  $context
     */
    private function append(string $event, array $context): void
    {
        $db = $this->db();
        $key = $this->key();
        $id = static fn (mixed $value): ?int => \is_int($value) || (\is_string($value) && ctype_digit($value))
            ? ((int) $value > 0 ? (int) $value : null) : null;
        $details = isset($context['details']) && \is_array($context['details'])
            ? self::sanitiseDetails($context['details']) : null;
        $objectRef = isset($context['object_ref']) && \is_string($context['object_ref'])
            ? self::cleanText($context['object_ref'], 64) : null;
        $row = [
            'occurred_utc'    => null,
            'event'           => $event,
            'actor_user_id'   => $id($context['actor_user_id'] ?? null),
            'actor_device_id' => $id($context['actor_device_id'] ?? null),
            'subject_user_id' => $id($context['subject_user_id'] ?? null),
            'calendar_id'     => $id($context['calendar_id'] ?? null),
            'object_ref'      => $objectRef === '' ? null : $objectRef,
            'details'         => $details === null ? null : self::encodeDetails($details),
            'ip_hash'         => isset($context['ip']) && \is_string($context['ip']) ? self::addressHash($key, $context['ip']) : null,
            'prev_hash'       => null,
        ];
        $db->transactionStart(true);

        try {
            $head = $this->lockHead();
            // The time is taken under the lock, so ids and times ascend together
            // and pruning can remove an exact prefix.
            $row['occurred_utc'] = $this->time();
            $row['prev_hash'] = (string) $head['last_hash'];
            $rowHash = self::rowHash($key, $row);
            $columns = array_merge(self::HASHED_COLUMNS, ['row_hash']);
            $values = [];

            foreach ($columns as $column) {
                $value = $column === 'row_hash' ? $rowHash : $row[$column];
                $values[] = match (true) {
                    $value === null => 'NULL',
                    \in_array($column, ['ip_hash', 'prev_hash', 'row_hash'], true) => $this->binary($value),
                    \is_int($value) => (string) $value,
                    default => $db->quote((string) $value),
                };
            }

            $db->setQuery($this->query()->insert($db->quoteName('#__appointments_teamlog'))
                ->columns($db->quoteName($columns))->values(implode(',', $values)))->execute();
            $newId = (int) $db->insertid();
            $db->setQuery($this->query()->update($db->quoteName('#__appointments_teamlog_head'))
                ->set($db->quoteName('last_id') . ' = ' . $newId)
                ->set($db->quoteName('last_hash') . ' = ' . $this->binary(self::seal($key, $newId, $rowHash)))
                ->set($db->quoteName('modified_utc') . ' = ' . $db->quote($row['occurred_utc']))
                ->where($db->quoteName('id') . ' = ' . self::HEAD_ID))->execute();
            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $db->transactionRollback(true);

            throw $e;
        }
    }

    /**
     * The head row, locked FOR UPDATE; created with the genesis link the first time.
     *
     * @return  array<string, mixed>
     */
    private function lockHead(): array
    {
        $db = $this->db();
        $table = $db->quoteName('#__appointments_teamlog_head');
        $select = (string) $this->query()->select('*')->from($table)->where($db->quoteName('id') . ' = ' . self::HEAD_ID) . ' FOR UPDATE';
        $head = $db->setQuery($select)->loadAssoc();

        if (!$head) {
            $db->setQuery('INSERT IGNORE INTO ' . $table . ' ('
                . implode(',', $db->quoteName(['id', 'last_id', 'last_hash', 'pruned_through_id', 'pruned_through_hash', 'modified_utc']))
                . ') VALUES (' . self::HEAD_ID . ', 0, ' . $this->binary(self::genesis($this->key())) . ', 0, NULL, '
                . $db->quote($this->time()) . ')')->execute();
            $head = $db->setQuery($select)->loadAssoc();
        }

        if (!$head) {
            throw new \RuntimeException('The log head could not be locked.');
        }

        return $head;
    }

    /**
     * @return  \Generator<array<string, mixed>>
     */
    private function rows(): \Generator
    {
        $db = $this->db();
        $after = 0;

        do {
            $rows = $db->setQuery($this->query()->select('*')->from($db->quoteName('#__appointments_teamlog'))
                ->where($db->quoteName('id') . ' > ' . $after)->order($db->quoteName('id') . ' ASC')
                ->setLimit(self::BATCH))->loadAssocList();

            foreach ($rows as $row) {
                $after = (int) $row['id'];

                yield $row;
            }
        } while (\count($rows) === self::BATCH);
    }

    private function teamDataExists(): bool
    {
        $db = $this->db();

        foreach (['#__appointments_calendars', '#__appointments_devices', '#__appointments_calendar_grants'] as $table) {
            if ((int) $db->setQuery($this->query()->select('COUNT(*)')->from($db->quoteName($table)))->loadResult() > 0) {
                return true;
            }
        }

        return false;
    }

    private function binary(string $bytes): string
    {
        return 'UNHEX(' . $this->db()->quote(bin2hex($bytes)) . ')';
    }

    private function key(): string
    {
        return $this->services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_LOG);
    }

    private function time(): string
    {
        return $this->services->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function db(): DatabaseInterface
    {
        return $this->services->db();
    }

    private function query(): QueryInterface
    {
        $db = $this->db();

        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
