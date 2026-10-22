<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\Conference\ConferenceException;
use Advans\Component\Appointments\Administrator\Helper\Conference\ConferenceManager;
use Advans\Component\Appointments\Administrator\Helper\Conference\ConferenceUrl;
use Advans\Component\Appointments\Administrator\Helper\Conference\FixedRoomProvider;
use Advans\Component\Appointments\Administrator\Helper\Conference\GenericRoomProvider;
use Advans\Component\Appointments\Administrator\Helper\Conference\JitsiProvider;
use Advans\Component\Appointments\Administrator\Helper\Conference\MeetingRequest;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Call after the booking transaction commits, before composing notification mail.
 * Returned rows never contain a moderator URL. _conference_changed means a link
 * was successfully provisioned; _conference_error is a safe storage diagnostic.
 * _conference_missing means no persisted row is safely available (deleted, or
 * unreadable when _conference_error is present). Never notify from the old input.
 * Every abort for an active conference booking leaves conference_state 'failed' with
 * a safe conference_error: a missing provider, a provider or configuration error and,
 * as a best effort after the rollback, a storage error. Only a stale snapshot stays
 * inert: the newer revision belongs to the request that wrote it.
 */
final class ConferenceService
{
    public static function forComponent(DatabaseInterface $db): self
    {
        return new self($db, \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments'),
            \Joomla\CMS\Factory::getApplication()->bootComponent('com_appointments')->getConferenceManager());
    }

    public const OPTION_KEYS = [
        'conference_provider',
        'conference_allow_outbound',
        'conference_jitsi_base_url',
        'conference_jitsi_room_pattern',
        'conference_jitsi_room_prefix',
        'conference_jitsi_entropy',
        'conference_jitsi_auth',
        'conference_jitsi_jwt_secret',
        'conference_jitsi_jwt_app_id',
        'conference_jitsi_jwt_audience',
        'conference_jitsi_jwt_issuer',
        'conference_jitsi_jwt_subject',
        'conference_jitsi_host_lead_minutes',
        'conference_jitsi_grace_minutes',
        'conference_generic_template',
        'conference_fixed_url',
    ];

    public const EVENT_OPTION_KEYS = [
        'conference_provider', 'conference_fixed_url', 'conference_room_prefix',
    ];

    public function __construct(
        private DatabaseInterface $db,
        private Registry $params,
        private ?ConferenceManager $manager = null,
        private ?KeyProvider $keys = null
    ) {
    }

    /**
     * A stale snapshot, cancelled/anonymised booking or ineligible retry is inert.
     * Only a successful retry changes SEQUENCE and invalidates old signed links.
     */
    public function provision(array $booking, \DateTimeImmutable $now, bool $retry = false): array
    {
        $started = false;

        try {
            $this->db->transactionStart();
            $started = true;
            $this->lockHost((int) ($booking['host_user_id'] ?? 0));
            $current = $this->booking((int) ($booking['id'] ?? 0), true);

            if (!$current || !$this->sameSnapshot($booking, $current)
                || !in_array((int) $current['state'], [0, 1], true)
                || !empty($current['anonymised_utc'])
                || ($retry && ($current['conference_state'] ?? null) !== 'failed')
            ) {
                $this->db->transactionCommit();

                return $this->safe($current ?? []) + [
                    '_conference_changed' => false,
                    '_conference_stale' => !$current || !$this->sameSnapshot($booking, $current),
                ];
            }

            $fields = $this->emptyFields();
            $succeeded = false;

            try {
                $event = $this->event((int) $current['eventtype_id']);

                if (!$event) {
                    throw new ConferenceException('configuration');
                }

                // An event type with the location type "conference" promises a link. When no
                // provider resolves, that is a failure the administrator must see and retry,
                // not a booking without conference state.
                if (($event['location_type'] ?? '') === 'conference') {
                    $name = $this->selected($event);

                    if ($name === 'none') {
                        throw new ConferenceException('not_configured');
                    }

                    $fields['conference_provider'] = $name;
                    $result = $this->providerManager($name, $event)->createMeeting($name, $this->request($current, $event));
                    $fields['conference_url'] = $result->joinUrl;
                    $fields['conference_room'] = $result->roomIdentifier;
                    $fields['conference_state'] = 'ok';
                    $succeeded = true;
                }
            } catch (\Throwable $error) {
                $fields['conference_state'] = 'failed';
                $fields['conference_error'] = $this->creationError($error);
            }

            if ($retry && $succeeded) {
                $previous = UtcTimestamp::parse((string) $current['modified_utc']);

                if ($previous === null || (int) $current['sequence'] >= 4294967295) {
                    throw new \RuntimeException('Invalid conference retry revision.');
                }

                $fields['sequence'] = (int) $current['sequence'] + 1;
                $timestamp = max($now->getTimestamp(), $previous->getTimestamp() + 1);
                $fields['modified_utc'] = gmdate('Y-m-d H:i:s', $timestamp);
            }

            $this->write((int) $current['id'], $fields);
            $refreshed = $this->booking((int) $current['id'], false);
            $this->db->transactionCommit();

            return $this->safe($refreshed ?? array_replace($current, $fields))
                + ['_conference_changed' => $succeeded, '_conference_stale' => false];
        } catch (\Throwable $error) {
            $this->rollback($started);
            $this->log('Conference status could not be saved.');
            $this->markStorageFailure($booking);

            return $this->refreshSafely((int) ($booking['id'] ?? 0)) + [
                '_conference_changed' => false,
                '_conference_stale' => true,
                '_conference_error' => 'Conference status could not be saved. Please retry.',
            ];
        }
    }

    /**
     * Best-effort teardown after cancellation/erasure commits. A captured
     * pre-erasure row may identify the room, but is never returned or logged.
     * Cleanup errors are transient return metadata, not retained erasure data.
     * Teardown the captured old room, never a newer row's room. If a newer
     * active conference reuses that provider/room, preserve the physical resource
     * too. Clear current metadata only if its identity, times and conference
     * fingerprint still match. Rescheduling clears its old metadata in the
     * booking transaction before calling this method with the captured snapshot.
     */
    public function destroy(array $booking): array
    {
        $started = false;
        $warning = null;

        try {
            $this->db->transactionStart();
            $started = true;
            $this->lockHost((int) ($booking['host_user_id'] ?? 0));
            $current = $this->booking((int) ($booking['id'] ?? 0), true);

            $mayClear = $current && $this->sameIdentity($booking, $current)
                && $this->sameTimes($booking, $current) && $this->sameConference($booking, $current)
                && (!in_array((int) $current['state'], [0, 1], true)
                    || !empty($current['anonymised_utc'])
                    || ((int) ($booking['sequence'] ?? -1) === (int) $current['sequence']
                        && ($booking['modified_utc'] ?? null) === $current['modified_utc']));

            $room = (string) ($booking['conference_room'] ?? '');
            $name = (string) ($booking['conference_provider'] ?? '');
            $reusedByNewerActive = $current && in_array((int) $current['state'], [0, 1], true)
                && empty($current['anonymised_utc']) && ($current['conference_state'] ?? null) === 'ok'
                && ($current['conference_provider'] ?? null) === $name
                && ($current['conference_room'] ?? null) === $room
                && (!$this->sameIdentity($booking, $current) || !$this->sameTimes($booking, $current)
                    || !$this->sameConference($booking, $current)
                    || (int) ($booking['sequence'] ?? -1) !== (int) $current['sequence']
                    || ($booking['modified_utc'] ?? null) !== $current['modified_utc']);

            if ($room !== '' && $name !== '' && !$reusedByNewerActive) {
                try {
                    $event = $this->event((int) ($booking['eventtype_id'] ?? 0)) ?? [];
                    $this->providerManager($name, $event)->destroyMeeting($name, $room);
                } catch (\Throwable $error) {
                    $warning = 'Conference room cleanup failed. Please retry.';
                    $this->log($warning);
                }
            }

            if ($mayClear) {
                $this->write((int) $current['id'], $this->emptyFields());
                $current = $this->booking((int) $current['id'], false) ?? array_replace($current, $this->emptyFields());
            }

            $this->db->transactionCommit();

            return $this->safe($current ?? []) + ['_conference_cleanup_error' => $warning];
        } catch (\Throwable $error) {
            $this->rollback($started);
            $this->log('Conference room cleanup could not be saved.');

            return $this->refreshSafely((int) ($booking['id'] ?? 0)) + [
                '_conference_cleanup_error' => 'Conference room cleanup could not be saved. Please retry.',
            ];
        }
    }

    /**
     * Host-only presentation. Never merge the result into a guest-facing row.
     * The injected manager is deliberately bypassed: no remote create call.
     */
    public function hostUrl(array $booking): ?string
    {
        try {
            $current = $this->booking((int) ($booking['id'] ?? 0), false);

            if (!$current || !$this->sameIdentity($booking, $current)
                || !in_array((int) $current['state'], [0, 1], true)
                || !empty($current['anonymised_utc']) || ($current['conference_state'] ?? null) !== 'ok'
                || empty($current['conference_url']) || empty($current['conference_room'])
            ) {
                return null;
            }

            if (in_array($current['conference_provider'], ['fixed', 'generic'], true)) {
                return ConferenceUrl::validate($current['conference_url']);
            }

            if ($current['conference_provider'] !== 'jitsi') {
                return null;
            }

            $event = $this->event((int) $current['eventtype_id']);

            if (!$event) {
                return null;
            }

            $result = (new JitsiProvider($this->keyProvider(), $this->jitsiConfig($event)))
                ->createMeeting($this->request($current, $event));

            if (!hash_equals($current['conference_room'], $result->roomIdentifier)
                || !hash_equals($current['conference_url'], $result->joinUrl)
            ) {
                return null;
            }

            return $result->hostUrl ?? $result->joinUrl;
        } catch (\Throwable $error) {
            $this->log('Conference host link is unavailable.');

            return null;
        }
    }

    private function providerManager(string $name, array $event): ConferenceManager
    {
        // Registered plugins (including explicit native-name test overrides) win.
        // Native fallback remains per-call so event configuration is never cached.
        // The shared registry's constructor must receive conference_allow_outbound;
        // ConferenceManager enforces that opt-in and the bounded adapter contract.
        if ($this->registered($name)) {
            return $this->manager;
        }

        $overrides = $this->overrides($event);
        $provider = match ($name) {
            'jitsi' => new JitsiProvider($this->keyProvider(), $this->jitsiConfig($event)),
            'generic' => new GenericRoomProvider($this->keyProvider(), [
                'url_pattern' => (string) $this->params->get('conference_generic_template', ''),
                'prefix' => $this->override($overrides, 'conference_room_prefix', 'meeting'),
                'alias' => $this->roomAlias($event),
            ]),
            'fixed' => new FixedRoomProvider(
                (string) $this->params->get('conference_fixed_url', ''),
                $this->override($overrides, 'conference_fixed_url', '')
            ),
            default => throw new ConferenceException('configuration'),
        };

        return new ConferenceManager([$provider]);
    }

    private function selected(array $event): string
    {
        // An explicit choice in the event settings wins. Without one, the event type's
        // own provider column applies, then the component option. The column is the
        // event type's adapter in the data model, so a provider set there must not be
        // silently replaced by a disabled global provider.
        $column = $event['conference_provider'] ?? null;
        $fallback = is_string($column) && $column !== '' ? $column : (string) $this->params->get('conference_provider', 'none');
        $name = $this->override($this->overrides($event), 'conference_provider', $fallback);

        if (!in_array($name, ['none', 'jitsi', 'generic', 'fixed'], true) && !$this->registered($name)) {
            throw new ConferenceException('configuration');
        }

        return $name;
    }

    private function registered(string $name): bool
    {
        return $this->manager !== null && in_array($name, $this->manager->getProviderNames(), true);
    }

    private function jitsiConfig(array $event): array
    {
        $config = [
            'base_url' => (string) $this->params->get('conference_jitsi_base_url', ''),
            'room_pattern' => (string) $this->params->get('conference_jitsi_room_pattern', '{prefix}-{alias}-{random}'),
            'prefix' => $this->override($this->overrides($event), 'conference_room_prefix',
                (string) $this->params->get('conference_jitsi_room_prefix', 'appointment')),
            'alias' => $this->roomAlias($event),
            'entropy' => $this->params->get('conference_jitsi_entropy', 24),
            'auth' => (string) $this->params->get('conference_jitsi_auth', 'none'),
            'host_lead_minutes' => $this->params->get('conference_jitsi_host_lead_minutes', 30),
            'grace_minutes' => $this->params->get('conference_jitsi_grace_minutes', 60),
            'jwt_secret' => (string) $this->params->get('conference_jitsi_jwt_secret', ''),
            'jwt_app_id' => (string) $this->params->get('conference_jitsi_jwt_app_id', ''),
            'jwt_audience' => (string) $this->params->get('conference_jitsi_jwt_audience', 'jitsi'),
        ];

        foreach (['issuer', 'subject'] as $field) {
            $value = (string) $this->params->get('conference_jitsi_jwt_' . $field, '');

            if ($value !== '') {
                $config['jwt_' . $field] = $value;
            }
        }

        return $config;
    }

    private function roomAlias(array $event): string
    {
        // Joomla can store Unicode/long aliases; room names require an ASCII segment.
        $alias = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($event['alias'] ?? 'event'));

        return trim(substr($alias, 0, 64), '-_') ?: 'event';
    }

    private function keyProvider(): KeyProvider
    {
        return $this->keys ??= new KeyProvider((string) Factory::getApplication()->get('secret', ''));
    }

    private function overrides(array $event): array
    {
        $params = $event['conference_params'] ?? null;

        if ($params === null || $params === '') {
            return [];
        }

        if (is_array($params)) {
            return $params;
        }

        try {
            $decoded = json_decode((string) $params, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new ConferenceException('configuration');
        }

        if (!is_array($decoded)) {
            throw new ConferenceException('configuration');
        }

        return $decoded;
    }

    private function override(array $params, string $key, string $default): string
    {
        $value = $params[$key] ?? '';

        if (!is_string($value)) {
            throw new ConferenceException('configuration');
        }

        return $value === '' || $value === 'inherit' ? $default : $value;
    }

    private function request(array $booking, array $event): MeetingRequest
    {
        $start = UtcTimestamp::parse((string) $booking['start_utc']);
        $end = UtcTimestamp::parse((string) $booking['end_utc']);

        if ($start === null || $end === null) {
            throw new ConferenceException('configuration');
        }

        $seconds = $end->getTimestamp() - $start->getTimestamp();

        if ($seconds < 60 || $seconds % 60 !== 0) {
            throw new ConferenceException('configuration');
        }

        return new MeetingRequest((string) $booking['uid'], $start, $end, (string) ($event['title'] ?? ''), intdiv($seconds, 60));
    }

    private function lockHost(int $host): void
    {
        if ($host < 1 || !$this->db->setQuery(
            'SELECT ' . $this->db->quoteName('user_id') . ' FROM ' . $this->db->quoteName('#__appointments_hosts')
            . ' WHERE ' . $this->db->quoteName('user_id') . ' = ' . $host . ' FOR UPDATE'
        )->loadResult()) {
            throw new \RuntimeException('Conference host lock is unavailable.');
        }
    }

    private function booking(int $id, bool $lock): ?array
    {
        return $this->db->setQuery(
            'SELECT * FROM ' . $this->db->quoteName('#__appointments_bookings')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $id . ($lock ? ' FOR UPDATE' : '')
        )->loadAssoc() ?: null;
    }

    private function event(int $id): ?array
    {
        return $this->db->setQuery(
            'SELECT * FROM ' . $this->db->quoteName('#__appointments_eventtypes')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $id
        )->loadAssoc() ?: null;
    }

    private function write(int $id, array $fields): void
    {
        $update = (object) (['id' => $id] + $fields);

        if (!$this->db->updateObject('#__appointments_bookings', $update, 'id', true)) {
            throw new \RuntimeException('Conference status could not be saved.');
        }
    }

    private function emptyFields(): array
    {
        return array_fill_keys(['conference_provider', 'conference_url', 'conference_room', 'conference_state', 'conference_error'], null);
    }

    private function sameIdentity(array $expected, array $current): bool
    {
        return (int) ($expected['id'] ?? 0) === (int) $current['id']
            && (int) ($expected['host_user_id'] ?? 0) === (int) $current['host_user_id']
            && (string) ($expected['uid'] ?? '') === (string) $current['uid'];
    }

    private function sameTimes(array $expected, array $current): bool
    {
        return ($expected['start_utc'] ?? null) === $current['start_utc']
            && ($expected['end_utc'] ?? null) === $current['end_utc'];
    }

    private function sameSnapshot(array $expected, array $current): bool
    {
        return $this->sameIdentity($expected, $current) && $this->sameTimes($expected, $current)
            && (int) ($expected['eventtype_id'] ?? 0) === (int) $current['eventtype_id']
            && (int) ($expected['state'] ?? -1) === (int) $current['state']
            && (int) ($expected['sequence'] ?? -1) === (int) $current['sequence']
            && ($expected['modified_utc'] ?? null) === $current['modified_utc'];
    }

    private function sameConference(array $expected, array $current): bool
    {
        foreach (array_keys($this->emptyFields()) as $field) {
            if (($expected[$field] ?? null) !== ($current[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Best effort after a rolled-back provisioning transaction: flag the booking as
     * failed so the administrator sees the missing link and the retry action. The
     * conditions keep it to the unchanged snapshot of an active conference booking
     * that has no conference state yet, so a newer revision or a link written by
     * another request is never overwritten. A second database failure is only logged.
     */
    private function markStorageFailure(array $snapshot): void
    {
        $id = (int) ($snapshot['id'] ?? 0);
        $uid = (string) ($snapshot['uid'] ?? '');
        $modified = (string) ($snapshot['modified_utc'] ?? '');

        if ($id < 1 || $uid === '' || $modified === '' || !isset($snapshot['sequence'])) {
            return;
        }

        try {
            $db = $this->db;
            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__appointments_bookings')
                . ' SET ' . $db->quoteName('conference_state') . ' = ' . $db->quote('failed')
                . ', ' . $db->quoteName('conference_error') . ' = ' . $db->quote((new ConferenceException('storage_failed'))->getMessage())
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $id
                . ' AND ' . $db->quoteName('uid') . ' = ' . $db->quote($uid)
                . ' AND ' . $db->quoteName('sequence') . ' = ' . (int) $snapshot['sequence']
                . ' AND ' . $db->quoteName('modified_utc') . ' = ' . $db->quote($modified)
                . ' AND ' . $db->quoteName('state') . ' IN (0, 1)'
                . ' AND ' . $db->quoteName('anonymised_utc') . ' IS NULL'
                . ' AND ' . $db->quoteName('conference_state') . ' IS NULL'
                . ' AND ' . $db->quoteName('eventtype_id') . ' IN (SELECT ' . $db->quoteName('id')
                . ' FROM ' . $db->quoteName('#__appointments_eventtypes')
                . ' WHERE ' . $db->quoteName('location_type') . ' = ' . $db->quote('conference') . ')'
            )->execute();
        } catch (\Throwable $error) {
            $this->log('Conference failure flag could not be saved.');
        }
    }

    private function creationError(\Throwable $error): string
    {
        return $error instanceof ConferenceException ? $error->getMessage() : 'Conference link creation failed. Please retry.';
    }

    private function safe(array $booking): array
    {
        unset($booking['host_url'], $booking['conference_host_url'], $booking['_host_url']);
        $booking['_conference_missing'] = !isset($booking['id']);

        return $booking;
    }

    private function refreshSafely(int $id): array
    {
        try {
            return $this->safe($this->booking($id, false) ?? []);
        } catch (\Throwable $error) {
            // Never fall back to a stale pre-erasure snapshot.
            return ['_conference_missing' => true];
        }
    }

    private function rollback(bool $started): void
    {
        if ($started) {
            try {
                $this->db->transactionRollback();
            } catch (\Throwable $error) {
            }
        }
    }

    private function log(string $message): void
    {
        try {
            if (class_exists(Log::class)) {
                Log::add($message, Log::WARNING, 'com_appointments');
            }
        } catch (\Throwable $error) {
            // Failure reporting must not undo the already committed booking.
        }
    }
}
