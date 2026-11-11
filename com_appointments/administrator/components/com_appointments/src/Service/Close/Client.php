<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Close;

defined('_JEXEC') or die;

/**
 * Builds and sends the requests of one profile. It creates leads, contacts and one note or
 * custom activity per booking and updates them; it has no code path that deletes anything in Close.
 * Transport receives only relative paths; the production transport never follows redirects.
 */
final class Client
{
    private const STATES = [0 => 'pending', 1 => 'confirmed', 2 => 'cancelled'];

    private ?array $typeData = null;
    private ?string $organization = null;
    /** @var array<string, bool> lead cf_ ID => accepts multiple values */
    private array $leadFields = [];

    public function __construct(public readonly Profile $profile, private \Closure $transport)
    {
    }

    public function request(string $method, string $path, ?array $payload = null, bool $create = false): array
    {
        if (!in_array($method, ['GET', 'POST', 'PUT'], true)) {
            throw new DeliveryException('blocked', 'method');
        }
        try {
            $response = ($this->transport)($method, $path, $payload);
        } catch (\Throwable $e) {
            throw new DeliveryException($create ? 'uncertain' : 'retry', 'transport');
        }
        $code = (int) ($response['code'] ?? 0);
        if ($code === 429) {
            throw new DeliveryException('retry', 'rate_limit', max(1, min(86400, (int) ($response['retry_after'] ?? 300))));
        }
        if ($code < 200 || $code >= 300) {
            $state = $code >= 500 || $code === 0 || $code === 408 ? ($create ? 'uncertain' : 'retry') : 'blocked';
            throw new DeliveryException($state, 'http_' . $code);
        }
        try {
            $data = $response['data'] ?? json_decode((string) ($response['body'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \UnexpectedValueException();
            }
            return $data;
        } catch (\Throwable $e) {
            throw new DeliveryException($create ? 'uncertain' : 'retry', 'invalid_response');
        }
    }

    /**
     * The organisation the configured key actually authenticates. It is read before any personal data
     * is sent, so a replaced key of another organisation is detected first.
     */
    public function organization(): string
    {
        if ($this->organization === null) {
            $me = $this->request('GET', '/api/v1/me/');
            $ids = [];
            foreach (is_array($me['organizations'] ?? null) ? $me['organizations'] : [] as $organization) {
                $ids[] = is_array($organization) ? (string) ($organization['id'] ?? '') : '';
            }
            $ids = array_values(array_unique($ids));
            if (count($ids) !== 1 || !preg_match('/^orga_[A-Za-z0-9]{1,100}$/D', $ids[0])) {
                throw new DeliveryException('blocked', 'organization_ambiguous');
            }
            if ($this->profile->organization !== '' && !hash_equals($this->profile->organization, $ids[0])) {
                throw new DeliveryException('blocked', 'organization_mismatch');
            }
            $this->organization = $ids[0];
        }
        return $this->organization;
    }

    /** Exact e-mail match, like the scheduling integration: one match is reused unchanged. */
    public function contact(string $email): ?array
    {
        $response = $this->request('POST', '/api/v1/data/search/', [
            'query' => ['type' => 'and', 'queries' => [
                ['type' => 'object_type', 'object_type' => 'contact'],
                ['type' => 'has_related', 'this_object_type' => 'contact', 'related_object_type' => 'contact_email',
                    'related_query' => ['type' => 'field_condition',
                        'field' => ['type' => 'regular_field', 'object_type' => 'contact_email', 'field_name' => 'email'],
                        'condition' => ['type' => 'text', 'mode' => 'exact_value', 'value' => $email]]],
            ]],
            '_fields' => ['contact' => ['id', 'lead_id', 'emails']], '_limit' => 2,
        ]);
        if (!isset($response['data']) || !is_array($response['data']) || !array_key_exists('cursor', $response)) {
            throw new DeliveryException('blocked', 'invalid_search');
        }
        if (count($response['data']) > 1 || $response['cursor'] !== null) {
            throw new DeliveryException('blocked', 'ambiguous_contact');
        }
        if ($response['data'] === []) {
            return null;
        }
        $contact = $response['data'][0];
        foreach ($contact['emails'] ?? [] as $address) {
            if (is_string($address['email'] ?? null) && strcasecmp(trim($address['email']), trim($email)) === 0) {
                try {
                    return ['contact_id' => Settings::id((string) ($contact['id'] ?? ''), 'cont'),
                        'lead_id' => Settings::id((string) ($contact['lead_id'] ?? ''), 'lead')];
                } catch (\InvalidArgumentException $e) {
                    throw new DeliveryException('blocked', 'invalid_search');
                }
            }
        }
        throw new DeliveryException('blocked', 'inexact_contact');
    }

    /** Checks every mapped lead field before personal data is sent. */
    public function validateLeadFields(): void
    {
        foreach (array_unique([...array_keys($this->profile->leadValues), ...array_values($this->profile->leadAnswers)]) as $id) {
            if (isset($this->leadFields[$id])) {
                continue;
            }
            $field = $this->request('GET', '/api/v1/custom_field/lead/' . $id . '/');
            if (($field['id'] ?? '') !== $id) {
                throw new DeliveryException('blocked', 'lead_field');
            }
            $this->leadFields[$id] = !empty($field['accepts_multiple_values']);
        }
    }

    /** All mapped activity fields are deliberately single-valued text fields. */
    public function validateActivityFields(): void
    {
        if ($this->typeData === null) {
            $type = $this->request('GET', '/api/v1/custom_activity/' . $this->profile->type . '/');
            if (($type['id'] ?? '') !== $this->profile->type || !is_array($type['fields'] ?? null)) {
                throw new DeliveryException('blocked', 'type_identity');
            }
            $mapped = array_values(array_unique([...array_values($this->profile->fields), ...array_values($this->profile->activityAnswers)]));
            $found = [];
            foreach ($type['fields'] as $field) {
                $id = $field['id'] ?? '';
                if (!empty($field['required']) && !in_array($id, $mapped, true)) {
                    throw new DeliveryException('blocked', 'unmapped_required_field');
                }
                if (in_array($id, $mapped, true)) {
                    if (($field['type'] ?? '') !== 'text' || !empty($field['accepts_multiple_values'])) {
                        throw new DeliveryException('blocked', 'field_type');
                    }
                    $found[] = $id;
                }
            }
            if (array_diff($mapped, $found)) {
                throw new DeliveryException('blocked', 'field_mapping');
            }
            $this->typeData = $type;
        }
    }

    /** A new lead with one contact. Only new leads receive the company, title and origin values. */
    public function lead(array $booking): array
    {
        $this->assertBooking($booking);
        $answers = self::answers($booking);
        $contact = ['name' => trim($booking['invitee_name']),
            'emails' => [['email' => trim($booking['invitee_email']), 'type' => 'office']]];
        if (is_string($booking['invitee_phone'] ?? null) && trim($booking['invitee_phone']) !== '') {
            $contact['phones'] = [['phone' => trim($booking['invitee_phone']), 'type' => 'office']];
        }
        if (($title = $this->first($answers, $this->profile->contactTitle)) !== '') {
            $contact['title'] = $title;
        }
        $payload = ['contacts' => [$contact]];
        if (($name = $this->first($answers, $this->profile->leadName)) !== '') {
            $payload['name'] = $name;
        }
        foreach ($this->profile->leadValues as $field => $value) {
            $payload['custom.' . $field] = $this->leadValue($field, $value);
        }
        foreach ($this->profile->leadAnswers as $question => $field) {
            $answer = $answers[$question] ?? null;
            if ($answer === null || $answer === '' || $answer === []) {
                continue;
            }
            if (isset($payload['custom.' . $field])) {
                throw new DeliveryException('blocked', 'field_conflict');
            }
            $payload['custom.' . $field] = $this->leadValue($field, $answer);
        }
        return $payload;
    }

    /**
     * The booking note: one note per booking, updated in place on every change.
     *
     * @param \Closure(string): string $text translation of the component's language keys
     */
    public function note(array $booking, array $context, \Closure $text): array
    {
        $this->assertBooking($booking);
        $state = self::STATES[(int) $booking['state']];
        $answers = self::answers($booking);
        $zone = new \DateTimeZone($context['timezone'] ?? 'UTC');
        $lines = [];
        $line = static function (string $key, string $value) use (&$lines, $text): void {
            if ($value !== '') {
                $lines[] = $text($key) . ': ' . $value;
            }
        };
        $line('COM_APPOINTMENTS_CLOSE_NOTE_STATUS', $text(['pending' => 'COM_APPOINTMENTS_CLOSE_NOTE_STATE_PENDING',
            'confirmed' => 'COM_APPOINTMENTS_CLOSE_NOTE_STATE_CONFIRMED', 'cancelled' => 'COM_APPOINTMENTS_CLOSE_NOTE_STATE_CANCELLED'][$state]));
        foreach (['COM_APPOINTMENTS_CLOSE_NOTE_START' => 'start_utc', 'COM_APPOINTMENTS_CLOSE_NOTE_END' => 'end_utc'] as $key => $source) {
            $line($key, self::time($booking[$source])->setTimezone($zone)->format('Y-m-d H:i') . ' (' . $zone->getName() . ')');
        }
        $line('COM_APPOINTMENTS_CLOSE_NOTE_EVENTTYPE', (string) ($context['event_title'] ?? ''));
        $line('COM_APPOINTMENTS_CLOSE_NOTE_NAME', trim($booking['invitee_name']));
        $line('COM_APPOINTMENTS_CLOSE_NOTE_EMAIL', trim($booking['invitee_email']));
        $line('COM_APPOINTMENTS_CLOSE_NOTE_PHONE', trim((string) ($booking['invitee_phone'] ?? '')));
        foreach ($this->profile->noteAnswers as $question) {
            $answer = self::flat($answers[$question] ?? '');
            $label = trim((string) ($context['labels'][$question] ?? ''));
            if ($answer !== '') {
                $lines[] = ($label !== '' ? $label : '#' . $question) . ': ' . $answer;
            }
        }
        $line('COM_APPOINTMENTS_CLOSE_NOTE_CAMPAIGN', $this->profile->campaign);
        $line('COM_APPOINTMENTS_CLOSE_NOTE_SOURCE', $this->profile->source);
        $line('COM_APPOINTMENTS_CLOSE_NOTE_NEW_CONTACT', $text(!empty($context['contact_created'])
            ? 'COM_APPOINTMENTS_CLOSE_NOTE_YES' : 'COM_APPOINTMENTS_CLOSE_NOTE_NO'));
        $line('COM_APPOINTMENTS_CLOSE_NOTE_UID', (string) $booking['uid']);
        $line('COM_APPOINTMENTS_CLOSE_NOTE_SEQUENCE', (string) (int) $booking['sequence']);
        $title = sprintf($text($state === 'cancelled' ? 'COM_APPOINTMENTS_CLOSE_NOTE_TITLE_CANCELLED'
            : 'COM_APPOINTMENTS_CLOSE_NOTE_TITLE'), (string) ($context['event_title'] ?? ''));
        return ['title' => mb_substr(trim($title), 0, 255), 'note' => implode("\n", $lines)];
    }

    /** Optional custom activity for plans that offer it. */
    public function activity(array $booking, array $context): array
    {
        $this->assertBooking($booking);
        $values = ['uid' => $booking['uid'], 'sequence' => (string) (int) $booking['sequence'],
            'state' => self::STATES[(int) $booking['state']], 'source' => $this->profile->source,
            'start' => self::time($booking['start_utc'])->format('Y-m-d\TH:i:s\Z'),
            'end' => self::time($booking['end_utc'])->format('Y-m-d\TH:i:s\Z'),
            'event_type' => (string) ($context['event_title'] ?? ''), 'campaign' => $this->profile->campaign,
            'contact_created' => !empty($context['contact_created']) ? 'yes' : 'no',
            'start_local' => self::time($booking['start_utc'])->setTimezone(new \DateTimeZone($context['timezone'] ?? 'UTC'))
                ->format('Y-m-d H:i T')];
        $payload = ['custom_activity_type_id' => $this->profile->type, 'status' => 'published'];
        foreach ($this->profile->fields as $name => $field) {
            $payload['custom.' . $field] = $values[$name];
        }
        $answers = self::answers($booking);
        foreach ($this->profile->activityAnswers as $question => $field) {
            $answer = self::flat($answers[$question] ?? '');
            if ($answer === '') {
                continue;
            }
            if (isset($payload['custom.' . $field])) {
                throw new DeliveryException('blocked', 'field_conflict');
            }
            $payload['custom.' . $field] = $answer;
        }
        return $payload;
    }

    private function assertBooking(array $booking): void
    {
        if (!filter_var($booking['invitee_email'] ?? '', FILTER_VALIDATE_EMAIL)
            || !is_string($booking['invitee_name'] ?? null) || trim($booking['invitee_name']) === ''
            || !preg_match('/^[a-f0-9]{64}$/D', (string) ($booking['uid'] ?? ''))
            || !isset(self::STATES[(int) ($booking['state'] ?? -1)]) || !is_numeric($booking['state'] ?? null)) {
            throw new DeliveryException('blocked', 'booking_data');
        }
        self::time($booking['start_utc'] ?? '');
        self::time($booking['end_utc'] ?? '');
    }

    private function leadValue(string $field, mixed $value): string|array
    {
        if (!isset($this->leadFields[$field])) {
            throw new DeliveryException('blocked', 'lead_field');
        }
        if ($this->leadFields[$field]) {
            return array_values(array_map('strval', (array) $value));
        }
        return self::flat($value);
    }

    private function first(array $answers, array $questions): string
    {
        foreach ($questions as $question) {
            $answer = mb_substr(self::flat($answers[$question] ?? ''), 0, 255);
            if ($answer !== '') {
                return $answer;
            }
        }
        return '';
    }

    private static function answers(array $booking): array
    {
        try {
            $answers = json_decode((string) (($booking['custom_fields'] ?? '') ?: '{}'), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DeliveryException('blocked', 'booking_answers');
        }
        if (!is_array($answers)) {
            throw new DeliveryException('blocked', 'booking_answers');
        }
        foreach ($answers as $answer) {
            if (is_array($answer)) {
                foreach ($answer as $part) {
                    if (!is_scalar($part)) {
                        throw new DeliveryException('blocked', 'booking_answers');
                    }
                }
            } elseif (!is_scalar($answer) && $answer !== null) {
                throw new DeliveryException('blocked', 'booking_answers');
            }
        }
        return $answers;
    }

    private static function flat(mixed $answer): string
    {
        return trim(is_array($answer) ? implode(', ', array_map('strval', $answer)) : (string) $answer);
    }

    private static function time(mixed $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new DeliveryException('blocked', 'booking_time');
        }
        return $date;
    }
}
