<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Close;

use Advans\Component\Appointments\Administrator\Helper\BookingData;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/** Delivery metadata only. Personal data is read from the locked canonical booking, never copied. */
final class Queue
{
    public const TABLE = '#__appointments_close';

    public function __construct(private DatabaseInterface $db, private object $params, private ?\Closure $transport = null,
        private ?\Closure $open = null)
    {
    }

    public static function install(DatabaseInterface $db): void
    {
        $db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName(self::TABLE) . ' (
            booking_id INT UNSIGNED NOT NULL PRIMARY KEY,
            uid CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            target_sequence INT UNSIGNED NOT NULL,
            erased TINYINT NOT NULL DEFAULT 0,
            request_id INT UNSIGNED NOT NULL DEFAULT 0,
            state VARCHAR(16) NOT NULL DEFAULT \'pending\',
            operation VARCHAR(24) NOT NULL DEFAULT \'\',
            reason VARCHAR(48) NOT NULL DEFAULT \'\',
            binding CHAR(64) NOT NULL DEFAULT \'\',
            organization_id VARCHAR(110) NOT NULL DEFAULT \'\',
            lead_id VARCHAR(110) NOT NULL DEFAULT \'\',
            contact_id VARCHAR(110) NOT NULL DEFAULT \'\',
            contact_created TINYINT NOT NULL DEFAULT 0,
            activity_id VARCHAR(110) NOT NULL DEFAULT \'\',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt BIGINT NOT NULL DEFAULT 0,
            last_attempt BIGINT NOT NULL DEFAULT 0,
            rate_limit_until BIGINT NOT NULL DEFAULT 0,
            mac CHAR(64) NOT NULL,
            INDEX delivery_due (state, next_attempt),
            INDEX privacy_request (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        $columns = $db->getTableColumns($db->replacePrefix(self::TABLE));
        foreach (['organization_id' => "VARCHAR(110) NOT NULL DEFAULT ''",
            'last_attempt' => 'BIGINT NOT NULL DEFAULT 0', 'rate_limit_until' => 'BIGINT NOT NULL DEFAULT 0'] as $name => $type) {
            if (!isset($columns[$name])) {
                $db->setQuery('ALTER TABLE ' . $db->quoteName(self::TABLE) . ' ADD ' . $db->quoteName($name) . ' ' . $type)->execute();
            }
        }
    }

    /** Called inside the canonical write transaction. No network and no historical backfill. */
    public static function changed(DatabaseInterface $db, array $booking, bool $erased = false, int $requestId = 0, bool $created = false): void
    {
        $params = ComponentHelper::getParams('com_appointments');
        $id = (int) $booking['id'];
        $table = $db->quoteName(self::TABLE);
        $old = $db->setQuery('SELECT * FROM ' . $table . ' WHERE booking_id = ' . $id)->loadAssoc();
        if (!$old && ($erased || !$created || (int) $params->get('close_enabled', 0) !== 1)) {
            return;
        }
        if (!$old) {
            $values = ['booking_id' => $id, 'uid' => (string) $booking['uid'], 'target_sequence' => (int) $booking['sequence'],
                'erased' => 0, 'request_id' => 0, 'state' => 'pending', 'operation' => '', 'reason' => '', 'binding' => '',
                'organization_id' => '', 'lead_id' => '', 'contact_id' => '', 'contact_created' => 0, 'activity_id' => '',
                'attempts' => 0, 'next_attempt' => 0, 'last_attempt' => 0, 'rate_limit_until' => 0];
            $row = (object) ($values + ['mac' => self::mac($values)]);
            if (!$db->insertObject(self::TABLE, $row)) {
                throw new \RuntimeException('Close delivery could not be queued.');
            }
            return;
        }
        $old = $db->setQuery('SELECT * FROM ' . $table . ' WHERE booking_id = ' . $id . ' FOR UPDATE')->loadAssoc();
        self::verify($old);
        if (!hash_equals((string) $old['uid'], (string) $booking['uid'])) {
            throw new \RuntimeException('Close booking identity does not match.');
        }
        if (!$erased && (int) $booking['sequence'] <= (int) $old['target_sequence']) {
            return;
        }
        $change = (object) ['booking_id' => $id, 'target_sequence' => (int) $booking['sequence'],
            'erased' => $erased || (int) $old['erased'] === 1 ? 1 : 0,
            'request_id' => $requestId > 0 ? $requestId : (int) $old['request_id'],
            // Uncertain POSTs remain uncertain even when a newer mutation arrives.
            'state' => $old['state'] === 'uncertain' ? 'uncertain' : 'pending',
            'next_attempt' => $old['reason'] === 'rate_limit' ? (int) $old['next_attempt'] : 0];
        if ($erased && $old['activity_id'] === '' && (int) $old['contact_created'] === 0 && $old['state'] !== 'uncertain') {
            $change->state = 'done';
            $change->reason = '';
            $change->operation = '';
            $change->lead_id = '';
            $change->contact_id = '';
        }
        if ((int) $old['erased'] === 1 && $old['state'] === 'done') {
            $change->state = 'done';
        }
        $change->mac = self::mac(array_replace($old, (array) $change));
        if (!$db->updateObject(self::TABLE, $change, 'booking_id')) {
            throw new \RuntimeException('Close delivery change could not be queued.');
        }
    }

    public function count(): int
    {
        return (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->table())->loadResult();
    }

    public function rows(int $offset = 0): array
    {
        $rows = $this->db->setQuery('SELECT * FROM ' . $this->table() . ' ORDER BY booking_id DESC LIMIT 100 OFFSET '
            . max(0, $offset))->loadAssocList();
        foreach ($rows as $row) {
            self::verify($row);
        }
        return $rows;
    }

    public function pendingPrivacy(int $requestId): int
    {
        return self::pendingForRequest($this->db, $requestId);
    }

    public static function pendingForRequest(DatabaseInterface $db, int $requestId): int
    {
        $rows = $db->setQuery('SELECT * FROM ' . $db->quoteName(self::TABLE) . ' WHERE request_id = ' . $requestId)->loadAssocList();
        $pending = 0;
        foreach ($rows as $row) {
            self::verify($row);
            $pending += $row['state'] !== 'done' ? 1 : 0;
        }
        return $pending;
    }

    public function hasPendingErasure(int $id): bool
    {
        $row = $this->db->setQuery('SELECT * FROM ' . $this->table() . ' WHERE booking_id = ' . $id)->loadAssoc();
        if (!$row) {
            return false;
        }
        self::verify($row);
        return (int) $row['erased'] === 1 && $row['state'] !== 'done';
    }

    /** Read-only remote reconciliation. It never authorizes another POST. */
    public function reconcile(int $id, string $remoteId): void
    {
        if ((int) $this->params->get('close_enabled', 0) !== 1) {
            throw new \RuntimeException('Close delivery is disabled.');
        }
        $settings = new Settings($this->params);
        $client = new Client($settings, $this->transport ?? Transport::forSettings($settings));
        $lock = $this->lockName();
        if ((int) $this->db->setQuery('SELECT GET_LOCK(' . $this->db->quote($lock) . ', 0)')->loadResult() !== 1) {
            throw new \RuntimeException('Close delivery is already running.');
        }
        try {
            $this->db->transactionStart();
            try {
                $booking = $this->booking($id);
                $row = $this->row($id, true);
                if ($row['state'] !== 'uncertain' || !hash_equals($row['binding'], $settings->binding)
                    || ($booking && !hash_equals($row['uid'], (string) $booking['uid']))) {
                    throw new \RuntimeException('This transfer cannot be reconciled.');
                }
                if ($this->cooldown() > time()) {
                    throw new \RuntimeException('Close delivery is waiting for its rate limit.');
                }
                $this->assertOrganization($row, $client);
                if ($row['operation'] === 'create_activity') {
                    $remoteId = Settings::id($remoteId, 'acti');
                    $remote = $client->request('GET', '/api/v1/activity/custom/' . $remoteId . '/');
                    if (($remote['id'] ?? '') !== $remoteId || ($remote['custom_activity_type_id'] ?? '') !== $settings->type
                        || ($remote['lead_id'] ?? '') !== $row['lead_id'] || ($remote['contact_id'] ?? '') !== $row['contact_id']
                        || ($remote['custom.' . $settings->fields['uid']] ?? '') !== $row['uid']) {
                        throw new \RuntimeException('The remote activity does not match this booking.');
                    }
                    $change = ['activity_id' => $remoteId];
                } elseif ($row['operation'] === 'create_contact' && $booking && $booking['anonymised_utc'] === null) {
                    $remoteId = Settings::id($remoteId, 'cont');
                    $remote = $client->request('GET', '/api/v1/contact/' . $remoteId . '/');
                    $opened = $this->open !== null ? ($this->open)($booking) : BookingData::fromApplication()->open($booking);
                    if (!empty($opened[BookingData::UNVERIFIED]) || !filter_var($opened['invitee_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                        throw new \RuntimeException('The canonical booking cannot be verified.');
                    }
                    $matches = false;
                    foreach ($remote['emails'] ?? [] as $address) {
                        $matches = $matches || strcasecmp((string) ($address['email'] ?? ''), (string) $opened['invitee_email']) === 0;
                    }
                    if (!$matches || ($remote['id'] ?? '') !== $remoteId) {
                        throw new \RuntimeException('The remote contact does not match this booking.');
                    }
                    $change = ['contact_id' => $remoteId, 'lead_id' => Settings::id((string) ($remote['lead_id'] ?? ''), 'lead'),
                        'contact_created' => 1];
                } else {
                    throw new \RuntimeException('Erased or unmatched contact creations require manual privacy review.');
                }
                $this->save($id, $change + ['state' => 'pending', 'reason' => '', 'operation' => '', 'next_attempt' => 0]);
                $this->db->transactionCommit();
            } catch (\Throwable $e) {
                $this->db->transactionRollback();
                if ($e instanceof DeliveryException && $e->reason === 'rate_limit') {
                    $this->failure($id, ['reason' => 'rate_limit', 'rate_limit_until' => time() + $e->delay,
                        'next_attempt' => time() + $e->delay]);
                }
                throw $e;
            }
        } finally {
            $this->db->setQuery('SELECT RELEASE_LOCK(' . $this->db->quote($lock) . ')')->loadResult();
        }
    }

    public function run(int $limit = 20, bool $retryBlocked = false, int $requestId = 0): array
    {
        $counts = ['processed' => 0, 'failed' => 0, 'disabled' => 0];
        if ((int) $this->params->get('close_enabled', 0) !== 1) {
            $counts['disabled'] = 1;
            return $counts;
        }
        // Also serialize contact creation across different bookings on this installation.
        $lock = $this->lockName();
        if ((int) $this->db->setQuery('SELECT GET_LOCK(' . $this->db->quote($lock) . ', 0)')->loadResult() !== 1) {
            throw new \RuntimeException('Close delivery is already running.');
        }
        try {
            $settings = new Settings($this->params);
            if (($until = $this->cooldown()) > time()) {
                return $counts + ['cooldown' => $until];
            }
            $client = new Client($settings, $this->transport ?? Transport::forSettings($settings));
            $states = $retryBlocked ? "'pending','retry','blocked'" : "'pending','retry'";
            $rows = $this->db->setQuery('SELECT * FROM ' . $this->table() . ' WHERE state IN (' . $states . ')'
                . ($requestId > 0 ? ' AND request_id = ' . $requestId : '')
                . ' AND (next_attempt <= ' . time() . ($retryBlocked ? " OR state = 'blocked'" : '') . ')'
                . ' ORDER BY last_attempt ASC, booking_id ASC LIMIT ' . max(1, min(100, $limit)))->loadAssocList();
            foreach ($rows as $row) {
                $counts['processed']++;
                // A contact creation and its activity are separate crash-safe checkpoints.
                for ($step = 0; $step < 3; $step++) {
                    try {
                        if ($this->deliver((int) $row['booking_id'], $settings, $client)) {
                            break;
                        }
                    } catch (DeliveryException $e) {
                        $change = ['state' => $e->state, 'reason' => $e->reason, 'next_attempt' => time() + $e->delay];
                        if ($e->reason === 'rate_limit') {
                            $change['rate_limit_until'] = time() + $e->delay;
                        }
                        $this->failure((int) $row['booking_id'], $change);
                        $counts['failed']++;
                        if ($e->reason === 'rate_limit') {
                            break 2;
                        }
                        break;
                    } catch (\Throwable $e) {
                        // Do not turn a persisted uncertain write intent into a retry.
                        $current = $this->row((int) $row['booking_id']);
                        $this->failure((int) $row['booking_id'], ['state' => $current['state'] === 'uncertain' ? 'uncertain' : 'blocked',
                            'reason' => 'delivery_error']);
                        $counts['failed']++;
                        break;
                    }
                }
            }
        } finally {
            $this->db->setQuery('SELECT RELEASE_LOCK(' . $this->db->quote($lock) . ')')->loadResult();
        }
        $counts['failed'] = max($counts['failed'], (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->table()
            . " WHERE state IN ('uncertain','blocked')" . ($requestId > 0 ? ' AND request_id = ' . $requestId : ''))->loadResult());
        return $counts;
    }

    private function deliver(int $id, Settings $settings, Client $client): bool
    {
        $db = $this->db;
        $db->transactionStart();
        try {
            $booking = $this->booking($id);
            $row = $this->row($id, true);
            if ($booking && !hash_equals($row['uid'], (string) $booking['uid'])) {
                throw new DeliveryException('blocked', 'booking_identity');
            }
            if ($row['state'] === 'uncertain') {
                $db->transactionCommit();
                return true;
            }
            if ($row['binding'] !== '' && !hash_equals($row['binding'], $settings->binding)) {
                throw new DeliveryException('blocked', 'mapping_changed');
            }
            $erased = (int) $row['erased'] === 1 || !$booking || $booking['anonymised_utc'] !== null;
            if (!$erased || $row['activity_id'] !== '' || (int) $row['contact_created'] === 1) {
                $this->assertOrganization($row, $client);
            }
            if ($erased && $row['activity_id'] === '') {
                if (!$this->contactCleanupComplete($row, $settings, $client)) {
                    throw new DeliveryException('blocked', 'contact_retention_review');
                }
                // A completed contact is retained; CRM master records are never erased here.
                $this->save($id, ['state' => 'done', 'reason' => '', 'operation' => '', 'lead_id' => '', 'contact_id' => '']);
                $db->transactionCommit();
                return true;
            }
            if ($erased) {
                $path = '/api/v1/activity/custom/' . Settings::id($row['activity_id'], 'acti') . '/';
                if ((int) $settings->params->get('close_delete_activity', 0) !== 1) {
                    if ($client->absent($path)) {
                        $this->save($id, ['activity_id' => '', 'state' => 'pending', 'reason' => '', 'operation' => '']);
                        $db->transactionCommit();
                        return false;
                    }
                    throw new DeliveryException('blocked', 'erasure_review');
                }
                $method = 'DELETE';
                $payload = null;
                $operation = 'delete_activity';
            } else {
                $booking = $this->open !== null ? ($this->open)($booking) : BookingData::fromApplication()->open($booking);
                if (!empty($booking[BookingData::UNVERIFIED])) {
                    throw new DeliveryException('blocked', 'unverified_booking');
                }
                $client->validateFields();
                $payload = $client->activity($booking);
                if ($row['contact_id'] === '') {
                    $match = $client->contact((string) $booking['invitee_email']);
                    if ($match !== null) {
                        $this->save($id, $match + ['binding' => $settings->binding, 'state' => 'pending', 'reason' => '']);
                        $db->transactionCommit();
                        return false;
                    }
                    $contact = ['name' => $booking['invitee_name'], 'emails' => [['email' => $booking['invitee_email'], 'type' => 'office']]];
                    if (!empty($booking['invitee_phone'])) {
                        $contact['phones'] = [['phone' => $booking['invitee_phone'], 'type' => 'office']];
                    }
                    $payload = ['contacts' => [$contact]];
                    $method = 'POST';
                    $path = '/api/v1/lead/';
                    $operation = 'create_contact';
                } else {
                    $payload['contact_id'] = Settings::id($row['contact_id'], 'cont');
                    $method = $row['activity_id'] === '' ? 'POST' : 'PUT';
                    $path = '/api/v1/activity/custom/' . ($method === 'PUT' ? Settings::id($row['activity_id'], 'acti') . '/' : '');
                    if ($method === 'POST') {
                        $payload['lead_id'] = Settings::id($row['lead_id'], 'lead');
                    } else {
                        unset($payload['custom_activity_type_id']);
                    }
                    $operation = $method === 'POST' ? 'create_activity' : 'update_activity';
                }
            }
            $sequence = (int) $row['target_sequence'];
            $create = $method === 'POST';
            // Persist BEFORE the request; a crash cannot replay an uncertain POST.
            $this->save($id, ['state' => $create ? 'uncertain' : 'retry', 'operation' => $operation,
                'binding' => $settings->binding, 'reason' => 'in_flight', 'attempts' => (int) $row['attempts'] + 1]);
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }

        $db->transactionStart();
        try {
            // Same lock order as BookingStore; erasure cannot race an outgoing PII request.
            $currentBooking = $this->booking($id);
            $current = $this->row($id, true);
            if ((int) $current['target_sequence'] !== $sequence || (int) $current['erased'] !== (int) $row['erased']
                || (!$erased && (!$currentBooking || $currentBooking['anonymised_utc'] !== null))) {
                $this->save($id, ['state' => 'pending', 'reason' => '', 'operation' => '']);
                $db->transactionCommit();
                return false;
            }
            $result = $client->request($method, $path, $payload, $create);
            $change = ['state' => 'done', 'reason' => '', 'operation' => '', 'next_attempt' => 0];
            if ($operation === 'create_contact') {
                try {
                    $change['lead_id'] = Settings::id((string) ($result['id'] ?? ''), 'lead');
                    if (count($result['contacts'] ?? []) !== 1) {
                        throw new \UnexpectedValueException();
                    }
                    $change['contact_id'] = Settings::id((string) $result['contacts'][0]['id'], 'cont');
                    $change['contact_created'] = 1;
                } catch (\Throwable $e) {
                    throw new DeliveryException('uncertain', 'invalid_created_contact');
                }
                $change['state'] = 'pending';
            } elseif ($operation === 'create_activity') {
                try {
                    $change['activity_id'] = Settings::id((string) ($result['id'] ?? ''), 'acti');
                } catch (\Throwable $e) {
                    throw new DeliveryException('uncertain', 'invalid_created_activity');
                }
            } elseif ($erased) {
                $change['activity_id'] = '';
                if (!$this->contactCleanupComplete($row, $settings, $client)) {
                    $change['state'] = 'blocked';
                    $change['reason'] = 'contact_retention_review';
                } else {
                    $change += ['lead_id' => '', 'contact_id' => ''];
                }
            }
            $this->save($id, $change);
            $db->transactionCommit();
            return $change['state'] !== 'pending';
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }
    }

    private function booking(int $id): ?array
    {
        return $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName('#__appointments_bookings')
            . ' WHERE id = ' . $id . ' FOR UPDATE')->loadAssoc() ?: null;
    }

    private function row(int $id, bool $lock = false): array
    {
        $row = $this->db->setQuery('SELECT * FROM ' . $this->table() . ' WHERE booking_id = ' . $id
            . ($lock ? ' FOR UPDATE' : ''))->loadAssoc();
        if (!$row) {
            throw new \RuntimeException('Close delivery metadata is missing.');
        }
        self::verify($row);
        return $row;
    }

    private function save(int $id, array $fields): void
    {
        $fields['mac'] = self::mac(array_replace($this->row($id), $fields));
        $object = (object) (['booking_id' => $id] + $fields);
        if (!$this->db->updateObject(self::TABLE, $object, 'booking_id')) {
            throw new \RuntimeException('Close delivery metadata could not be saved.');
        }
    }

    private function failure(int $id, array $fields): void
    {
        $this->db->transactionStart();
        try {
            $this->row($id, true);
            $fields['last_attempt'] = (int) $this->db->setQuery('SELECT COALESCE(MAX(last_attempt), 0) FROM ' . $this->table())->loadResult() + 1;
            $this->save($id, $fields);
            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();
            throw $e;
        }
    }

    private static function mac(array $row): string
    {
        unset($row['mac']);
        ksort($row);
        $row = array_map(static fn ($value): string => (string) $value, $row);
        $key = (new KeyProvider((string) Factory::getApplication()->get('secret', '')))->key('close-delivery-metadata');
        return hash_hmac('sha256', json_encode($row, JSON_THROW_ON_ERROR), $key);
    }

    private static function verify(array $row): void
    {
        if (hash_equals(self::mac($row), (string) ($row['mac'] ?? ''))) {
            return;
        }
        // Earlier evaluation builds had no account binding. Never assign remote IDs from those builds to an account implicitly.
        if (($row['organization_id'] ?? '') === '' && (int) ($row['last_attempt'] ?? 0) === 0 && (int) ($row['rate_limit_until'] ?? 0) === 0) {
            unset($row['organization_id'], $row['last_attempt'], $row['rate_limit_until']);
            if (hash_equals(self::mac($row), (string) ($row['mac'] ?? ''))) {
                return;
            }
        }
        throw new \RuntimeException('Close delivery metadata is not authentic.');
    }

    private function assertOrganization(array $row, Client $client): void
    {
        $organization = $client->organization();
        if ($row['organization_id'] !== '' && !hash_equals($row['organization_id'], $organization)) {
            throw new DeliveryException('blocked', 'organization_changed');
        }
        if ($row['organization_id'] === '') {
            if ($row['activity_id'] !== '' || $row['contact_id'] !== '' || $row['state'] === 'uncertain') {
                throw new DeliveryException('blocked', 'organization_unbound');
            }
            $this->save((int) $row['booking_id'], ['organization_id' => $organization]);
        }
    }

    private function cooldown(): int
    {
        $rows = $this->db->setQuery('SELECT * FROM ' . $this->table() . ' WHERE rate_limit_until > ' . time())->loadAssocList();
        $until = 0;
        foreach ($rows as $row) {
            self::verify($row);
            $until = max($until, (int) $row['rate_limit_until']);
        }
        return $until;
    }

    private function contactCleanupComplete(array $row, Settings $settings, Client $client): bool
    {
        return (int) $row['contact_created'] !== 1 || (int) $settings->params->get('close_retain_contact', 0) === 1
            || $client->absent('/api/v1/contact/' . Settings::id($row['contact_id'], 'cont') . '/');
    }

    private function table(): string
    {
        return $this->db->quoteName(self::TABLE);
    }

    private function lockName(): string
    {
        $database = (string) $this->db->setQuery('SELECT DATABASE()')->loadResult();
        return 'appointments-close-' . substr(hash('sha256', $database . '|' . $this->db->getPrefix()), 0, 32);
    }
}
