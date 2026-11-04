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
 * Chain, normative (K = key(TEAM_LOG), every HMAC-SHA256 over CipherSuite::associatedData()):
 * - `row_hash = HMAC(K, prev_hash || canonical(row))`; the canonical row is {@see self::canonical()}
 *   over every column except `id` and `row_hash`.
 * - `prev_hash` of a row is the link of its predecessor, `link(id, row_hash) = HMAC(K, 'teamlog-link',
 *   id, row_hash)`, so the predecessor's id is bound too. The first row of a fresh log links to
 *   `genesis = link(0, 32 zero bytes)`.
 * - The head keeps `last_id` and `last_hash = seal(last_id, row_hash)` with its own label
 *   (`HMAC(K, 'teamlog-head', id, row_hash)`), so no stored link is ever a valid head: cutting rows off
 *   the end and pointing the head at an earlier row needs the key. An empty head is `seal(0, zero)`.
 * - Pruning deletes a prefix of rows older than twelve months, stores `pruned_through_id` and the
 *   anchor `pruned_through_hash = link(pruned_through_id, its row_hash)` (what the first remaining row
 *   links to), and appends a `log.pruned` row whose details repeat both. Verification requires the
 *   newest `log.pruned` row to name exactly the head's anchor, so a prefix removed without the key is
 *   detected as well.
 * - The head is created with the first row. When it has to be created while log rows or internet
 *   calendar data already exist (the head was deleted), it gets a restart marker in
 *   `pruned_through_hash` (while `pruned_through_id` is 0), and the chain stays reported as broken.
 *   An empty log is only intact while no internet calendar data exists.
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

            // A deadlock or lock wait timeout has rolled back the caller's
            // whole transaction (or left it unusable): the caller must know,
            // or it would report success for changes that are gone.
            if (self::isLockConflict($e)) {
                throw $e;
            }
        }
    }

    /**
     * Whether a database error is a deadlock (1213, SQLSTATE 40001) or a lock wait timeout (1205).
     */
    public static function isLockConflict(\Throwable $e): bool
    {
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            if (\in_array((int) $error->getCode(), [1205, 1213], true)
                || preg_match('/deadlock|lock wait timeout|40001/i', $error->getMessage()) === 1) {
                return true;
            }
        }

        return false;
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
            $otherData = $this->teamDataExists();
            $anchored = $this->anchoredLastId();
            $result = self::verifyChain($key, $head, $this->rows(), $otherData, $anchored);
            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $db->transactionRollback(true);

            throw $e;
        }

        // An intact chain moves the file anchor up to its newest row, so a
        // later cut below it, or a database played back, is detected.
        if ($result->intact && $head !== null) {
            $this->anchorLastId((int) $head['last_id']);
        }

        return $result;
    }

    public function headHash(): string
    {
        $this->ensureHead();
        $db = $this->db();
        $hash = $db->setQuery($this->query()->select($db->quoteName('last_hash'))
            ->from($db->quoteName('#__appointments_teamlog_head'))
            ->where($db->quoteName('id') . ' = ' . self::HEAD_ID))->loadResult();

        // No head yet: nothing to show (the constant empty head is not handed out).
        return \is_string($hash) && \strlen($hash) === 32 ? bin2hex($hash) : '';
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
            $anchor = self::link($key, $through, $rowHash);
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
            $lastId = (int) $db->setQuery($this->query()->select($db->quoteName('last_id'))
                ->from($db->quoteName('#__appointments_teamlog_head'))->where($db->quoteName('id') . ' = ' . self::HEAD_ID))->loadResult();
            $this->anchorLastId($lastId);

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
     * @param   bool                                 $otherData  Whether internet calendar data (calendars, devices, grants) exists.
     * @param   int                                  $anchoredLastId  The newest row id the file anchor knows, 0 for none.
     */
    public static function verifyChain(string $key, ?array $head, iterable $rows, bool $otherData = false, int $anchoredLastId = 0): AuditVerification
    {
        $genesis = self::genesis($key);

        // The file anchor knows a newer row than the database: rows were cut
        // off, or the database was played back without its files.
        if ($anchoredLastId > 0 && ($head === null || (int) $head['last_id'] < $anchoredLastId)) {
            return new AuditVerification(false, 0, $anchoredLastId, $head === null ? '' : bin2hex((string) $head['last_hash']));
        }

        if ($head === null) {
            foreach ($rows as $row) {
                return new AuditVerification(false, 0, (int) $row['id'], '');
            }

            // A log that was never written has no head. With calendars,
            // devices or grants present, it was written and then removed.
            return $otherData ? new AuditVerification(false, 0, 0, '') : new AuditVerification(true, 0, null, bin2hex(self::emptyHead($key)));
        }

        $lastHash = (string) $head['last_hash'];
        $lastId = (int) $head['last_id'];
        $prunedThrough = (int) $head['pruned_through_id'];
        $anchor = $head['pruned_through_hash'] ?? null;
        $broken = static fn (int $id): AuditVerification => new AuditVerification(false, 0, $id, bin2hex($lastHash));

        // A restart marker (the head was re-created) or a broken anchor.
        if (($prunedThrough === 0 && $anchor !== null) || ($prunedThrough > 0 && (!\is_string($anchor) || \strlen($anchor) !== 32))) {
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
            $previousHash = $row['row_hash'];
            $expected = self::link($key, $id, $row['row_hash']);
        }

        if ($checked === 0) {
            // Pruning always leaves its own row, so an empty log is only valid
            // when it was never written: no head position and no calendar data.
            $intact = $lastId === 0 && $prunedThrough === 0 && !$otherData && hash_equals(self::emptyHead($key), $lastHash);

            return new AuditVerification($intact, 0, $intact ? null : 0, bin2hex($lastHash));
        }

        if ($lastId !== $previousId || !hash_equals(self::seal($key, $previousId, $previousHash), $lastHash)) {
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

    /**
     * What the next row stores as `prev_hash`. Another label than {@see self::seal()},
     * so a `prev_hash` copied from a row is never a valid head.
     */
    public static function link(string $key, int $id, string $rowHash): string
    {
        return hash_hmac('sha256', CipherSuite::associatedData('teamlog-link', $id, $rowHash), $key, true);
    }

    /**
     * The link of the first row of a log that was never pruned.
     */
    public static function genesis(string $key): string
    {
        return self::link($key, 0, str_repeat("\0", 32));
    }

    /**
     * `last_hash` of a head without rows.
     */
    public static function emptyHead(string $key): string
    {
        return self::seal($key, 0, str_repeat("\0", 32));
    }

    /**
     * The marker of a head that had to be re-created although the log was in use.
     */
    public static function restartMarker(string $key): string
    {
        return hash_hmac('sha256', CipherSuite::associatedData('teamlog-restart'), $key, true);
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

            if ($this->summarised($row)) {
                $db->transactionCommit(true);

                return;
            }

            $row['prev_hash'] = $this->predecessorLink($head, $key);
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
            try {
                // After a deadlock InnoDB has rolled back the whole transaction
                // and the savepoint is gone: roll back completely, so Joomla's
                // transaction depth is right again, and report the conflict.
                self::isLockConflict($e) ? $db->transactionRollback(false) : $db->transactionRollback(true);
            } catch (\Throwable $rollback) {
                // The original error is what the caller needs.
            }

            throw $e;
        }
    }

    /**
     * Repeated failure events are written once per window: `dav.auth_failure`
     * and `dav.locked` per subject, address and minute, `key.mac_failure` per
     * calendar and reason for 24 hours. The first row of a window says so in
     * `details.summary`, the others are not written, so a flood cannot fill
     * the log or slow its verification.
     *
     * @param   array<string, mixed>  $row
     */
    private function summarised(array &$row): bool
    {
        $windows = [AuditEvent::DAV_AUTH_FAILURE => 60, AuditEvent::DAV_LOCKED => 60, AuditEvent::ROW_MAC_FAILURE => 86400];

        if (!isset($windows[$row['event']])) {
            return false;
        }

        $db = $this->db();
        $since = $this->services->now()->setTimezone(new \DateTimeZone('UTC'))
            ->modify('-' . $windows[$row['event']] . ' seconds')->format('Y-m-d H:i:s');
        $query = $this->query()->select($db->quoteName('details'))->from($db->quoteName('#__appointments_teamlog'))
            ->where($db->quoteName('event') . ' = ' . $db->quote($row['event']))
            ->where($db->quoteName('occurred_utc') . ' >= ' . $db->quote($since));
        $same = static fn (string $column, ?string $value): string => $value === null
            ? $db->quoteName($column) . ' IS NULL' : $db->quoteName($column) . ' = ' . $value;

        if ($row['event'] === AuditEvent::ROW_MAC_FAILURE) {
            $query->where($same('calendar_id', $row['calendar_id'] === null ? null : (string) (int) $row['calendar_id']));
        } else {
            $query->where($same('subject_user_id', $row['subject_user_id'] === null ? null : (string) (int) $row['subject_user_id']))
                ->where($same('object_ref', $row['object_ref'] === null ? null : $db->quote((string) $row['object_ref'])))
                ->where($same('ip_hash', $row['ip_hash'] === null ? null : $this->binary($row['ip_hash'])));
        }

        $reason = self::decodeDetails($row['details'])['reason'] ?? null;

        foreach ($db->setQuery($query->setLimit(50))->loadColumn() ?: [] as $details) {
            if ($row['event'] !== AuditEvent::ROW_MAC_FAILURE || (self::decodeDetails($details)['reason'] ?? null) === $reason) {
                return true;
            }
        }

        $details = self::decodeDetails($row['details']) ?? [];
        $details['summary'] = $windows[$row['event']];
        $row['details'] = self::encodeDetails($details);

        return false;
    }

    /**
     * The `prev_hash` of the next row. The head is checked first: its seal must
     * match its newest row, an empty head must be the never-written state, and
     * the file anchor must not know a newer row. Otherwise the next row links
     * to a marker that can never verify, so a manipulated head is not sealed
     * over by the next event.
     *
     * @param   array<string, mixed>  $head
     */
    private function predecessorLink(array $head, string $key): string
    {
        $lastId = (int) $head['last_id'];
        $anchor = $head['pruned_through_hash'] ?? null;
        $gap = hash_hmac('sha256', CipherSuite::associatedData('teamlog-gap', $lastId), $key, true);

        if ($lastId < $this->anchoredLastId()) {
            return $gap;
        }

        if ($lastId === 0) {
            $db = $this->db();
            $rows = (int) $db->setQuery($this->query()->select('COUNT(*)')->from($db->quoteName('#__appointments_teamlog')))->loadResult();
            // The never-written state, before any calendar data exists. The
            // first event of an installation is the recovery key, which is
            // created before any calendar; a head that is empty while calendar
            // data exists was emptied.
            $fresh = (int) $head['pruned_through_id'] === 0 && $anchor === null && $rows === 0
                && \is_string($head['last_hash']) && hash_equals(self::emptyHead($key), $head['last_hash'])
                && !$this->teamDataExists();

            return $fresh ? self::genesis($key) : self::restartMarker($key);
        }

        $db = $this->db();
        $rowHash = $db->setQuery($this->query()->select($db->quoteName('row_hash'))->from($db->quoteName('#__appointments_teamlog'))
            ->where($db->quoteName('id') . ' = ' . $lastId))->loadResult();

        if (\is_string($rowHash) && \strlen($rowHash) === 32 && \is_string($head['last_hash'])
            && hash_equals(self::seal($key, $lastId, $rowHash), $head['last_hash'])) {
            return self::link($key, $lastId, $rowHash);
        }

        return $gap;
    }

    /**
     * Create the head of a log that was never written, before any internet
     * calendar data exists, so the first event later never looks like a
     * re-created head. Called when the log view or the check reads the head.
     */
    private function ensureHead(): void
    {
        $db = $this->db();
        $exists = (int) $db->setQuery($this->query()->select('COUNT(*)')->from($db->quoteName('#__appointments_teamlog_head')))->loadResult() > 0;

        if ($exists || $this->anchoredLastId() > 0 || $this->teamDataExists()
            || (int) $db->setQuery($this->query()->select('COUNT(*)')->from($db->quoteName('#__appointments_teamlog')))->loadResult() > 0) {
            return;
        }

        $db->setQuery('INSERT IGNORE INTO ' . $db->quoteName('#__appointments_teamlog_head') . ' ('
            . implode(',', $db->quoteName(['id', 'last_id', 'last_hash', 'pruned_through_id', 'pruned_through_hash', 'modified_utc']))
            . ') VALUES (' . self::HEAD_ID . ', 0, ' . $this->binary(self::emptyHead($this->key())) . ', 0, NULL, '
            . $db->quote($this->time()) . ')')->execute();
    }

    /**
     * The head row, locked FOR UPDATE. Created with the first row; created
     * with the restart marker when log rows, calendar data or a file anchor
     * exist already (the head was deleted).
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
            $key = $this->key();
            $rows = (int) $db->setQuery($this->query()->select('COUNT(*)')->from($db->quoteName('#__appointments_teamlog')))->loadResult();
            $restart = $rows > 0 || $this->teamDataExists() || $this->anchoredLastId() > 0;

            if ($restart) {
                Log::add('Team calendars: the log head was missing and was re-created; the chain is reported as broken.', Log::WARNING, 'com_appointments');
            }

            $db->setQuery('INSERT IGNORE INTO ' . $table . ' ('
                . implode(',', $db->quoteName(['id', 'last_id', 'last_hash', 'pruned_through_id', 'pruned_through_hash', 'modified_utc']))
                . ') VALUES (' . self::HEAD_ID . ', 0, ' . $this->binary(self::emptyHead($key)) . ', 0, '
                . ($restart ? $this->binary(self::restartMarker($key)) : 'NULL') . ', '
                . $db->quote($this->time()) . ')')->execute();
            $head = $db->setQuery($select)->loadAssoc();
        }

        if (!$head) {
            throw new \RuntimeException('The log head could not be locked.');
        }

        return $head;
    }

    /**
     * The anchor kind of the log in the signed file of work package B, null
     * while that kind does not exist.
     */
    private static function anchorKind(): ?string
    {
        $constant = EpochAnchorInterface::class . '::KIND_LOG';

        return \defined($constant) ? (string) \constant($constant) : null;
    }

    /**
     * The newest row id the file anchor knows, 0 without an anchor.
     */
    private function anchoredLastId(): int
    {
        $kind = self::anchorKind();

        if ($kind === null) {
            return 0;
        }

        try {
            return $this->services->anchor()->minimum($kind, self::HEAD_ID);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Raise the file anchor to a committed newest row id. Only from a verified
     * chain and after a prune, both outside any caller's transaction, so an
     * append that is rolled back later can never leave the anchor ahead.
     */
    private function anchorLastId(int $lastId): void
    {
        $kind = self::anchorKind();

        if ($kind === null || $lastId <= 0) {
            return;
        }

        try {
            $this->services->anchor()->record($kind, self::HEAD_ID, $lastId);
        } catch (\Throwable $e) {
            Log::add('Team calendars: the log anchor could not be written (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
        }
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
