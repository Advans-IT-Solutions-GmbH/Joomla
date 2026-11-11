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
 * All Close profiles of the component options. Each event type belongs to at most one profile;
 * an event type without a profile is never transferred.
 */
final class Settings
{
    /** @var array<string, Profile> */
    public readonly array $profiles;

    /** @var array<int, string> event type ID => profile key */
    private array $routes = [];

    public function __construct(object $params)
    {
        $raw = $params->get('close_profiles', []);
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? [] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        }
        $entries = json_decode(json_encode($raw ?? [], JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($entries) || $entries === [] || count($entries) > 20) {
            throw new \InvalidArgumentException('No valid Close profile is configured.');
        }
        $profiles = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('Invalid Close profile.');
            }
            $profile = new Profile($entry);
            if (isset($profiles[$profile->key])) {
                throw new \InvalidArgumentException('Close profile keys must be unique.');
            }
            foreach ($profile->eventTypes as $eventType) {
                if (isset($this->routes[$eventType])) {
                    throw new \InvalidArgumentException('An event type can belong to only one Close profile.');
                }
                $this->routes[$eventType] = $profile->key;
            }
            $profiles[$profile->key] = $profile;
        }
        $this->profiles = $profiles;
    }

    public function profileFor(int $eventType): ?Profile
    {
        return isset($this->routes[$eventType]) ? $this->profiles[$this->routes[$eventType]] : null;
    }

    public function profile(string $key): ?Profile
    {
        return $this->profiles[$key] ?? null;
    }

    public static function id(string $id, string $prefix): string
    {
        if (!preg_match('/^' . preg_quote($prefix, '/') . '_[A-Za-z0-9]{1,100}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid Close object identifier.');
        }
        return $id;
    }
}
