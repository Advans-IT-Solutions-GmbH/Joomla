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
 * `{"v":1,"calendar":{"<id>":<epoch>,…},"user":{"<id>":<epoch>,…},"mac":"<hex>"}`
 * with `mac = HMAC-SHA256(key(TEAM_ANCHOR), "com_appointments/epoch-anchor/v1|" . json(v, calendar, user))`,
 * the maps sorted by id. Writes happen under an exclusive lock of a side file
 * and replace the file atomically (temporary file in the same folder, rename),
 * so a reader sees the old or the new document, never half of one.
 *
 * Not writable: every method answers as if no epoch were anchored and
 * writable() is false. Missing (first use, or deleted) or wrongly signed: the
 * file is rebuilt from the epochs in the database and that is logged once.
 *
 * @since  1.4.0
 */
final class EpochAnchor implements EpochAnchorInterface
{
    private const VERSION = 1;

    private const HEADER = "<?php defined('_JEXEC') or die;\n";

    /**
     * The verified document of this request, null before the first read.
     *
     * @var  array{calendar: array<int, int>, user: array<int, int>}|null
     */
    private $data;

    /**
     * @var  string|null  Overrides the file for the tests; null means the component folder.
     */
    private $path;

    public function __construct(private readonly TeamServices $services, ?string $path = null)
    {
        $this->path = $path;
    }

    public function record(string $kind, int $id, int $epoch): void
    {
        self::assertKind($kind);

        if ($id <= 0 || $epoch <= 0 || !$this->writable()) {
            return;
        }

        $this->locked(function () use ($kind, $id, $epoch): void {
            // Read again under the lock: another request may have raised an epoch.
            $data = $this->load(true);

            // A file kept for the old site secret is never overwritten.
            if ($data === null) {
                return;
            }

            if (($data[$kind][$id] ?? 0) >= $epoch) {
                $this->data = $data;

                return;
            }

            $data[$kind][$id] = $epoch;
            $this->write($data);
            $this->data = $data;
        });
    }

    public function recordAfterCommit(string $kind, int $id): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
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
            $data = $this->parse(is_file($this->file()) ? (string) @file_get_contents($this->file()) : '');

            if ($data === null && $this->writable()) {
                $data = $this->locked(fn (): ?array => $this->load(true));
            }

            $this->data = $data ?? ['calendar' => [], 'user' => []];
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
     * @param   array{calendar: array<int, int>, user: array<int, int>}  $data
     */
    public static function canonical(array $data): string
    {
        $calendar = $data['calendar'];
        $user = $data['user'];
        ksort($calendar, SORT_NUMERIC);
        ksort($user, SORT_NUMERIC);

        return 'com_appointments/epoch-anchor/v1|' . json_encode(
            ['v' => self::VERSION, 'calendar' => (object) $calendar, 'user' => (object) $user],
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * The verified document, rebuilt from the database when the file is
     * missing or its signature does not match. The caller holds the lock.
     *
     * @return  array{calendar: array<int, int>, user: array<int, int>}|null  Null when a file
     *          exists that must be kept as it is (the site secret looks changed).
     */
    private function load(bool $rebuild): ?array
    {
        $raw = is_file($this->file()) ? (string) @file_get_contents($this->file()) : '';
        $data = $this->parse($raw);

        if ($data !== null) {
            return $data;
        }

        $data = ['calendar' => [], 'user' => []];

        // After a change of the site secret every signature fails: rebuilding
        // would sign whatever the database holds under the wrong secret. Keep
        // the file for the old secret and run without the anchor meanwhile.
        if ($raw !== '' && $this->siteSecretSuspect()) {
            return null;
        }

        if (!$rebuild) {
            return $data;
        }

        $data = $this->fromDatabase();
        $this->write($data);
        $this->log($raw === '' ? 'missing' : 'signature');

        return $data;
    }

    /**
     * @return  array{calendar: array<int, int>, user: array<int, int>}|null
     */
    private function parse(string $raw): ?array
    {
        if (!str_starts_with($raw, self::HEADER)) {
            return null;
        }

        $document = json_decode(substr($raw, \strlen(self::HEADER)), true);

        if (!\is_array($document) || ($document['v'] ?? null) !== self::VERSION || !\is_string($document['mac'] ?? null)
            || !\is_array($document['calendar'] ?? null) || !\is_array($document['user'] ?? null)) {
            return null;
        }

        $data = ['calendar' => [], 'user' => []];

        foreach (['calendar', 'user'] as $kind) {
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
     * @param   array{calendar: array<int, int>, user: array<int, int>}  $data
     */
    private function write(array $data): void
    {
        ksort($data['calendar'], SORT_NUMERIC);
        ksort($data['user'], SORT_NUMERIC);
        $key = $this->services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_ANCHOR);
        $mac = hash_hmac('sha256', self::canonical($data), $key);
        CipherSuite::wipe($key);
        $document = self::HEADER . json_encode(
            ['v' => self::VERSION, 'calendar' => (object) $data['calendar'], 'user' => (object) $data['user'], 'mac' => $mac],
            JSON_THROW_ON_ERROR
        ) . "\n";
        $temporary = $this->file() . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $handle = @fopen($temporary, 'xb');
        $written = $handle !== false && @fwrite($handle, $document) === \strlen($document) && @fflush($handle);

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
     * The epochs as the database holds them now (used only when the file is
     * missing or broken, so there is nothing better to compare with).
     *
     * @return  array{calendar: array<int, int>, user: array<int, int>}
     */
    private function fromDatabase(): array
    {
        $db = $this->services->db();
        $data = ['calendar' => [], 'user' => []];

        $keys = $this->services->keys();

        // Only epochs of rows whose MAC verifies: a value a database writer set
        // must not become a permanent lower bound.
        if (method_exists(CalendarService::class, 'macFields')) {
            foreach ($db->setQuery('SELECT * FROM ' . $db->quoteName('#__appointments_calendars'))->loadObjectList() ?: [] as $row) {
                if ((int) $row->id > 0 && (int) $row->grant_epoch > 0
                    && $keys->verifyRowMac('calendars', CalendarService::macFields($row), (string) $row->row_mac)) {
                    $data['calendar'][(int) $row->id] = (int) $row->grant_epoch;
                }
            }
        }

        $names = KeyService::macFields('user_keys');

        foreach ($db->setQuery('SELECT * FROM ' . $db->quoteName('#__appointments_user_keys'))->loadAssocList() ?: [] as $row) {
            $fields = [];

            foreach ($names as $name) {
                $fields[$name] = $row[$name] ?? null;
            }

            if ((int) $row['user_id'] > 0 && (int) $row['device_epoch'] > 0
                && $keys->verifyRowMac('user_keys', $fields, (string) $row['row_mac'])) {
                $data['user'][(int) $row['user_id']] = (int) $row['device_epoch'];
            }
        }

        return $data;
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
        $handle = @fopen($this->file() . '.lock', 'c');

        if ($handle === false || !flock($handle, LOCK_EX)) {
            if ($handle !== false) {
                fclose($handle);
            }

            throw new \RuntimeException('The epoch anchor could not be locked.');
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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
        return $this->path ?? JPATH_ADMINISTRATOR . '/components/com_appointments/data/epochs.php';
    }

    private function log(string $reason): void
    {
        try {
            $this->services->audit()->log(AuditEvent::ROW_MAC_FAILURE, ['details' => ['table' => 'epoch_anchor', 'reason' => $reason]]);
        } catch (\Throwable $e) {
            try {
                Log::add('Internet calendars: the epoch anchor was rebuilt (' . $reason . ').', Log::WARNING, 'com_appointments');
            } catch (\Throwable $ignored) {
            }
        }
    }

    private static function assertKind(string $kind): void
    {
        if ($kind !== self::KIND_CALENDAR && $kind !== self::KIND_USER) {
            throw new \InvalidArgumentException('Unknown epoch kind.');
        }
    }
}
