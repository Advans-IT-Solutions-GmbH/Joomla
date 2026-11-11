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
 * One Close destination: its own API origin and key reference, its own delivery mode and mapping.
 * The API key itself is never part of the options; only the name of a server environment variable.
 */
final class Profile
{
    public const MODE_NOTE = 'note';
    public const MODE_ACTIVITY = 'activity';
    private const ACTIVITY_REQUIRED = ['uid', 'sequence', 'start', 'end', 'state', 'source'];
    private const ACTIVITY_OPTIONAL = ['event_type', 'campaign', 'contact_created', 'start_local'];

    public readonly string $key;
    public readonly string $origin;
    public readonly string $credential;
    public readonly string $mode;
    public readonly string $type;
    /** @var array<string, string> activity value name => cf_ ID */
    public readonly array $fields;
    /** @var array<int, string> local question ID => activity cf_ ID */
    public readonly array $activityAnswers;
    /** @var list<int> event type IDs routed to this profile */
    public readonly array $eventTypes;
    public readonly string $campaign;
    public readonly string $source;
    /** @var list<int> local question IDs written into the booking note */
    public readonly array $noteAnswers;
    /** @var list<int> */
    public readonly array $leadName;
    /** @var list<int> */
    public readonly array $contactTitle;
    /** @var array<int, string> local question ID => lead cf_ ID, new leads only */
    public readonly array $leadAnswers;
    /** @var array<string, string> lead cf_ ID => fixed value, new leads only */
    public readonly array $leadValues;
    /** Identifies the remote destination; a change stops updates of already transferred bookings. */
    public readonly string $binding;

    public function __construct(array $entry)
    {
        $this->key = (string) ($entry['key'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $this->key)) {
            throw new \InvalidArgumentException('Invalid Close profile key.');
        }
        $this->origin = rtrim(trim((string) ($entry['origin'] ?? '')), '/');
        $parts = parse_url($this->origin);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !empty($parts['path']) || (isset($parts['port']) && $parts['port'] !== 443)
            || filter_var($parts['host'], FILTER_VALIDATE_IP) || !str_contains($parts['host'], '.')
            || preg_match('/[\s\x00-\x1f\x7f]/', $this->origin)) {
            throw new \InvalidArgumentException('Close API origin is not configured as an HTTPS origin.');
        }
        $this->credential = trim((string) ($entry['credential_env'] ?? ''));
        if (!preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $this->credential)) {
            throw new \InvalidArgumentException('Close credential environment reference is missing.');
        }
        $this->mode = (string) ($entry['delivery'] ?? self::MODE_NOTE);
        if (!in_array($this->mode, [self::MODE_NOTE, self::MODE_ACTIVITY], true)) {
            throw new \InvalidArgumentException('Invalid Close delivery mode.');
        }
        $this->eventTypes = self::ids($entry['eventtypes'] ?? []);
        if ($this->eventTypes === []) {
            throw new \InvalidArgumentException('A Close profile needs at least one event type.');
        }
        $this->campaign = self::text($entry['campaign'] ?? '', 255);
        $this->source = self::text($entry['source'] ?? '', 255) ?: 'Joomla appointments';
        $this->noteAnswers = self::ids($entry['note_answers'] ?? '');
        $this->leadName = self::ids($entry['lead_name'] ?? '');
        $this->contactTitle = self::ids($entry['contact_title'] ?? '');
        $this->leadAnswers = self::questionMap($entry['lead_answers'] ?? '');
        $values = self::object($entry['lead_values'] ?? '');
        $leadValues = [];
        foreach ($values as $field => $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Close lead values must be text.');
            }
            $leadValues[Settings::id((string) $field, 'cf')] = self::text($value, 1000);
        }
        ksort($leadValues);
        $this->leadValues = $leadValues;
        if (array_intersect(array_keys($leadValues), array_values($this->leadAnswers))) {
            throw new \InvalidArgumentException('A lead field cannot have a fixed value and an answer.');
        }
        if ($this->mode === self::MODE_ACTIVITY) {
            $this->type = Settings::id(trim((string) ($entry['activity_type'] ?? '')), 'actitype');
            $fields = [];
            foreach (self::object($entry['activity_fields'] ?? '') as $name => $field) {
                if (!in_array($name, [...self::ACTIVITY_REQUIRED, ...self::ACTIVITY_OPTIONAL], true) || !is_string($field)) {
                    throw new \InvalidArgumentException('Unknown Close activity field.');
                }
                $fields[$name] = Settings::id($field, 'cf');
            }
            if (array_diff(self::ACTIVITY_REQUIRED, array_keys($fields))) {
                throw new \InvalidArgumentException('Close booking field mapping is incomplete.');
            }
            ksort($fields);
            $this->fields = $fields;
            $this->activityAnswers = self::questionMap($entry['activity_answers'] ?? '');
            if (count($fields) !== count(array_unique($fields)) || array_intersect($fields, $this->activityAnswers)) {
                throw new \InvalidArgumentException('Close mappings must use distinct destination fields.');
            }
        } else {
            $this->type = '';
            $this->fields = [];
            $this->activityAnswers = [];
        }
        $this->binding = hash('sha256', json_encode([$this->origin, $this->credential, $this->mode, $this->type], JSON_THROW_ON_ERROR));
    }

    /** All questions whose answers this profile may read; nothing else leaves the website. */
    public function questions(): array
    {
        return array_values(array_unique([...$this->noteAnswers, ...$this->leadName, ...$this->contactTitle,
            ...array_keys($this->leadAnswers), ...array_keys($this->activityAnswers)]));
    }

    /** @return list<int> */
    private static function ids(mixed $value): array
    {
        if (is_string($value) || is_int($value)) {
            $value = trim((string) $value) === '' ? [] : preg_split('/\s*,\s*/', trim((string) $value));
        }
        if (!is_array($value) || count($value) > 200) {
            throw new \InvalidArgumentException('Invalid Close ID list.');
        }
        $ids = [];
        foreach ($value as $id) {
            if ((!is_string($id) && !is_int($id)) || !ctype_digit((string) $id) || (int) $id < 1 || (int) $id > 4294967295) {
                throw new \InvalidArgumentException('Close ID lists must contain local numeric IDs.');
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /** @return array<int, string> */
    private static function questionMap(mixed $value): array
    {
        $map = [];
        foreach (self::object($value) as $question => $field) {
            if (!ctype_digit((string) $question) || (int) $question < 1 || !is_string($field)) {
                throw new \InvalidArgumentException('Close answer mappings must use local field IDs.');
            }
            $map[(int) $question] = Settings::id($field, 'cf');
        }
        ksort($map);
        return $map;
    }

    private static function object(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        }
        if (!is_array($value) || count($value) > 100 || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException('Close mappings must be JSON objects.');
        }
        return $value;
    }

    private static function text(mixed $value, int $max): string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \InvalidArgumentException('Invalid Close profile text.');
        }
        return $value;
    }
}
