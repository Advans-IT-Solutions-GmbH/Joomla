<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Close;

defined('_JEXEC') or die;

use Advans\Component\Appointments\Administrator\Helper\Attribution;

/**
 * Builds and sends the requests of one profile. It creates leads, contacts and one note or
 * custom activity per booking and updates them; it has no code path that deletes anything in Close.
 * Transport receives only relative paths; the production transport never follows redirects.
 */
final class Client
{
    private const STATES = [0 => 'pending', 1 => 'confirmed', 2 => 'cancelled'];

    /** How the booking was assigned to a contact (match()); an ambiguous step is 'ambiguous_' . the step. */
    public const MATCH_EMAIL = 'email';
    public const MATCH_PHONE = 'phone';
    public const MATCH_NAME_COMPANY = 'name_company';
    public const MATCH_NONE = 'none';

    /** The most contacts a step reads and the most possible duplicates a note names. */
    public const CANDIDATE_LIMIT = 10;

    /** The name step reads contacts in pages of NAME_PAGE, at most NAME_LIMIT, and the company of at most NAME_LEADS leads. */
    private const NAME_PAGE = 100;
    private const NAME_LIMIT = 200;
    private const NAME_LEADS = 50;

    /** The note's text of each assignment, written out for the language lint. */
    private const MATCH_TEXTS = [
        self::MATCH_EMAIL => 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH_EMAIL',
        self::MATCH_PHONE => 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH_PHONE',
        self::MATCH_NAME_COMPANY => 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH_NAME_COMPANY',
        self::MATCH_NONE => 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH_NONE',
        'ambiguous_' . self::MATCH_EMAIL => 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH_AMBIGUOUS_EMAIL',
        'ambiguous_' . self::MATCH_PHONE => 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH_AMBIGUOUS_PHONE',
        'ambiguous_' . self::MATCH_NAME_COMPANY => 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH_AMBIGUOUS_NAME_COMPANY',
    ];

    /** The note's label of each campaign parameter, written out for the language lint. */
    private const UTM_TEXTS = [
        'utm_source' => 'COM_APPOINTMENTS_CLOSE_NOTE_UTM_SOURCE',
        'utm_medium' => 'COM_APPOINTMENTS_CLOSE_NOTE_UTM_MEDIUM',
        'utm_campaign' => 'COM_APPOINTMENTS_CLOSE_NOTE_UTM_CAMPAIGN',
        'utm_content' => 'COM_APPOINTMENTS_CLOSE_NOTE_UTM_CONTENT',
        'utm_term' => 'COM_APPOINTMENTS_CLOSE_NOTE_UTM_TERM',
    ];

    private ?array $typeData = null;
    private ?string $organization = null;
    /** @var array<string, bool> lead cf_ ID => accepts multiple values */
    private array $leadFields = [];
    /** @var array<string, string> campaign parameter => resolved lead cf_ ID */
    private array $utmIds = [];

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

    /**
     * The existing contact a booking belongs to, in three steps, each only when the one before found
     * nothing: 1. the exact e-mail address; 2. the telephone number in E.164 form; 3. first and last
     * name of the contact together with the company (the answer of the configured lead-name field),
     * both exactly, ignoring case and spacing. A name alone never matches. Exactly one match in a step
     * is reused unchanged. Several matches in a step never block: the booking gets a new lead, and the
     * leads that matched are named in its note as possible duplicates. Each step sends Close only the
     * value it searches for, which the new lead would carry anyway.
     *
     * @return array{match_rule: string, match_candidates: string, contact_id?: string, lead_id?: string}
     */
    public function match(array $booking): array
    {
        $this->assertBooking($booking);
        $steps = [
            self::MATCH_EMAIL => fn (): ?array => $this->byEmail(trim((string) $booking['invitee_email'])),
            self::MATCH_PHONE => fn (): ?array => $this->byPhone(self::e164((string) ($booking['invitee_phone'] ?? ''))),
            self::MATCH_NAME_COMPANY => fn (): ?array => $this->byNameAndCompany(self::personName($booking),
                $this->first(self::answers($booking), $this->profile->leadName)),
        ];
        foreach ($steps as $rule => $find) {
            $found = $find();
            if ($found === null || $found['contacts'] === []) {
                continue;
            }
            // Every match counts here; only the possible duplicates a note names are capped.
            if (count($found['contacts']) === 1 && !$found['more']) {
                return $found['contacts'][0] + ['match_rule' => $rule, 'match_candidates' => ''];
            }
            $leads = array_values(array_unique(array_column($found['contacts'], 'lead_id')));
            return ['match_rule' => 'ambiguous_' . $rule, 'match_candidates' => implode(',', array_slice($leads, 0, self::CANDIDATE_LIMIT))];
        }
        return ['match_rule' => self::MATCH_NONE, 'match_candidates' => ''];
    }

    /** A telephone number in E.164 form ("+41791234567"), or '' when it is not an international number. */
    public static function e164(string $phone): string
    {
        $digits = (string) preg_replace('/[\s().\/-]/', '', trim($phone));
        return preg_match('/^(?:\+|00)([1-9][0-9]{6,14})$/D', $digits, $match) === 1 ? '+' . $match[1] : '';
    }

    /** A name or company for an exact comparison: lower case, spacing collapsed and trimmed. */
    public static function comparable(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    /** @return ?array{contacts: list<array{contact_id: string, lead_id: string}>, more: bool} */
    private function byEmail(string $email): ?array
    {
        return $this->search(['type' => 'has_related', 'this_object_type' => 'contact', 'related_object_type' => 'contact_email',
            'related_query' => ['type' => 'field_condition',
                'field' => ['type' => 'regular_field', 'object_type' => 'contact_email', 'field_name' => 'email'],
                'condition' => ['type' => 'text', 'mode' => 'exact_value', 'value' => $email]]], 'emails',
            static function (array $contact) use ($email): bool {
                foreach (is_array($contact['emails'] ?? null) ? $contact['emails'] : [] as $address) {
                    if (is_string($address['email'] ?? null) && strcasecmp(trim($address['email']), $email) === 0) {
                        return true;
                    }
                }
                return false;
            });
    }

    /** @return ?array{contacts: list<array{contact_id: string, lead_id: string}>, more: bool} */
    private function byPhone(string $phone): ?array
    {
        if ($phone === '') {
            return null;
        }
        return $this->search(['type' => 'has_related', 'this_object_type' => 'contact', 'related_object_type' => 'contact_phone',
            'related_query' => ['type' => 'field_condition',
                'field' => ['type' => 'regular_field', 'object_type' => 'contact_phone', 'field_name' => 'phone'],
                'condition' => ['type' => 'text', 'mode' => 'exact_value', 'value' => $phone]]], 'phones',
            static function (array $contact) use ($phone): bool {
                foreach (is_array($contact['phones'] ?? null) ? $contact['phones'] : [] as $number) {
                    if (is_string($number['phone'] ?? null) && self::e164($number['phone']) === $phone) {
                        return true;
                    }
                }
                return false;
            });
    }

    /**
     * Contacts with this first and last name whose lead has this company as its name. Without a name
     * or without a company the step does not apply. The company is checked on every contact with the
     * name, across result pages (up to NAME_LIMIT contacts and NAME_LEADS leads), before anything is
     * limited: only the possible duplicates a note names are capped (match()).
     *
     * @return ?array{contacts: list<array{contact_id: string, lead_id: string}>, more: bool}
     */
    private function byNameAndCompany(string $name, string $company): ?array
    {
        if (self::comparable($name) === '' || self::comparable($company) === '') {
            return null;
        }
        $named = $this->search(['type' => 'field_condition',
            'field' => ['type' => 'regular_field', 'object_type' => 'contact', 'field_name' => 'name'],
            'condition' => ['type' => 'text', 'mode' => 'full_words', 'value' => trim($name)]], 'name',
            static fn (array $contact): bool => is_string($contact['name'] ?? null) && self::comparable($contact['name']) === self::comparable($name),
            self::NAME_PAGE, self::NAME_LIMIT);
        $companies = [];
        $contacts = [];
        $more = $named['more'];
        foreach ($named['contacts'] as $contact) {
            $lead = $contact['lead_id'];
            if (!isset($companies[$lead])) {
                if (count($companies) >= self::NAME_LEADS) {
                    // More leads with this name than are read: a match among them cannot be excluded.
                    $more = true;
                    continue;
                }
                $data = $this->request('GET', '/api/v1/lead/' . $lead . '/?_fields=id,name');
                if (($data['id'] ?? '') !== $lead) {
                    throw new DeliveryException('blocked', 'invalid_search');
                }
                $companies[$lead] = self::comparable(is_string($data['name'] ?? null) ? $data['name'] : '');
            }
            if ($companies[$lead] === self::comparable($company)) {
                $contacts[] = $contact;
            }
        }
        // One match among more contacts than were read is not unique: match() then lists it as a possible duplicate.
        return ['contacts' => $contacts, 'more' => $more && $contacts !== []];
    }

    /**
     * One contact search: the contacts $exact accepts, read in pages of $page up to $max contacts.
     * `more` says that Close has further results beyond those read.
     *
     * @param \Closure(array): bool $exact
     *
     * @return array{contacts: list<array{contact_id: string, lead_id: string}>, more: bool}
     */
    private function search(array $condition, string $field, \Closure $exact, int $page = self::CANDIDATE_LIMIT + 1,
        int $max = self::CANDIDATE_LIMIT + 1): array
    {
        $contacts = [];
        $read = 0;
        $cursor = null;
        do {
            $payload = [
                'query' => ['type' => 'and', 'queries' => [['type' => 'object_type', 'object_type' => 'contact'], $condition]],
                '_fields' => ['contact' => ['id', 'lead_id', $field]], '_limit' => min($page, $max - $read),
            ];
            if ($cursor !== null) {
                $payload['cursor'] = $cursor;
            }
            $response = $this->request('POST', '/api/v1/data/search/', $payload);
            if (!isset($response['data']) || !is_array($response['data']) || !array_key_exists('cursor', $response)
                || ($response['cursor'] !== null && !is_string($response['cursor']))) {
                throw new DeliveryException('blocked', 'invalid_search');
            }
            foreach ($response['data'] as $contact) {
                $read++;
                if (!is_array($contact) || !$exact($contact)) {
                    continue;
                }
                try {
                    $contacts[] = ['contact_id' => Settings::id((string) ($contact['id'] ?? ''), 'cont'),
                        'lead_id' => Settings::id((string) ($contact['lead_id'] ?? ''), 'lead')];
                } catch (\InvalidArgumentException $e) {
                    throw new DeliveryException('blocked', 'invalid_search');
                }
            }
            $cursor = $response['cursor'];
        } while ($cursor !== null && $response['data'] !== [] && $read < $max);
        return ['contacts' => $contacts, 'more' => $cursor !== null];
    }

    /** First and last name of the booking person, else the full name. */
    private static function personName(array $booking): string
    {
        $name = trim(trim((string) ($booking['invitee_first_name'] ?? '')) . ' ' . trim((string) ($booking['invitee_last_name'] ?? '')));
        return $name !== '' && trim((string) ($booking['invitee_first_name'] ?? '')) !== '' && trim((string) ($booking['invitee_last_name'] ?? '')) !== ''
            ? $name : trim((string) ($booking['invitee_name'] ?? ''));
    }

    /** Checks every mapped lead field before personal data is sent. */
    public function validateLeadFields(): void
    {
        $this->resolveUtmFields();
        foreach (array_unique([...array_keys($this->profile->leadValues), ...array_values($this->profile->leadAnswers),
            ...array_values($this->utmIds)]) as $id) {
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

    /**
     * The cf_ ID of every campaign parameter mapped in the profile. A configured name is looked up in
     * the lead field catalog (the same read right as a single field): exactly one field with that name,
     * ignoring case and spacing, is required. The resolved fields must differ from each other and from
     * the other lead mappings.
     */
    private function resolveUtmFields(): void
    {
        if ($this->utmIds !== [] || $this->profile->utmFields === []) {
            return;
        }
        $names = array_filter($this->profile->utmFields, static fn (string $field): bool => !str_starts_with($field, 'cf_'));
        $catalog = [];
        if ($names !== []) {
            $skip = 0;
            do {
                $page = $this->request('GET', '/api/v1/custom_field/lead/?_limit=100&_skip=' . $skip);
                if (!is_array($page['data'] ?? null)) {
                    throw new DeliveryException('blocked', 'lead_field');
                }
                foreach ($page['data'] as $field) {
                    if (is_array($field) && is_string($field['id'] ?? null) && is_string($field['name'] ?? null)) {
                        $catalog[self::comparable($field['name'])][] = $field['id'];
                    }
                }
                $skip += 100;
            } while (!empty($page['has_more']) && $page['data'] !== [] && $skip < 1000);
        }
        $ids = [];
        foreach ($this->profile->utmFields as $parameter => $field) {
            if (isset($names[$parameter])) {
                $found = array_values(array_unique($catalog[self::comparable($field)] ?? []));
                if (count($found) !== 1) {
                    throw new DeliveryException('blocked', 'lead_field');
                }
                try {
                    $field = Settings::id($found[0], 'cf');
                } catch (\InvalidArgumentException $e) {
                    throw new DeliveryException('blocked', 'lead_field');
                }
            }
            $ids[$parameter] = $field;
        }
        if (count($ids) !== count(array_unique($ids))
            || array_intersect($ids, [...array_keys($this->profile->leadValues), ...array_values($this->profile->leadAnswers)])) {
            throw new DeliveryException('blocked', 'field_conflict');
        }
        $this->utmIds = $ids;
    }

    /**
     * The campaign parameters of the booking's origin ({@see Attribution::utm()}), all '' when the
     * profile does not transfer them or the booking has no origin record.
     *
     * @return array<string, string>
     */
    public function utm(array $booking): array
    {
        if (!$this->profile->utm) {
            return array_fill_keys(Profile::UTM, '');
        }
        return Attribution::utm(Attribution::decode($booking['attribution'] ?? null), $this->profile->utmFirst);
    }

    /**
     * The update that fills the mapped campaign fields of an existing lead which are still empty, or
     * null when there is nothing to fill. Values already in Close are never overwritten, so the first
     * known origin of a lead stays. The whole lead is read on purpose: Close leaves empty custom fields
     * out of a lead, so only a full read tells an empty field from one that was not requested.
     */
    public function fill(string $lead, array $booking): ?array
    {
        $this->resolveUtmFields();
        $values = array_filter($this->utm($booking), static fn (string $value): bool => $value !== '');
        $values = array_intersect_key($values, $this->utmIds);
        if ($values === []) {
            return null;
        }
        $lead = Settings::id($lead, 'lead');
        $current = $this->request('GET', '/api/v1/lead/' . $lead . '/');
        if (($current['id'] ?? '') !== $lead) {
            throw new DeliveryException('blocked', 'invalid_lead');
        }
        $payload = [];
        foreach ($values as $parameter => $value) {
            $id = $this->utmIds[$parameter];
            $present = $current['custom.' . $id] ?? null;
            if ($present === null || $present === '' || $present === []) {
                $payload['custom.' . $id] = $this->leadValue($id, $value);
            }
        }
        return $payload === [] ? null : $payload;
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

    /**
     * A new lead with one contact. Only new leads receive the company, title and fixed or answer
     * values; the mapped campaign fields are set here and filled where empty on existing leads (fill()).
     */
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
        $this->resolveUtmFields();
        foreach ($this->utm($booking) as $parameter => $value) {
            if ($value !== '' && isset($this->utmIds[$parameter])) {
                $payload['custom.' . $this->utmIds[$parameter]] = $this->leadValue($this->utmIds[$parameter], $value);
            }
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
        // The campaign parameters of this booking's origin, also when the lead already had others.
        foreach ($this->utm($booking) as $parameter => $value) {
            $line(self::UTM_TEXTS[$parameter], $value);
        }
        $line('COM_APPOINTMENTS_CLOSE_NOTE_NEW_CONTACT', $text(!empty($context['contact_created'])
            ? 'COM_APPOINTMENTS_CLOSE_NOTE_YES' : 'COM_APPOINTMENTS_CLOSE_NOTE_NO'));
        // How the booking was assigned (match()). The e-mail address and telephone number the booking
        // person used are the lines above. Transfers recorded before the matching steps have none.
        $rule = (string) ($context['match_rule'] ?? '');
        if (isset(self::MATCH_TEXTS[$rule])) {
            $line('COM_APPOINTMENTS_CLOSE_NOTE_MATCH', $text(self::MATCH_TEXTS[$rule]));
        }
        $line('COM_APPOINTMENTS_CLOSE_NOTE_DUPLICATES', str_replace(',', ', ', (string) ($context['match_candidates'] ?? '')));
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
            // match(): email, phone, name_company, none or ambiguous_<step>, and the possible duplicates.
            'matched_by' => (string) ($context['match_rule'] ?? ''),
            'duplicates' => str_replace(',', ', ', (string) ($context['match_candidates'] ?? '')),
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
