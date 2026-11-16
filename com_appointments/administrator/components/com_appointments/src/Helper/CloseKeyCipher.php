<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Seals the Close API key of one event type for storage.
 *
 * The value is a sodium secretbox (XSalsa20-Poly1305) with a random nonce per
 * value. Its key is derived from the Joomla site secret for this purpose and
 * then for the event type, so a sealed value cannot be moved to another event
 * type. The database never holds the key in clear.
 *
 * Opening never throws: a changed site secret, a moved, truncated or forged
 * value all read as "no usable key". The operator then enters the key again.
 *
 * Pure: no Joomla service, no database, testable without a Joomla bootstrap.
 */
final class CloseKeyCipher
{
    /** Longest key accepted, the same bound the HTTPS transport enforces. */
    public const MAX_LENGTH = 1024;

    private const PREFIX = 'v1:';

    private string $key;

    /**
     * @throws \InvalidArgumentException When the installation has no site secret.
     */
    public function __construct(string $siteSecret)
    {
        $this->key = (new KeyProvider($siteSecret))->key(KeyProvider::PURPOSE_CLOSE_API_KEY);
    }

    /**
     * Whether the value can be sent as the user name of HTTP Basic authentication: visible ASCII,
     * no colon (the separator of user name and password), at most MAX_LENGTH bytes.
     */
    public static function valid(string $apiKey): bool
    {
        return $apiKey !== '' && strlen($apiKey) <= self::MAX_LENGTH && preg_match('/^[\x21-\x39\x3b-\x7e]+$/D', $apiKey) === 1;
    }

    /**
     * The last four characters, shown so the operator recognises the stored key. A key shorter than
     * 16 characters gets no hint, because four characters would disclose too much of it.
     */
    public static function hint(string $apiKey): string
    {
        return strlen($apiKey) >= 16 ? substr($apiKey, -4) : '';
    }

    /**
     * @throws \InvalidArgumentException When the event type or the key is unusable. The message never contains the key.
     */
    public function seal(int $eventType, string $apiKey): string
    {
        if ($eventType < 1) {
            throw new \InvalidArgumentException('A Close API key belongs to an event type.');
        }

        if (!self::valid($apiKey)) {
            throw new \InvalidArgumentException('The Close API key contains characters that cannot be used.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->subkey($eventType);

        try {
            return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($apiKey, $nonce, $key));
        } finally {
            sodium_memzero($key);
        }
    }

    /**
     * The stored key, or null when the value cannot be opened with this site secret for this event type.
     */
    public function open(int $eventType, string $sealed): ?string
    {
        if ($eventType < 1 || !str_starts_with($sealed, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);

        if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $key = $this->subkey($eventType);

        try {
            $apiKey = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        } catch (\SodiumException $e) {
            return null;
        } finally {
            sodium_memzero($key);
        }

        return is_string($apiKey) && self::valid($apiKey) ? $apiKey : null;
    }

    private function subkey(int $eventType): string
    {
        return sodium_crypto_generichash('com_appointments:close-api-key:eventtype:' . $eventType, $this->key,
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
