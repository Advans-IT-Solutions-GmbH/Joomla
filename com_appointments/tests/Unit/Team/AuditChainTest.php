<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Service\Team\AuditService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team/AuditServiceInterface.php';
require_once dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team/AuditService.php';

/**
 * The hash chain of the internet calendar log, without a database: rows are
 * built exactly as AuditService appends them, then altered the way somebody
 * with write access to the database (but without the site secret) could.
 */
final class AuditChainTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    /**
     * Append rows like AuditService::append(), with a fake clock.
     *
     * @param   array<int, array{0: string, 1: string, 2?: array<string, mixed>}>  $events  [event, time, details]
     * @param   array<string, mixed>|null  $head  Start from this head (after a prune), or a fresh log.
     *
     * @return  array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function chain(array $events, ?array $head = null, int $firstId = 1): array
    {
        $head ??= ['last_id' => 0, 'last_hash' => AuditService::emptyHead(self::KEY), 'pruned_through_id' => 0, 'pruned_through_hash' => null];
        $rows = [];
        $id = $firstId;
        // What AuditService::predecessorLink() finds: the link of the newest row, else the anchor, else genesis.
        $prev = $head['link'] ?? ($head['pruned_through_hash'] ?? AuditService::genesis(self::KEY));
        unset($head['link']);

        foreach ($events as $event) {
            $details = isset($event[2]) ? AuditService::sanitiseDetails($event[2]) : null;
            $row = [
                'id' => (string) $id, 'occurred_utc' => $event[1], 'event' => $event[0], 'actor_user_id' => '7',
                'actor_device_id' => null, 'subject_user_id' => null, 'calendar_id' => '3', 'object_ref' => null,
                'details' => $details === null ? null : AuditService::encodeDetails($details), 'ip_hash' => null,
                'prev_hash' => $prev,
            ];
            $row['row_hash'] = AuditService::rowHash(self::KEY, $row);
            $head['last_id'] = $id;
            $head['last_hash'] = AuditService::seal(self::KEY, $id, $row['row_hash']);
            $prev = AuditService::link(self::KEY, $id, $row['row_hash']);
            $rows[$id] = $row;
            // Ids may have gaps (a rolled back append), the chain must not care.
            $id += 2;
        }

        return [$head, $rows];
    }

    private function sample(): array
    {
        return $this->chain([
            [AuditEvent::LOGIN_SUCCESS, '2026-01-01 08:00:00'],
            [AuditEvent::DEVICE_CREATED, '2026-01-01 08:01:00', ['device_id' => 4, 'rule_version' => '1']],
            [AuditEvent::GRANT_CREATED, '2026-01-02 09:00:00', ['role' => 'write']],
            [AuditEvent::OBJECT_DELETED, '2026-01-03 10:00:00'],
        ]);
    }

    public function testIntactChainVerifies(): void
    {
        [$head, $rows] = $this->sample();
        $result = AuditService::verifyChain(self::KEY, $head, array_values($rows));

        self::assertTrue($result->intact);
        self::assertSame(4, $result->checkedRows);
        self::assertNull($result->firstBrokenId);
        self::assertSame(bin2hex($head['last_hash']), $result->headHash);
    }

    public function testDatabaseJsonNormalisationDoesNotBreakTheChain(): void
    {
        [$head, $rows] = $this->sample();
        // MySQL returns JSON with its own key order and spacing.
        $rows[3]['details'] = '{"rule_version": "1", "device_id": 4}';

        self::assertTrue(AuditService::verifyChain(self::KEY, $head, array_values($rows))->intact);
    }

    public function testAlteredRowIsDetected(): void
    {
        [$head, $rows] = $this->sample();
        $rows[5]['details'] = '{"role": "manage"}';

        $result = AuditService::verifyChain(self::KEY, $head, array_values($rows));
        self::assertFalse($result->intact);
        self::assertSame(5, $result->firstBrokenId);

        [$head, $rows] = $this->sample();
        $rows[3]['occurred_utc'] = '2026-01-01 08:02:00';
        self::assertSame(3, AuditService::verifyChain(self::KEY, $head, array_values($rows))->firstBrokenId);
    }

    public function testRemovedRowIsDetected(): void
    {
        [$head, $rows] = $this->sample();
        unset($rows[3]);

        $result = AuditService::verifyChain(self::KEY, $head, array_values($rows));
        self::assertFalse($result->intact);
        self::assertSame(5, $result->firstBrokenId);
    }

    public function testInsertedRowIsDetected(): void
    {
        [$head, $rows] = $this->sample();
        $forged = $rows[3];
        $forged['id'] = '4';
        $forged['event'] = AuditEvent::GRANT_REVOKED;
        // Without the key the forger can only copy hashes from other rows.
        $forged['prev_hash'] = $rows[5]['prev_hash'];
        $all = [$rows[1], $rows[3], $forged, $rows[5], $rows[7]];

        $result = AuditService::verifyChain(self::KEY, $head, $all);
        self::assertFalse($result->intact);
        self::assertSame(4, $result->firstBrokenId);
    }

    public function testReorderedRowsAreDetected(): void
    {
        [$head, $rows] = $this->sample();
        [$rows[3]['id'], $rows[5]['id']] = [$rows[5]['id'], $rows[3]['id']];
        $all = [$rows[1], $rows[5], $rows[3], $rows[7]];

        self::assertFalse(AuditService::verifyChain(self::KEY, $head, $all)->intact);
    }

    public function testCutOffEndIsDetected(): void
    {
        // The forger points the head at the new last row with every value
        // stored in the database: its row hash, or the link the deleted row
        // carried (which was the head's value under the old scheme).
        foreach (['row_hash', 'next_prev'] as $copy) {
            [$head, $rows] = $this->sample();
            $head['last_id'] = 5;
            $head['last_hash'] = $copy === 'row_hash' ? $rows[5]['row_hash'] : $rows[7]['prev_hash'];
            unset($rows[7]);

            $result = AuditService::verifyChain(self::KEY, $head, array_values($rows));
            self::assertFalse($result->intact, $copy);
            self::assertSame(5, $result->firstBrokenId);
        }

        [$head, $rows] = $this->sample();
        unset($rows[7]);
        self::assertFalse(AuditService::verifyChain(self::KEY, $head, array_values($rows))->intact, 'Head not updated at all');
    }

    public function testEmptiedLogIsDetected(): void
    {
        [$head] = $this->sample();

        self::assertFalse(AuditService::verifyChain(self::KEY, $head, [])->intact, 'Rows deleted, head kept');
        self::assertFalse(AuditService::verifyChain(self::KEY, null, [], true)->intact, 'Rows and head deleted while calendars exist');
        self::assertTrue(AuditService::verifyChain(self::KEY, null, [], false)->intact, 'A log that was never written');

        // The head reset to the empty state (readable once it was empty) while calendar data exists.
        $empty = ['last_id' => 0, 'last_hash' => AuditService::emptyHead(self::KEY), 'pruned_through_id' => 0, 'pruned_through_hash' => null];
        self::assertFalse(AuditService::verifyChain(self::KEY, $empty, [], true)->intact, 'Emptied with calendar data');
        self::assertTrue(AuditService::verifyChain(self::KEY, $empty, [], false)->intact, 'Empty before any calendar');

        [, $rows] = $this->sample();
        self::assertFalse(AuditService::verifyChain(self::KEY, null, array_values($rows))->intact, 'Head deleted, rows kept');
    }

    public function testRestartedLogStaysBroken(): void
    {
        // Rows and head deleted, then a new event: the head is re-created with the restart marker.
        $restart = ['last_id' => 0, 'last_hash' => AuditService::emptyHead(self::KEY), 'pruned_through_id' => 0,
            'pruned_through_hash' => AuditService::restartMarker(self::KEY)];
        [$head, $rows] = $this->chain([[AuditEvent::LOGIN_FAILURE, '2026-03-01 08:00:00']], $restart);

        $result = AuditService::verifyChain(self::KEY, $head, array_values($rows), true);
        self::assertFalse($result->intact);

        // Removing the marker does not help: the first row links to it, not to genesis.
        $head['pruned_through_hash'] = null;
        self::assertFalse(AuditService::verifyChain(self::KEY, $head, array_values($rows), true)->intact);
    }

    public function testDeadlockIsRecognised(): void
    {
        self::assertTrue(AuditService::isLockConflict(new \RuntimeException('Deadlock found when trying to get lock', 1213)));
        self::assertTrue(AuditService::isLockConflict(new \RuntimeException('x', 0, new \RuntimeException('Lock wait timeout exceeded', 1205))));
        self::assertFalse(AuditService::isLockConflict(new \RuntimeException('Duplicate entry', 1062)));
        self::assertFalse(AuditService::isLockConflict(new \RuntimeException("Duplicate entry '1213' for key", 1062)), 'Numbers in the text do not count');
    }

    public function testWrongKeyIsDetected(): void
    {
        [$head, $rows] = $this->sample();

        self::assertFalse(AuditService::verifyChain(strrev(self::KEY), $head, array_values($rows))->intact);
    }

    /**
     * A prune as AuditService::prune() does it: delete a prefix, anchor the
     * head, append a log.pruned row naming the anchor.
     */
    private function pruned(): array
    {
        [$head, $rows] = $this->sample();
        $through = 3;
        $anchor = AuditService::link(self::KEY, $through, $rows[$through]['row_hash']);
        unset($rows[1], $rows[3]);
        $head['pruned_through_id'] = $through;
        $head['pruned_through_hash'] = $anchor;
        $head['link'] = AuditService::link(self::KEY, 7, $rows[7]['row_hash']);
        [$head, $more] = $this->chain([[AuditEvent::LOG_PRUNED, '2027-02-01 00:00:00', ['through_id' => $through, 'rows' => 2, 'anchor' => bin2hex($anchor)]]], $head, 9);

        return [$head, $rows + $more];
    }

    public function testPrunedChainStillVerifies(): void
    {
        [$head, $rows] = $this->pruned();
        $result = AuditService::verifyChain(self::KEY, $head, array_values($rows));

        self::assertTrue($result->intact);
        self::assertSame(3, $result->checkedRows);
    }

    public function testPrefixRemovedWithoutTheKeyIsDetected(): void
    {
        // Delete one more row at the front and move the anchor along, copying
        // stored values only.
        [$head, $rows] = $this->pruned();
        unset($rows[5]);
        $head['pruned_through_id'] = 5;
        $head['pruned_through_hash'] = $rows[7]['prev_hash'];

        $result = AuditService::verifyChain(self::KEY, $head, array_values($rows));
        self::assertFalse($result->intact, 'The newest log.pruned row names another anchor');

        // A prune of a log that was never pruned: no log.pruned row at all.
        [$head, $rows] = $this->sample();
        unset($rows[1]);
        $head['pruned_through_id'] = 1;
        $head['pruned_through_hash'] = $rows[3]['prev_hash'];
        self::assertFalse(AuditService::verifyChain(self::KEY, $head, array_values($rows))->intact);
    }

    public function testDetailsKeepNoAddressNoContentAndNoNesting(): void
    {
        $details = AuditService::sanitiseDetails([
            'reason' => "no_mfa\n",
            'email' => 'someone@example.test',
            'nested' => ['a' => 1],
            'Bad Key' => 'x',
            'long' => str_repeat('x', 500),
            'ok' => true,
            'count' => 3,
        ]);

        self::assertSame('no_mfa', $details['reason']);
        self::assertSame('[redacted]', $details['email']);
        self::assertArrayNotHasKey('nested', $details);
        self::assertArrayNotHasKey('Bad Key', $details);
        self::assertSame(AuditService::DETAILS_MAX_LENGTH, mb_strlen($details['long']));
        self::assertSame(['count', 'email', 'long', 'ok', 'reason'], array_keys($details));
        self::assertNull(AuditService::sanitiseDetails([]));
        self::assertCount(AuditService::DETAILS_MAX_KEYS, AuditService::sanitiseDetails(array_combine(
            array_map(static fn (int $i): string => 'k' . $i, range(1, 40)), range(1, 40))));
    }

    public function testAddressIsHashedWithTheKeyOnly(): void
    {
        $hash = AuditService::addressHash(self::KEY, '192.0.2.10');

        self::assertSame(32, strlen($hash));
        self::assertNotSame($hash, AuditService::addressHash(strrev(self::KEY), '192.0.2.10'));
        self::assertSame(AuditService::addressHash(self::KEY, '2001:db8::1'), AuditService::addressHash(self::KEY, '2001:0db8:0:0::1'));
        self::assertSame(AuditService::addressHash(self::KEY, '2001:db8:1:2::1'), AuditService::addressHash(self::KEY, '2001:db8:1:2:ffff::9'), 'IPv6 per /64');
        self::assertNotSame(AuditService::addressHash(self::KEY, '2001:db8:1:2::1'), AuditService::addressHash(self::KEY, '2001:db8:1:3::1'));
        self::assertNull(AuditService::addressHash(self::KEY, 'not an address'));
        self::assertNull(AuditService::addressHash(self::KEY, ''));
    }
}
