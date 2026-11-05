<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\BookingData;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Service\BookingRecords;
use Joomla\Database\DatabaseInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Conference/RuntimeStubs.php';
require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Service/BookingRecords.php';

abstract class BookingRecordsTestDatabase implements DatabaseInterface
{
    abstract public function insertObject($table, &$object, $key = null);
}

final class BookingRecordsTestQuery
{
    public string $operation = 'select';
    public string $selected = '';
    public string $table = '';
    public ?int $bookingId = null;
    public array $sets = [];

    public function select($columns): self
    {
        $this->selected = \is_array($columns) ? implode(',', $columns) : (string) $columns;

        return $this;
    }

    public function from(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    public function where(string $condition): self
    {
        if ($this->bookingId === null && preg_match('/`id`\s*=\s*(\d+)/', $condition, $match)) {
            $this->bookingId = (int) $match[1];
        }

        return $this;
    }

    public function order(string $order): self
    {
        return $this;
    }

    public function setLimit(int $limit): self
    {
        return $this;
    }

    public function update(string $table): self
    {
        $this->operation = 'update';
        $this->table = $table;

        return $this;
    }

    public function set(string $assignment): self
    {
        $this->sets[] = $assignment;

        return $this;
    }
}

final class BookingRecordsTestDatabaseState
{
    public array $rows = [];
    public array $params = [];
    public array $audit = [];
    public ?array $snapshot = null;
    public ?int $failAuditInsertAt = null;
    public ?int $failBookingUpdateAt = null;
    public int $auditInsertAttempts = 0;
    public int $bookingUpdateAttempts = 0;
    public int $affectedRows = 0;
    public int $rollbacks = 0;
    public int $commits = 0;
    public ?BookingRecordsTestQuery $query = null;

    public function start(): void
    {
        $this->snapshot = ['rows' => $this->rows, 'params' => $this->params, 'audit' => $this->audit];
    }

    public function rollback(): void
    {
        if ($this->snapshot !== null) {
            $this->rows = $this->snapshot['rows'];
            $this->params = $this->snapshot['params'];
            $this->audit = $this->snapshot['audit'];
            $this->snapshot = null;
        }

        $this->rollbacks++;
    }

    public function commit(): void
    {
        $this->snapshot = null;
        $this->commits++;
    }

    public function execute(): void
    {
        $this->affectedRows = 0;

        if ($this->query === null || $this->query->operation !== 'update') {
            return;
        }

        if (str_contains($this->query->table, 'appointments_bookings')) {
            $this->bookingUpdateAttempts++;

            if ($this->bookingUpdateAttempts === $this->failBookingUpdateAt) {
                throw new RuntimeException('Injected booking update failure.');
            }

            $id = $this->query->bookingId;

            if ($id === null || !isset($this->rows[$id])) {
                return;
            }

            foreach ($this->query->sets as $assignment) {
                [$column, $value] = explode(' = ', $assignment, 2);
                $this->rows[$id][trim($column, '`')] = trim($value, "'");
            }

            $this->affectedRows = 1;

            return;
        }

        if (str_contains($this->query->table, 'extensions')) {
            $this->params[BookingData::MIGRATION_OPTION] = BookingData::migrationDoneMarker(new KeyProvider('booking-records-unit-secret'));
            $this->affectedRows = 1;
        }
    }

    public function insertAudit(object $audit): void
    {
        $this->auditInsertAttempts++;

        if ($this->auditInsertAttempts === $this->failAuditInsertAt) {
            throw new RuntimeException('Injected audit insertion failure.');
        }

        $this->audit[] = (array) $audit;
    }
}

final class BookingRecordsTest extends TestCase
{
    private const SECRET = 'booking-records-unit-secret';

    public function testAuditInsertionFailureRollsBackTheBatchAndRetryCompletesIt(): void
    {
        $state = $this->databaseState();
        $state->failAuditInsertAt = 2;
        $before = $state->rows;
        $token = $state->params[BookingData::MIGRATION_OPTION];

        try {
            BookingRecords::encryptRemaining($this->database($state), new KeyProvider(self::SECRET), 7);
            self::fail('The injected audit insertion failure was not propagated.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected audit insertion failure.', $exception->getMessage());
        }

        self::assertSame($before, $state->rows, 'A failed audit insert must roll back prior booking updates in the batch.');
        self::assertSame([], $state->audit, 'A failed batch must not leave partial audit rows.');
        self::assertSame($token, $state->params[BookingData::MIGRATION_OPTION], 'A failed batch must not mark the migration complete.');
        self::assertSame(1, $state->rollbacks);
        self::assertSame(0, $state->commits);

        $state->failAuditInsertAt = null;
        $state->auditInsertAttempts = 0;
        $state->bookingUpdateAttempts = 0;
        self::assertSame(2, BookingRecords::encryptRemaining($this->database($state), new KeyProvider(self::SECRET), 7));
        $this->assertCompleted($state);
    }

    public function testLaterBookingUpdateFailureRollsBackEarlierUpdatesAndRetryCompletesIt(): void
    {
        $state = $this->databaseState();
        $state->failBookingUpdateAt = 2;
        $before = $state->rows;
        $token = $state->params[BookingData::MIGRATION_OPTION];

        try {
            BookingRecords::encryptRemaining($this->database($state), new KeyProvider(self::SECRET), 7);
            self::fail('The injected booking update failure was not propagated.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected booking update failure.', $exception->getMessage());
        }

        self::assertSame($before, $state->rows, 'A later booking update failure must roll back earlier updates in the batch.');
        self::assertSame([], $state->audit, 'A failed booking update must not leave audit rows.');
        self::assertSame($token, $state->params[BookingData::MIGRATION_OPTION], 'A failed batch must not mark the migration complete.');
        self::assertSame(1, $state->rollbacks);
        self::assertSame(0, $state->commits);

        $state->failBookingUpdateAt = null;
        $state->auditInsertAttempts = 0;
        $state->bookingUpdateAttempts = 0;
        self::assertSame(2, BookingRecords::encryptRemaining($this->database($state), new KeyProvider(self::SECRET), 7));
        $this->assertCompleted($state);
    }

    public function testExpiredWindowInsideTheLimitNeverEncryptsAutomatically(): void
    {
        $state = $this->databaseState(10);
        $before = $state->rows;
        $token = $state->params[BookingData::MIGRATION_OPTION];
        $keys = new KeyProvider(self::SECRET);
        self::assertNotNull(BookingData::authenticCutoff($token, $keys, time()), 'The fixture token must be expired but inside the limit.');
        self::assertNull(BookingData::migrationCutoff($token, $keys, time()), 'The fixture token must be expired.');

        self::assertSame(0, BookingRecords::migrate($this->database($state), $keys, 200));
        self::assertSame(0, BookingRecords::resume($this->database($state), $keys, 1));
        self::assertSame($before, $state->rows, 'After the window no automatic path may encrypt clear text.');
        self::assertSame([], $state->audit);
        self::assertSame($token, $state->params[BookingData::MIGRATION_OPTION], 'Pending rows must keep the token, not the end marker.');

        self::assertSame(2, BookingRecords::encryptRemaining($this->database($state), $keys, 7));
        $this->assertCompleted($state);
    }

    private function databaseState(int $daysAgo = 100): BookingRecordsTestDatabaseState
    {
        $state = new BookingRecordsTestDatabaseState();
        $keys = new KeyProvider(self::SECRET);
        $cutoff = time() - $daysAgo * 86400;
        $state->params[BookingData::MIGRATION_OPTION] = BookingData::migrationToken(
            $keys,
            $cutoff + BookingData::MIGRATION_DAYS * 86400,
            $cutoff
        );

        foreach ([11, 12] as $id) {
            $row = ['id' => $id, 'eventtype_id' => 3, 'host_user_id' => 7, 'uid' => str_pad(dechex($id), 64, '0', STR_PAD_LEFT),
                'created_utc' => gmdate('Y-m-d H:i:s', $cutoff - 86400)];

            foreach (BookingData::COLUMNS as $column) {
                $row[$column] = null;
            }

            $row['invitee_name'] = 'Legacy Invitee ' . $id;
            $row['invitee_email'] = 'legacy' . $id . '@example.test';
            $state->rows[$id] = $row;
        }

        return $state;
    }

    private function database(BookingRecordsTestDatabaseState $state): DatabaseInterface
    {
        $database = $this->getMockBuilder(BookingRecordsTestDatabase::class)
            ->onlyMethods(['quoteName', 'quote', 'setQuery', 'execute', 'loadResult', 'loadAssoc', 'updateObject',
                'transactionStart', 'transactionCommit', 'transactionRollback', 'insertObject'])
            ->addMethods(['createQuery', 'getQuery', 'loadAssocList', 'getAffectedRows'])
            ->getMock();

        $database->method('quoteName')->willReturnCallback(static function ($name) {
            if (is_array($name)) {
                return array_map(static fn ($part): string => '`' . $part . '`', $name);
            }

            return '`' . $name . '`';
        });
        $database->method('quote')->willReturnCallback(static fn ($value): string => "'" . (string) $value . "'");
        $database->method('createQuery')->willReturnCallback(static fn (): BookingRecordsTestQuery => new BookingRecordsTestQuery());
        $database->method('getQuery')->willReturnCallback(static fn (): BookingRecordsTestQuery => new BookingRecordsTestQuery());
        $database->method('setQuery')->willReturnCallback(static function (BookingRecordsTestQuery $query) use ($database, $state) {
            $state->query = $query;

            return $database;
        });
        $database->method('loadResult')->willReturnCallback(static function () use ($state) {
            if ($state->query !== null && $state->query->selected === 'COUNT(*)') {
                return (string) \count(array_filter($state->rows, static function (array $row): bool {
                    foreach (BookingData::COLUMNS as $column) {
                        if ($row[$column] !== null && BookingData::isSealed((string) $row[$column])) {
                            return false;
                        }
                    }

                    return true;
                }));
            }

            return json_encode($state->params);
        });
        $database->method('loadAssocList')->willReturnCallback(static function () use ($state): array {
            $rows = [];

            foreach ($state->rows as $row) {
                foreach (BookingData::COLUMNS as $column) {
                    if ($row[$column] !== null && BookingData::isSealed((string) $row[$column])) {
                        continue 2;
                    }
                }

                $rows[] = $row;
            }

            return $rows;
        });
        $database->method('execute')->willReturnCallback(static fn () => $state->execute());
        $database->method('getAffectedRows')->willReturnCallback(static fn (): int => $state->affectedRows);
        $database->method('insertObject')->willReturnCallback(static fn (string $table, object $audit) => $state->insertAudit($audit));
        $database->method('transactionStart')->willReturnCallback(static fn () => $state->start());
        $database->method('transactionCommit')->willReturnCallback(static fn () => $state->commit());
        $database->method('transactionRollback')->willReturnCallback(static fn () => $state->rollback());

        return $database;
    }

    private function assertCompleted(BookingRecordsTestDatabaseState $state): void
    {
        self::assertCount(2, $state->audit);
        self::assertSame(['encrypt', 'encrypt'], array_column($state->audit, 'action'));
        self::assertSame(['admin', 'admin'], array_column($state->audit, 'path'));

        foreach ($state->rows as $row) {
            self::assertTrue(BookingData::isSealed($row['invitee_name']));
            self::assertTrue(BookingData::isSealed($row['invitee_email']));
        }

        self::assertTrue(BookingData::migrationDone($state->params[BookingData::MIGRATION_OPTION], new KeyProvider(self::SECRET)));
    }
}
