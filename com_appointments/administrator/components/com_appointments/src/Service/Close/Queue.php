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
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Delivery metadata only: no copy of the booking payload. Close is a separate system with its own
 * data protection handling. Website erasure (retention, self-service, administrator or a privacy
 * request) only drops the local link here; nothing is ever deleted in Close.
 */
final class Queue
{
    public const TABLE = '#__appointments_close_delivery';
    public const LIMITS = '#__appointments_close_limits';
    /** Unreleased evaluation layout; it held erasure obligations that no longer exist. */
    public const LEGACY_TABLE = '#__appointments_close';

    private ?string $profileKey = null;
    /** @var array<string, Client> */
    private array $clients = [];

    /**
     * @param ?\Closure(Profile): \Closure $transport test transport factory
     * @param ?\Closure(array): array      $open      test booking decryption
     * @param ?\Closure(string): string    $translate test translation
     */
    public function __construct(private DatabaseInterface $db, private object $params, private ?\Closure $transport = null,
        private ?\Closure $open = null, private ?\Closure $translate = null, private ?string $timezone = null)
    {
    }

    public static function install(DatabaseInterface $db): void
    {
        $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName(self::LEGACY_TABLE))->execute();
        $db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName(self::TABLE) . ' (
            booking_id INT UNSIGNED NOT NULL PRIMARY KEY,
            profile VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'\',
            uid CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            target_sequence INT UNSIGNED NOT NULL,
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
            mac CHAR(64) NOT NULL,
            INDEX delivery_due (state, next_attempt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        $db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName(self::LIMITS) . ' (
            profile VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            until BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
    }

    /** Called inside the canonical write transaction. No network and no historical backfill. */
    public static function changed(DatabaseInterface $db, array $booking, bool $erased = false, bool $created = false): void
    {
        $id = (int) $booking['id'];
        $table = $db->quoteName(self::TABLE);
        // Never take a locking read or delete on a missing key: InnoDB would keep a gap lock, and concurrent
        // bookings inserting into that gap could deadlock. The caller's booking row lock already serializes
        // every change of this record, so a plain existence check is sufficient.
        $exists = $db->setQuery('SELECT booking_id FROM ' . $table . ' WHERE booking_id = ' . $id)->loadResult() !== null;
        if ($erased) {
            // Close is isolated from website retention and privacy handling: only the local link goes.
            if ($exists) {
                $db->setQuery('DELETE FROM ' . $table . ' WHERE booking_id = ' . $id)->execute();
            }
            return;
        }
        $old = $exists ? $db->setQuery('SELECT * FROM ' . $table . ' WHERE booking_id = ' . $id . ' FOR UPDATE')->loadAssoc() : null;
        if (!$exists) {
            $params = ComponentHelper::getParams('com_appointments');
            if (!$created || (int) $params->get('close_enabled', 0) !== 1) {
                return;
            }
            try {
                $profile = (new Settings($params))->profileFor((int) ($booking['eventtype_id'] ?? 0));
                if ($profile === null) {
                    return;
                }
                $key = $profile->key;
            } catch (\InvalidArgumentException|\JsonException $e) {
                // Never fail a booking on CRM configuration; the worker resolves the profile later and shows the error.
                $key = '';
            }
            $values = ['booking_id' => $id, 'profile' => $key, 'uid' => (string) $booking['uid'],
                'target_sequence' => (int) $booking['sequence'], 'state' => 'pending', 'operation' => '', 'reason' => '',
                'binding' => '', 'organization_id' => '', 'lead_id' => '', 'contact_id' => '', 'contact_created' => 0, 'activity_id' => '',
                'attempts' => 0, 'next_attempt' => 0, 'last_attempt' => 0];
            $row = (object) ($values + ['mac' => self::mac($values)]);
            if (!$db->insertObject(self::TABLE, $row)) {
                throw new \RuntimeException('Close delivery could not be queued.');
            }
            return;
        }
        if (!$old) {
            return;
        }
        self::verify($old);
        if (!hash_equals((string) $old['uid'], (string) $booking['uid'])) {
            throw new \RuntimeException('Close booking identity does not match.');
        }
        if ((int) $booking['sequence'] <= (int) $old['target_sequence']) {
            return;
        }
        // Uncertain POSTs remain uncertain even when a newer change arrives.
        $change = ['target_sequence' => (int) $booking['sequence'],
            'state' => $old['state'] === 'uncertain' ? 'uncertain' : 'pending', 'next_attempt' => 0];
        $object = (object) (['booking_id' => $id] + $change + ['mac' => self::mac(array_replace($old, $change))]);
        if (!$db->updateObject(self::TABLE, $object, 'booking_id')) {
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

    /** Read-only remote reconciliation. It never authorizes another POST. */
    public function reconcile(int $id, string $remoteId): void
    {
        if ((int) $this->params->get('close_enabled', 0) !== 1) {
            throw new \RuntimeException('Close delivery is disabled.');
        }
        $settings = new Settings($this->params);
        $lock = $this->lockName();
        if ((int) $this->db->setQuery('SELECT GET_LOCK(' . $this->db->quote($lock) . ', 0)')->loadResult() !== 1) {
            throw new \RuntimeException('Close delivery is already running.');
        }
        try {
            $this->db->transactionStart();
            try {
                $booking = $this->booking($id);
                $row = $this->row($id, true);
                $profile = $settings->profile((string) $row['profile']);
                if ($row['state'] !== 'uncertain' || $profile === null || !hash_equals($row['binding'], $profile->binding)
                    || !$booking || !hash_equals($row['uid'], (string) $booking['uid'])) {
                    throw new \RuntimeException('This transfer cannot be reconciled.');
                }
                if (($this->limits()[$profile->key] ?? 0) > time()) {
                    throw new \RuntimeException('Close delivery is waiting for its rate limit.');
                }
                $this->profileKey = $profile->key;
                $client = $this->client($profile);
                if ($row['organization_id'] === '' || !hash_equals($row['organization_id'], $client->organization())) {
                    throw new DeliveryException('blocked', 'organization_changed');
                }
                if ($row['operation'] === 'create_activity') {
                    $note = $profile->mode === Profile::MODE_NOTE;
                    $remoteId = Settings::id($remoteId, 'acti');
                    $remote = $client->request('GET', $this->activityPath($profile) . $remoteId . '/');
                    $marker = $note ? str_contains((string) ($remote['note'] ?? ''), $row['uid'])
                        : ($remote['custom.' . $profile->fields['uid']] ?? '') === $row['uid']
                            && ($remote['custom_activity_type_id'] ?? '') === $profile->type;
                    if (($remote['id'] ?? '') !== $remoteId || ($remote['lead_id'] ?? '') !== $row['lead_id']
                        || ($remote['contact_id'] ?? '') !== $row['contact_id'] || !$marker) {
                        throw new \RuntimeException('The remote activity does not match this booking.');
                    }
                    $change = ['activity_id' => $remoteId];
                } elseif ($row['operation'] === 'create_contact' && $booking['anonymised_utc'] === null) {
                    $remoteId = Settings::id($remoteId, 'cont');
                    $remote = $client->request('GET', '/api/v1/contact/' . $remoteId . '/');
                    $opened = $this->openBooking($booking);
                    if (!empty($opened[BookingData::UNVERIFIED]) || !filter_var($opened['invitee_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                        throw new \RuntimeException('The canonical booking cannot be verified.');
                    }
                    $matches = false;
                    foreach ($remote['emails'] ?? [] as $address) {
                        $matches = $matches || strcasecmp(trim((string) ($address['email'] ?? '')), trim((string) $opened['invitee_email'])) === 0;
                    }
                    if (!$matches || ($remote['id'] ?? '') !== $remoteId) {
                        throw new \RuntimeException('The remote contact does not match this booking.');
                    }
                    $change = ['contact_id' => $remoteId, 'lead_id' => Settings::id((string) ($remote['lead_id'] ?? ''), 'lead'),
                        'contact_created' => 1];
                } else {
                    throw new \RuntimeException('This transfer cannot be reconciled.');
                }
                $this->save($id, $change + ['state' => 'pending', 'reason' => '', 'operation' => '', 'next_attempt' => 0]);
                $this->db->transactionCommit();
            } catch (\Throwable $e) {
                $this->db->transactionRollback();
                if ($e instanceof DeliveryException && $e->reason === 'rate_limit' && $this->profileKey !== null) {
                    $this->limit($this->profileKey, time() + $e->delay);
                }
                throw $e;
            }
        } finally {
            $this->profileKey = null;
            $this->db->setQuery('SELECT RELEASE_LOCK(' . $this->db->quote($lock) . ')')->loadResult();
        }
    }

    public function run(int $limit = 20, bool $retryBlocked = false): array
    {
        $counts = ['processed' => 0, 'failed' => 0, 'disabled' => 0, 'deferred' => 0];
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
            $states = $retryBlocked ? "'pending','retry','blocked'" : "'pending','retry'";
            $due = ' WHERE state IN (' . $states . ')'
                . ' AND (next_attempt <= ' . time() . ($retryBlocked ? " OR state = 'blocked'" : '') . ')';
            // Paused profiles must not fill the batch and starve the other organisations.
            $paused = array_map(fn (string $key): string => $this->db->quote($key), array_keys($this->limits()));
            if ($paused !== []) {
                $counts['deferred'] = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->table() . $due
                    . ' AND profile IN (' . implode(',', $paused) . ')')->loadResult();
                $due .= ' AND profile NOT IN (' . implode(',', $paused) . ')';
            }
            $rows = $this->db->setQuery('SELECT * FROM ' . $this->table() . $due
                . ' ORDER BY last_attempt ASC, booking_id ASC LIMIT ' . max(1, min(100, $limit)))->loadAssocList();
            foreach ($rows as $row) {
                $counts['processed']++;
                // Destination binding, contact creation and activity are separate crash-safe checkpoints.
                for ($step = 0; $step < 4; $step++) {
                    $this->profileKey = null;
                    try {
                        $result = $this->deliver((int) $row['booking_id'], $settings);
                        if ($result === 'deferred') {
                            $counts['deferred']++;
                        }
                        if ($result !== 'next') {
                            break;
                        }
                    } catch (DeliveryException $e) {
                        $change = ['state' => $e->state, 'reason' => $e->reason, 'next_attempt' => time() + $e->delay];
                        if ($e->reason === 'rate_limit' && $this->profileKey !== null) {
                            // Rate limits are per Close organization: other profiles continue.
                            $this->limit($this->profileKey, time() + $e->delay);
                        }
                        $this->failure((int) $row['booking_id'], $change);
                        $counts['failed']++;
                        break;
                    } catch (\Throwable $e) {
                        // Do not turn a persisted uncertain write intent into a retry.
                        $current = $this->find((int) $row['booking_id']);
                        if ($current !== null) {
                            $this->failure((int) $row['booking_id'], ['state' => $current['state'] === 'uncertain' ? 'uncertain' : 'blocked',
                                'reason' => 'delivery_error']);
                        }
                        $counts['failed']++;
                        break;
                    }
                }
            }
        } finally {
            $this->profileKey = null;
            $this->db->setQuery('SELECT RELEASE_LOCK(' . $this->db->quote($lock) . ')')->loadResult();
        }
        $counts['failed'] = max($counts['failed'], (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->table()
            . " WHERE state IN ('uncertain','blocked')")->loadResult());
        return $counts;
    }

    /** @return 'stop'|'next'|'deferred' */
    private function deliver(int $id, Settings $settings): string
    {
        $db = $this->db;
        $db->transactionStart();
        try {
            $booking = $this->booking($id);
            $row = $this->find($id, true);
            if ($row === null) {
                $db->transactionCommit();
                return 'stop';
            }
            if (!$booking || $booking['anonymised_utc'] !== null) {
                // Erasure normally removes the link in its own transaction; drop any leftover link.
                $db->setQuery('DELETE FROM ' . $this->table() . ' WHERE booking_id = ' . $id)->execute();
                $db->transactionCommit();
                return 'stop';
            }
            if (!hash_equals($row['uid'], (string) $booking['uid'])) {
                throw new DeliveryException('blocked', 'booking_identity');
            }
            if ($row['state'] === 'uncertain') {
                $db->transactionCommit();
                return 'stop';
            }
            if ($row['profile'] === '') {
                $resolved = $settings->profileFor((int) $booking['eventtype_id']);
                if ($resolved === null) {
                    // The event type has no profile: it is not transferred.
                    $db->setQuery('DELETE FROM ' . $this->table() . ' WHERE booking_id = ' . $id)->execute();
                    $db->transactionCommit();
                    return 'stop';
                }
                $this->save($id, ['profile' => $resolved->key]);
                $row['profile'] = $resolved->key;
            }
            $profile = $settings->profile($row['profile']);
            if ($profile === null) {
                throw new DeliveryException('blocked', 'profile_missing');
            }
            $this->profileKey = $profile->key;
            if ($row['binding'] !== '' && !hash_equals($row['binding'], $profile->binding)) {
                throw new DeliveryException('blocked', 'profile_changed');
            }
            if (($this->limits()[$profile->key] ?? 0) > time()) {
                $db->transactionCommit();
                return 'deferred';
            }
            $opened = $this->openBooking($booking);
            if (!empty($opened[BookingData::UNVERIFIED])) {
                throw new DeliveryException('blocked', 'unverified_booking');
            }
            $client = $this->client($profile);
            // Prove the destination before any personal data leaves: the authenticated organisation
            // must be the one this record is bound to.
            $organization = $client->organization();
            if ($row['organization_id'] !== '' && !hash_equals($row['organization_id'], $organization)) {
                throw new DeliveryException('blocked', 'organization_changed');
            }
            if ($row['organization_id'] === '' || $row['binding'] === '') {
                // Commit the destination before the first request that carries personal data, so a failed
                // search cannot roll it back and let a later key of another organisation receive the booking.
                $this->save($id, ['organization_id' => $organization, 'binding' => $profile->binding]);
                $db->transactionCommit();
                return 'next';
            }
            if ($row['contact_id'] === '') {
                $client->validateLeadFields();
                $match = $client->contact((string) ($opened['invitee_email'] ?? ''));
                if ($match !== null) {
                    // Existing leads and contacts are never changed.
                    $this->save($id, $match + ['binding' => $profile->binding, 'state' => 'pending', 'reason' => '']);
                    $db->transactionCommit();
                    return 'next';
                }
                $payload = $client->lead($opened);
                $method = 'POST';
                $path = '/api/v1/lead/';
                $operation = 'create_contact';
            } else {
                $context = $this->context($opened, $profile, (int) $row['contact_created'] === 1);
                if ($profile->mode === Profile::MODE_ACTIVITY) {
                    $client->validateActivityFields();
                    $payload = $client->activity($opened, $context);
                } else {
                    $payload = $client->note($opened, $context, $this->translator());
                }
                $method = $row['activity_id'] === '' ? 'POST' : 'PUT';
                $path = $this->activityPath($profile) . ($method === 'PUT' ? Settings::id($row['activity_id'], 'acti') . '/' : '');
                if ($method === 'POST') {
                    $payload['lead_id'] = Settings::id($row['lead_id'], 'lead');
                    $payload['contact_id'] = Settings::id($row['contact_id'], 'cont');
                } else {
                    unset($payload['custom_activity_type_id']);
                }
                $operation = $method === 'POST' ? 'create_activity' : 'update_activity';
            }
            $sequence = (int) $row['target_sequence'];
            $create = $method === 'POST';
            // Persist BEFORE the request; a crash cannot replay an uncertain POST.
            $this->save($id, ['state' => $create ? 'uncertain' : 'retry', 'operation' => $operation,
                'binding' => $profile->binding, 'reason' => 'in_flight', 'attempts' => (int) $row['attempts'] + 1]);
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }

        $db->transactionStart();
        try {
            // Same lock order as BookingStore; erasure cannot race an outgoing request.
            $currentBooking = $this->booking($id);
            $current = $this->find($id, true);
            if ($current === null) {
                $db->transactionCommit();
                return 'stop';
            }
            if ((int) $current['target_sequence'] !== $sequence || !$currentBooking || $currentBooking['anonymised_utc'] !== null) {
                $this->save($id, ['state' => 'pending', 'reason' => '', 'operation' => '']);
                $db->transactionCommit();
                return 'next';
            }
            $result = $client->request($method, $path, $payload, $create);
            $change = ['state' => 'done', 'reason' => '', 'operation' => '', 'next_attempt' => 0];
            if ($operation === 'create_contact') {
                try {
                    $change['lead_id'] = Settings::id((string) ($result['id'] ?? ''), 'lead');
                    if (count($result['contacts'] ?? []) !== 1) {
                        throw new \UnexpectedValueException();
                    }
                    $change['contact_id'] = Settings::id((string) ($result['contacts'][0]['id'] ?? ''), 'cont');
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
            }
            $this->save($id, $change);
            $db->transactionCommit();
            return $change['state'] === 'pending' ? 'next' : 'stop';
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }
    }

    private function context(array $booking, Profile $profile, bool $created): array
    {
        $title = (string) $this->db->setQuery('SELECT title FROM ' . $this->db->quoteName('#__appointments_eventtypes')
            . ' WHERE id = ' . (int) $booking['eventtype_id'])->loadResult();
        $labels = [];
        if ($profile->noteAnswers !== []) {
            foreach ($this->db->setQuery('SELECT id, label FROM ' . $this->db->quoteName('#__appointments_fields')
                . ' WHERE id IN (' . implode(',', $profile->noteAnswers) . ')')->loadAssocList() as $field) {
                $labels[(int) $field['id']] = (string) $field['label'];
            }
        }
        $zone = $this->timezone ?? (string) Factory::getApplication()->get('offset', 'UTC');
        if (!in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $zone = 'UTC';
        }
        return ['event_title' => $title, 'labels' => $labels, 'contact_created' => $created, 'timezone' => $zone];
    }

    /** Notes use the site's default language, independent of who triggered the delivery. */
    private function translator(): \Closure
    {
        if ($this->translate === null) {
            $tag = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
            $language = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag);
            $language->load('com_appointments', JPATH_ADMINISTRATOR, $tag, true);
            $language->load('com_appointments', JPATH_ADMINISTRATOR . '/components/com_appointments', $tag, true);
            $this->translate = static fn (string $key): string => $language->_($key);
        }
        return $this->translate;
    }

    private function client(Profile $profile): Client
    {
        if (!isset($this->clients[$profile->key]) || $this->clients[$profile->key]->profile !== $profile) {
            try {
                $transport = $this->transport !== null ? ($this->transport)($profile) : Transport::forProfile($profile);
            } catch (\RuntimeException $e) {
                throw new DeliveryException('blocked', 'credential');
            }
            $this->clients[$profile->key] = new Client($profile, $transport);
        }
        return $this->clients[$profile->key];
    }

    private function activityPath(Profile $profile): string
    {
        return $profile->mode === Profile::MODE_NOTE ? '/api/v1/activity/note/' : '/api/v1/activity/custom/';
    }

    private function openBooking(array $booking): array
    {
        return $this->open !== null ? ($this->open)($booking) : BookingData::fromApplication()->open($booking);
    }

    private function booking(int $id): ?array
    {
        $table = $this->db->quoteName('#__appointments_bookings');
        // A locking read on a missing key would hold a gap lock against new bookings; check plainly first.
        if ($this->db->setQuery('SELECT id FROM ' . $table . ' WHERE id = ' . $id)->loadResult() === null) {
            return null;
        }
        return $this->db->setQuery('SELECT * FROM ' . $table . ' WHERE id = ' . $id . ' FOR UPDATE')->loadAssoc() ?: null;
    }

    private function find(int $id, bool $lock = false): ?array
    {
        if ($lock && $this->db->setQuery('SELECT booking_id FROM ' . $this->table() . ' WHERE booking_id = ' . $id)->loadResult() === null) {
            return null;
        }
        $row = $this->db->setQuery('SELECT * FROM ' . $this->table() . ' WHERE booking_id = ' . $id
            . ($lock ? ' FOR UPDATE' : ''))->loadAssoc();
        if (!$row) {
            return null;
        }
        self::verify($row);
        return $row;
    }

    private function row(int $id, bool $lock = false): array
    {
        $row = $this->find($id, $lock);
        if ($row === null) {
            throw new \RuntimeException('Close delivery metadata is missing.');
        }
        return $row;
    }

    private function save(int $id, array $fields): void
    {
        $row = $this->row($id);
        $fields['mac'] = self::mac(array_replace($row, $fields));
        $object = (object) (['booking_id' => $id] + $fields);
        if (!$this->db->updateObject(self::TABLE, $object, 'booking_id')) {
            throw new \RuntimeException('Close delivery metadata could not be saved.');
        }
    }

    private function failure(int $id, array $fields): void
    {
        $this->db->transactionStart();
        try {
            // Erasure may have removed the link after delivery rolled back.
            if ($this->find($id, true) === null) {
                $this->db->transactionCommit();
                return;
            }
            $fields['last_attempt'] = (int) $this->db->setQuery('SELECT COALESCE(MAX(last_attempt), 0) FROM ' . $this->table())->loadResult() + 1;
            $this->save($id, $fields);
            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();
            throw $e;
        }
    }

    /** @return array<string, int> profile key => rate limit end */
    private function limits(): array
    {
        $limits = [];
        foreach ($this->db->setQuery('SELECT profile, until FROM ' . $this->db->quoteName(self::LIMITS)
            . ' WHERE until > ' . time())->loadAssocList() as $row) {
            $limits[(string) $row['profile']] = (int) $row['until'];
        }
        return $limits;
    }

    private function limit(string $profile, int $until): void
    {
        $table = $this->db->quoteName(self::LIMITS);
        $this->db->setQuery('DELETE FROM ' . $table . ' WHERE profile = ' . $this->db->quote($profile))->execute();
        $this->db->setQuery('INSERT INTO ' . $table . ' (profile, until) VALUES (' . $this->db->quote($profile) . ', ' . $until . ')')->execute();
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
        if (!hash_equals(self::mac($row), (string) ($row['mac'] ?? ''))) {
            throw new \RuntimeException('Close delivery metadata is not authentic.');
        }
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
