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
use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use Joomla\CMS\Log\Log;

defined('_JEXEC') or die;

/**
 * The epoch anchor of specification 5.11 (work package B).
 *
 * The file holds one line that stops any direct request (`defined('_JEXEC')
 * or die;`) and, after it, a JSON document that is read as data, never
 * included, so no opcode cache can serve an old copy:
 * `{"v":2,"calendar":{…},"calendar_key":{…},"log":{…},"user":{…},"mac":"<hex>"}`
 * (each map id => value) with
 * `mac = HMAC-SHA256(key(TEAM_ANCHOR), "com_appointments/epoch-anchor/v2|" . json(the document without mac))`,
 * the maps sorted by id. Writes happen under an exclusive lock of a side file
 * and replace the file atomically (temporary file in the same folder, synced,
 * then renamed), so a reader needs no lock and sees the old or the new
 * document, never half of one.
 *
 * - Not writable, or unavailable (no lock, no space, a database error during
 *   a rebuild): a valid file is still enforced; without one nothing is
 *   anchored, the request runs without a lower bound and no row is ever
 *   treated as tampered because of the anchor.
 * - Missing (first use, or deleted) or wrongly signed: rebuilt from the rows of
 *   the database whose MAC verifies, logged once. Never while the site secret
 *   looks changed: the file is then kept for the old secret.
 * - recordAfterCommit() never lets the anchor stand above the database: inside
 *   a transaction it only remembers the row, and at the end of the request it
 *   records the value that was committed (read afresh), or nothing if a
 *   transaction is still open.
 *
 * @since  1.4.0
 */
final class EpochAnchor implements EpochAnchorInterface
{
    private const VERSION = 2;

    private const HEADER = "<?php defined('_JEXEC') or die;\n";

    private const KINDS = [self::KIND_CALENDAR, self::KIND_CALENDAR_KEY, self::KIND_LOG, self::KIND_USER];

    /**
     * The verified document of this request, null before the first read.
     *
     * @var  array<string, array<int, int>>|null
     */
    private $data;

    /**
     * Rows waiting for the end of the request: kind => id => true.
     *
     * @var  array<string, array<int, true>>
     */
    private $queue = [];

    /**
     * @var  bool
     */
    private $shutdownRegistered = false;

    /**
     * @var  string|null  Overrides the stable file for the tests; null means the stable administrator path.
     */
    private $path;

    /**
     * @var  string|null  Overrides the legacy file for tests; null uses the default only with the production stable path.
     */
    private $legacyPath;

    public function __construct(private readonly TeamServices $services, ?string $path = null, ?string $legacyPath = null)
    {
        $this->path = $path;
        $this->legacyPath = $legacyPath;
    }

    public function record(string $kind, int $id, int $epoch): void
    {
        self::assertKind($kind);

        if ($id <= 0 || $epoch <= 0 || !$this->writable()) {
            return;
        }

        $this->locked(function () use ($kind, $id, $epoch): void {
            // Read again under the lock: another request may have raised a value.
            $data = $this->load(true);

            // A file kept for the old site secret is never overwritten.
            if ($data === null) {
                return;
            }

            if (($data[$kind][$id] ?? 0) < $epoch) {
                $data[$kind][$id] = $epoch;
                $this->write($data);
            }

            $this->data = $data;
        });
    }

    public function migrateLegacy(): void
    {
        $legacyFile = $this->legacyFile();

        if ($legacyFile === null || !is_file($legacyFile)) {
            return;
        }

        if (!$this->writable()) {
            throw new \RuntimeException('The stable Appointments epoch anchor is not writable.');
        }

        $this->locked(function () use ($legacyFile): void {
            if (!is_file($legacyFile)) {
                return;
            }

            $this->load(true);

            if (is_file($legacyFile)) {
                $stableData = $this->parse((string) @file_get_contents($this->file()));
                $legacyData = $this->parse((string) @file_get_contents($legacyFile));

                if ($stableData !== null && $legacyData === null) {
                    // Preserve unauthenticated legacy bytes without letting them block a verified anchor.
                    return;
                }

                throw new \RuntimeException('The legacy Appointments epoch anchor could not be retired.');
            }
        });
    }

    public function recordAfterCommit(string $kind, int $id): void
    {
        self::assertKind($kind);

        if ($id <= 0) {
            return;
        }

        if (!$this->insideTransaction()) {
            $this->recordCommitted($kind, $id);

            return;
        }

        $this->queue[$kind][$id] = true;

        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(function (): void {
                $this->flush();
            });
        }
    }

    /**
     * Record every queued row with its committed value. Nothing when a
     * transaction is still open: it would roll back when the connection closes.
     */
    public function flush(): void
    {
        if ($this->queue === [] || $this->insideTransaction()) {
            return;
        }

        $queue = $this->queue;
        $this->queue = [];

        foreach ($queue as $kind => $ids) {
            foreach (array_keys($ids) as $id) {
                try {
                    $this->recordCommitted($kind, $id);
                } catch (\Throwable $e) {
                    $this->warn('an epoch could not be anchored', $e);
                }
            }
        }
    }

    public function minimum(string $kind, int $id): int
    {
        self::assertKind($kind);

        if ($id <= 0) {
            return 0;
        }

        if ($this->data === null) {
            // Reading needs no lock: the file is only ever replaced by rename.
            // A valid file is enforced even when it cannot be written any more.
            $data = $this->readFiles();

            if ($data === null) {
                // A rebuild inside a transaction would read this request's own,
                // uncommitted rows and could anchor values that roll back: there
                // the call works without a lower bound and the rebuild waits for
                // a call outside the transaction.
                if ($this->insideTransaction() || !$this->writable()) {
                    return 0;
                }

                try {
                    $data = $this->locked(fn (): ?array => $this->load(true));
                } catch (\Throwable $e) {
                    // The anchor is unavailable (no lock, no space, a database
                    // error): for this request it works like a folder that cannot
                    // be written, without a lower bound, and is not tried again.
                    // A failure here never makes a row look tampered.
                    $this->warn('the epoch anchor is unavailable', $e);
                    $data = null;
                }
            }

            $this->data = $data ?? self::empty();
        }

        return (int) ($this->data[$kind][$id] ?? 0);
    }

    public function accepts(string $kind, int $id, int $epoch): bool
    {
        return $epoch >= $this->minimum($kind, $id);
    }

    public function writable(): bool
    {
        $folder = \dirname($this->file());

        if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
            return false;
        }

        return is_writable($folder) && (!is_file($this->file()) || is_writable($this->file()));
    }

    /**
     * The canonical bytes the MAC covers.
     *
     * @param   array<string, array<int, int>>  $data
     */
    public static function canonical(array $data): string
    {
        $document = ['v' => self::VERSION];

        foreach (self::KINDS as $kind) {
            $map = $data[$kind] ?? [];
            ksort($map, SORT_NUMERIC);
            $document[$kind] = (object) $map;
        }

        return 'com_appointments/epoch-anchor/v2|' . json_encode($document, JSON_THROW_ON_ERROR);
    }

    /**
     * @return  array<string, array<int, int>>
     */
    private static function empty(): array
    {
        return array_fill_keys(self::KINDS, []);
    }

    /**
     * The committed value of a row of the given kind, read afresh, 0 when the
     * row is unknown or does not verify (nothing is anchored then).
     */
    private function committedValue(string $kind, int $id): int
    {
        $db = $this->services->db();
        $keys = $this->services->keys();

        switch ($kind) {
            case self::KIND_CALENDAR:
                $row = $db->setQuery('SELECT * FROM ' . $db->quoteName('#__appointments_calendars') . ' WHERE ' . $db->quoteName('id') . ' = ' . $id)->loadObject();

                return $row && method_exists(CalendarService::class, 'macFields')
                    && $keys->verifyRowMac('calendars', CalendarService::macFields($row), (string) $row->row_mac)
                    ? (int) $row->grant_epoch : 0;

            case self::KIND_USER:
                $row = $db->setQuery('SELECT * FROM ' . $db->quoteName('#__appointments_user_keys') . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $id)->loadAssoc();

                return $row && $this->userRowVerifies($row) ? (int) $row['device_epoch'] : 0;

            case self::KIND_CALENDAR_KEY:
                return $this->newestCalendarKey($id);

            case self::KIND_LOG:
                // The log head carries no MAC; work package F records it only
                // after an intact chain check.
                return (int) $db->setQuery(
                    'SELECT ' . $db->quoteName('last_id') . ' FROM ' . $db->quoteName('#__appointments_teamlog_head') . ' WHERE ' . $db->quoteName('id') . ' = ' . $id
                )->loadResult();
        }

        return 0;
    }

    private function recordCommitted(string $kind, int $id): void
    {
        $value = $this->committedValue($kind, $id);

        if ($value > 0) {
            $this->record($kind, $id, $value);
        }
    }

    /**
     * @param   array<string, mixed>  $row
     */
    private function userRowVerifies(array $row): bool
    {
        $fields = [];

        foreach (KeyService::macFields('user_keys') as $name) {
            $fields[$name] = $row[$name] ?? null;
        }

        return (int) ($row['user_id'] ?? 0) > 0 && $this->services->keys()->verifyRowMac('user_keys', $fields, (string) ($row['row_mac'] ?? ''));
    }

    /**
     * The newest key version of a calendar whose calendar_keys row verifies.
     */
    private function newestCalendarKey(int $calendarId): int
    {
        $db = $this->services->db();
        $row = $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__appointments_calendar_keys') . ' WHERE ' . $db->quoteName('calendar_id') . ' = ' . $calendarId
            . ' ORDER BY ' . $db->quoteName('key_version') . ' DESC LIMIT 1'
        )->loadAssoc();

        if (!$row) {
            return 0;
        }

        $fields = [];

        foreach (KeyService::macFields('calendar_keys') as $name) {
            $fields[$name] = $row[$name] ?? null;
        }

        return $this->services->keys()->verifyRowMac('calendar_keys', $fields, (string) $row['row_mac']) ? (int) $row['key_version'] : 0;
    }

    /**
     * The verified document, rebuilt from the database when the file is
     * missing or its signature does not match. The caller holds the lock.
     *
     * @return  array<string, array<int, int>>|null  Null when a file exists that must be
     *          kept as it is (the site secret looks changed).
     */
    private function load(bool $rebuild): ?array
    {
        $raw = is_file($this->file()) ? (string) @file_get_contents($this->file()) : '';
        $data = $this->parse($raw);
        $legacyFile = $this->legacyFile();
        $legacyRaw = $legacyFile !== null && is_file($legacyFile) ? (string) @file_get_contents($legacyFile) : '';
        $legacyData = $legacyRaw === '' ? null : $this->parse($legacyRaw);

        if ($data !== null) {
            if ($legacyData !== null) {
                $merged = self::merge($data, $legacyData);

                if (self::canonical($merged) !== self::canonical($data)) {
                    $this->write($merged);
                }

                $this->retireLegacy($legacyFile, $merged);

                return $merged;
            }

            return $data;
        }

        if ($legacyData !== null) {
            $this->write($legacyData);
            $this->retireLegacy($legacyFile, $legacyData);

            return $legacyData;
        }

        // After a change of the site secret every signature fails: rebuilding
        // would sign whatever the database holds under the wrong secret. Keep
        // the file for the old secret and run without the anchor meanwhile.
        if (($raw !== '' || $legacyRaw !== '') && $this->siteSecretSuspect()) {
            return null;
        }

        if (!$rebuild) {
            return self::empty();
        }

        $data = $this->fromDatabase();
        $this->write($data);
        $this->log($raw === '' ? 'missing' : 'signature');

        return $data;
    }

    /**
     * Verify the stable file contains the merged bounds before removing the legacy copy.
     *
     * @param   string                         $legacyFile
     * @param   array<string, array<int, int>> $expected
     */
    private function retireLegacy(string $legacyFile, array $expected): void
    {
        if (!is_file($legacyFile)) {
            return;
        }

        $legacyData = $this->parse((string) @file_get_contents($legacyFile));
        $stableData = $this->parse(is_file($this->file()) ? (string) @file_get_contents($this->file()) : '');

        if ($legacyData === null || $stableData === null) {
            throw new \RuntimeException('The Appointments epoch anchors could not be authenticated.');
        }

        $merged = self::merge($stableData, $legacyData);

        if (self::canonical($merged) !== self::canonical($stableData)) {
            $this->write($merged);
            $stableData = $this->parse((string) @file_get_contents($this->file()));
        }

        if ($stableData === null || self::canonical($stableData) !== self::canonical($merged)
            || self::canonical($merged) !== self::canonical($expected)) {
            throw new \RuntimeException('The stable Appointments epoch anchor could not be verified.');
        }

        if (!@unlink($legacyFile) && is_file($legacyFile)) {
            throw new \RuntimeException('The legacy Appointments epoch anchor could not be removed.');
        }
    }

    /**
     * Read both the stable and legacy locations during upgrades. A request
     * already running old code can still write the legacy file after migration.
     *
     * @return  array<string, array<int, int>>|null
     */
    private function readFiles(): ?array
    {
        $legacyFile = $this->legacyFile();
        $legacyData = $legacyFile !== null && is_file($legacyFile)
            ? $this->parse((string) @file_get_contents($legacyFile))
            : null;
        $data = $this->parse(is_file($this->file()) ? (string) @file_get_contents($this->file()) : '');

        if ($legacyData === null) {
            return $data;
        }

        return $data === null ? $legacyData : self::merge($data, $legacyData);
    }

    /**
     * Keep the highest verified value from each installation-era file.
     *
     * @param   array<string, array<int, int>>  $current
     * @param   array<string, array<int, int>>  $legacy
     *
     * @return  array<string, array<int, int>>
     */
    private static function merge(array $current, array $legacy): array
    {
        foreach (self::KINDS as $kind) {
            foreach ($legacy[$kind] ?? [] as $id => $epoch) {
                if (($current[$kind][$id] ?? 0) < $epoch) {
                    $current[$kind][$id] = $epoch;
                }
            }
        }

        return $current;
    }

    /**
     * @return  array<string, array<int, int>>|null
     */
    private function parse(string $raw): ?array
    {
        if (!str_starts_with($raw, self::HEADER)) {
            return null;
        }

        $document = json_decode(substr($raw, \strlen(self::HEADER)), true);

        if (!\is_array($document) || ($document['v'] ?? null) !== self::VERSION || !\is_string($document['mac'] ?? null)) {
            return null;
        }

        $data = self::empty();

        foreach (self::KINDS as $kind) {
            if (!\is_array($document[$kind] ?? null)) {
                return null;
            }

            foreach ($document[$kind] as $id => $epoch) {
                if (!\is_int($epoch) || (int) $id <= 0 || $epoch <= 0) {
                    return null;
                }

                $data[$kind][(int) $id] = $epoch;
            }
        }

        $key = $this->services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_ANCHOR);
        $expected = hash_hmac('sha256', self::canonical($data), $key);
        CipherSuite::wipe($key);

        return hash_equals($expected, $document['mac']) ? $data : null;
    }

    /**
     * @param   array<string, array<int, int>>  $data
     */
    private function write(array $data): void
    {
        $key = $this->services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_ANCHOR);
        $mac = hash_hmac('sha256', self::canonical($data), $key);
        CipherSuite::wipe($key);
        $document = ['v' => self::VERSION];

        foreach (self::KINDS as $kind) {
            $map = $data[$kind] ?? [];
            ksort($map, SORT_NUMERIC);
            $document[$kind] = (object) $map;
        }

        $document['mac'] = $mac;
        $bytes = self::HEADER . json_encode($document, JSON_THROW_ON_ERROR) . "\n";
        $temporary = $this->file() . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        $written = $handle !== false && @fwrite($handle, $bytes) === \strlen($bytes) && @fflush($handle);

        // On disk before the rename, so a crash never leaves an empty anchor.
        if ($handle !== false) {
            if (\function_exists('fsync')) {
                @fsync($handle);
            }

            fclose($handle);
        }

        foreach (glob(\dirname($this->file()) . '/' . basename($this->file()) . '.*.tmp') ?: [] as $leftover) {
            if ($leftover !== $temporary && @filemtime($leftover) < time() - 3600) {
                @unlink($leftover);
            }
        }

        if (!$written || !@rename($temporary, $this->file())) {
            @unlink($temporary);

            throw new \RuntimeException('The epoch anchor could not be written.');
        }
    }

    /**
     * The values as the database holds them now, from rows whose MAC verifies
     * (used only when the file is missing or broken, so there is nothing
     * better to compare with). A value a database writer set must not become a
     * permanent lower bound. The log head is not rebuilt: work package F
     * records it after its next intact chain check.
     *
     * @return  array<string, array<int, int>>
     */
    private function fromDatabase(): array
    {
        $db = $this->services->db();
        $keys = $this->services->keys();
        $data = self::empty();

        if (method_exists(CalendarService::class, 'macFields')) {
            foreach ($db->setQuery('SELECT * FROM ' . $db->quoteName('#__appointments_calendars'))->loadObjectList() ?: [] as $row) {
                if ((int) $row->id > 0 && (int) $row->grant_epoch > 0
                    && $keys->verifyRowMac('calendars', CalendarService::macFields($row), (string) $row->row_mac)) {
                    $data[self::KIND_CALENDAR][(int) $row->id] = (int) $row->grant_epoch;
                }
            }
        }

        foreach ($db->setQuery('SELECT * FROM ' . $db->quoteName('#__appointments_user_keys'))->loadAssocList() ?: [] as $row) {
            if ((int) $row['device_epoch'] > 0 && $this->userRowVerifies($row)) {
                $data[self::KIND_USER][(int) $row['user_id']] = (int) $row['device_epoch'];
            }
        }

        foreach ($db->setQuery('SELECT DISTINCT ' . $db->quoteName('calendar_id') . ' FROM ' . $db->quoteName('#__appointments_calendar_keys'))->loadColumn() ?: [] as $calendarId) {
            $version = $this->newestCalendarKey((int) $calendarId);

            if ($version > 0) {
                $data[self::KIND_CALENDAR_KEY][(int) $calendarId] = $version;
            }
        }

        return $data;
    }

    /**
     * Whether a transaction is open on the connection. Joomla keeps the depth
     * in a protected property and offers no getter; unknown counts as open, so
     * nothing is anchored that might still roll back.
     */
    private function insideTransaction(): bool
    {
        try {
            $db = $this->services->db();

            return (int) (new \ReflectionProperty($db, 'transactionDepth'))->getValue($db) > 0;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Run under an exclusive lock of the side file `<file>.lock`.
     *
     * @template T
     *
     * @param   callable():T  $operation
     *
     * @return  T
     */
    private function locked(callable $operation): mixed
    {
        $paths = [$this->file()];
        $legacyFile = $this->legacyFile();

        if ($legacyFile !== null && (is_file($legacyFile) || is_file($legacyFile . '.lock'))) {
            $paths[] = $legacyFile;
        }

        sort($paths, SORT_STRING);
        $handles = [];

        try {
            foreach ($paths as $path) {
                $handle = @fopen($path . '.lock', 'c');

                if ($handle === false || !flock($handle, LOCK_EX)) {
                    if ($handle !== false) {
                        fclose($handle);
                    }

                    throw new \RuntimeException('The epoch anchor could not be locked.');
                }

                $handles[] = $handle;
            }

            return $operation();
        } finally {
            foreach (array_reverse($handles) as $handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function legacyFile(): ?string
    {
        if ($this->legacyPath !== null) {
            return $this->legacyPath;
        }

        return $this->path === null ? JPATH_ADMINISTRATOR . '/components/com_appointments/data/epochs.php' : null;
    }

    private function siteSecretSuspect(): bool
    {
        try {
            $keys = $this->services->keys();

            return $keys instanceof KeyService && $keys->siteSecretSuspect();
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function file(): string
    {
        return $this->path ?? JPATH_ADMINISTRATOR . '/components/com_appointments-data/epochs.php';
    }

    private function log(string $reason): void
    {
        try {
            $this->services->audit()->log(AuditEvent::ROW_MAC_FAILURE, ['details' => ['table' => 'epoch_anchor', 'reason' => $reason]]);
        } catch (\Throwable $e) {
            $this->warn('the epoch anchor was rebuilt (' . $reason . ')', $e);
        }
    }

    private function warn(string $what, \Throwable $e): void
    {
        try {
            Log::add('Internet calendars: ' . $what . ' (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
        } catch (\Throwable $ignored) {
        }
    }

    private static function assertKind(string $kind): void
    {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown epoch kind.');
        }
    }
}
