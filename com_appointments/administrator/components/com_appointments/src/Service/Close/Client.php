<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Close;

defined('_JEXEC') or die;

/** Transport receives only relative paths; the production transport never follows redirects. */
final class Client
{
    public function __construct(private Settings $settings, private \Closure $transport)
    {
    }

    public function request(string $method, string $path, ?array $payload = null, bool $create = false): array
    {
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
            if ($method === 'DELETE' && $code === 404) {
                return [];
            }
            $state = $code >= 500 || $code === 0 || $code === 408 ? ($create ? 'uncertain' : 'retry') : 'blocked';
            throw new DeliveryException($state, 'http_' . $code);
        }
        if ($method === 'DELETE' && $code === 204) {
            return [];
        }
        try {
            $data = $response['data'] ?? json_decode((string) ($response['body'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
            if ($method === 'DELETE' && ($data === null || $data === [])) {
                return [];
            }
            if (!is_array($data)) {
                throw new \UnexpectedValueException();
            }
            return $data;
        } catch (\Throwable $e) {
            throw new DeliveryException($create ? 'uncertain' : 'retry', 'invalid_response');
        }
    }

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
                return ['contact_id' => Settings::id((string) $contact['id'], 'cont'),
                    'lead_id' => Settings::id((string) $contact['lead_id'], 'lead')];
            }
        }
        throw new DeliveryException('blocked', 'inexact_contact');
    }

    /** All mapped activity fields are deliberately single-valued text fields. */
    public function validateFields(): void
    {
        $fields = $this->request('GET', '/api/v1/custom_field/activity/?_limit=1000');
        if (!isset($fields['data']) || !is_array($fields['data']) || !empty($fields['has_more'])) {
            throw new DeliveryException('blocked', 'field_catalog');
        }
        $mapped = array_merge(array_values($this->settings->fields), array_values($this->settings->questions));
        $found = [];
        foreach ($fields['data'] as $field) {
            if (($field['custom_activity_type_id'] ?? null) !== $this->settings->type) {
                continue;
            }
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
    }

    public function activity(array $booking): array
    {
        if (!filter_var($booking['invitee_email'] ?? '', FILTER_VALIDATE_EMAIL)
            || !is_string($booking['invitee_name'] ?? null) || trim($booking['invitee_name']) === ''
            || !preg_match('/^[a-f0-9]{64}$/D', (string) ($booking['uid'] ?? ''))
            || !in_array((int) ($booking['state'] ?? -1), [0, 1, 2], true)) {
            throw new DeliveryException('blocked', 'booking_data');
        }
        $values = ['uid' => $booking['uid'], 'sequence' => (string) (int) $booking['sequence'],
            'state' => [0 => 'pending', 1 => 'confirmed', 2 => 'cancelled'][(int) $booking['state']],
            'source' => 'Joomla appointments'];
        foreach (['start' => 'start_utc', 'end' => 'end_utc'] as $target => $source) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $booking[$source], new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $booking[$source]) {
                throw new DeliveryException('blocked', 'booking_time');
            }
            $values[$target] = $date->format('Y-m-d\TH:i:s\Z');
        }
        $payload = ['custom_activity_type_id' => $this->settings->type, 'status' => 'published'];
        foreach ($values as $name => $value) {
            $payload['custom.' . $this->settings->fields[$name]] = $value;
        }
        $answers = json_decode((string) (($booking['custom_fields'] ?? '') ?: '{}'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($answers)) {
            throw new DeliveryException('blocked', 'booking_answers');
        }
        foreach ($this->settings->questions as $question => $field) {
            $answer = $answers[$question] ?? '';
            if (is_array($answer)) {
                foreach ($answer as $part) {
                    if (!is_string($part)) {
                        throw new DeliveryException('blocked', 'booking_answers');
                    }
                }
                $answer = implode("\n", $answer);
            }
            if (!is_scalar($answer) && $answer !== null) {
                throw new DeliveryException('blocked', 'booking_answers');
            }
            $payload['custom.' . $field] = (string) $answer;
        }
        return $payload;
    }
}
