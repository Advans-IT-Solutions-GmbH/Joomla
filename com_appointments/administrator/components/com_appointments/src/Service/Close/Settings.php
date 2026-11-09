<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Close;

defined('_JEXEC') or die;

final class Settings
{
    public readonly string $origin;
    public readonly string $type;
    public readonly array $fields;
    public readonly array $questions;
    public readonly string $credential;
    public readonly string $binding;

    public function __construct(public readonly object $params)
    {
        $this->origin = rtrim((string) $params->get('close_origin', ''), '/');
        $parts = parse_url($this->origin);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || filter_var($parts['host'], FILTER_VALIDATE_IP) || !str_contains($parts['host'], '.')
            || preg_match('/[\s\x00-\x1f\x7f]/', $this->origin)) {
            throw new \InvalidArgumentException('Close API origin is not configured as an HTTPS origin.');
        }
        $this->type = self::id((string) $params->get('close_type', ''), 'actitype');
        $this->credential = (string) $params->get('close_credential_env', '');
        if (!preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $this->credential)) {
            throw new \InvalidArgumentException('Close credential environment reference is missing.');
        }
        $this->fields = $this->mapping('close_fields');
        $required = ['uid', 'sequence', 'start', 'end', 'state', 'source'];
        if (array_diff($required, array_keys($this->fields)) || array_diff(array_keys($this->fields), $required)) {
            throw new \InvalidArgumentException('Close booking field mapping is incomplete.');
        }
        $this->questions = $this->mapping('close_questions');
        foreach (array_keys($this->questions) as $question) {
            if (!ctype_digit((string) $question) || (int) $question < 1) {
                throw new \InvalidArgumentException('Close question whitelist must use local field IDs.');
            }
        }
        $all = array_merge(array_values($this->fields), array_values($this->questions));
        if (count($all) !== count(array_unique($all))) {
            throw new \InvalidArgumentException('Close mappings must use distinct destination fields.');
        }
        // A mapping change must not silently move already delivered activities.
        $this->binding = hash('sha256', json_encode([$this->origin, $this->type, $this->fields, $this->questions], JSON_THROW_ON_ERROR));
    }

    public static function id(string $id, string $prefix): string
    {
        if (!preg_match('/^' . preg_quote($prefix, '/') . '_[A-Za-z0-9]{1,100}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid Close object identifier.');
        }
        return $id;
    }

    private function mapping(string $name): array
    {
        $raw = trim((string) $this->params->get($name, '{}'));
        $value = json_decode($raw === '' ? '{}' : $raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || count($value) > 100) {
            throw new \InvalidArgumentException('Invalid Close mapping.');
        }
        foreach ($value as $id) {
            if (!is_string($id)) {
                throw new \InvalidArgumentException('Invalid Close custom field identifier.');
            }
            self::id($id, 'cf');
        }
        ksort($value);
        return $value;
    }
}
