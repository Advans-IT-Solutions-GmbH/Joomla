<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Team;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;

defined('_JEXEC') or die;

/**
 * The app password of one device: format, parsing, stored hash and the key that
 * wraps the user's secret key for this device.
 *
 * An app password is 160 random bits, shown once as 32 Base32 characters in
 * groups of four (`ABCD-EFGH-…`). Because of that entropy the stored hash is a
 * keyed HMAC and not a slow password hash: a CalDAV client authenticates on
 * every request, and a slow hash per request would be a denial of service on a
 * shared host, while a fast hash of 160 random bits cannot be searched.
 *
 * The hash and the wrapping key are domain separated and both need a server key
 * from {@see KeyProvider}, which is derived from the site secret in
 * `configuration.php` and never stored in the database. A copy of the database
 * alone therefore yields neither a usable password test nor a wrapping key.
 *
 * Pure class: no Joomla service, no database, no clock.
 *
 * @since  1.4.0
 */
final class AppPassword
{
    /** Raw secret length in bytes (160 bits). */
    public const BYTES = 20;

    /** Length of the Base32 form without separators. */
    public const CHARS = 32;

    /** Length of the random salt of the wrapping key. */
    public const SALT_BYTES = 16;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new app password: the raw secret and the form shown to the user.
     *
     * @return  array{raw: string, display: string}
     */
    public static function generate(): array
    {
        $raw = random_bytes(self::BYTES);

        return ['raw' => $raw, 'display' => self::format($raw)];
    }

    /**
     * The display form, `XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX`.
     *
     * @throws  \InvalidArgumentException  When the secret has the wrong length.
     */
    public static function format(string $raw): string
    {
        if (\strlen($raw) !== self::BYTES) {
            throw new \InvalidArgumentException('An app password has exactly 20 bytes.');
        }

        return implode('-', str_split(self::encode($raw), 4));
    }

    /**
     * The raw secret of what a client sent, or null when it is not an app
     * password at all. Case, spaces and hyphens are ignored, so a password typed
     * by hand in lower case or without the groups is accepted.
     */
    public static function parse(string $input): ?string
    {
        $normal = strtoupper(str_replace(['-', ' '], '', $input));

        if (\strlen($normal) !== self::CHARS || strspn($normal, self::ALPHABET) !== self::CHARS) {
            return null;
        }

        return self::decode($normal);
    }

    /**
     * The value stored in `#__appointments_devices.secret_hash` (32 raw bytes).
     */
    public static function hash(string $raw, KeyProvider $keys): string
    {
        return hash_hmac(
            'sha256',
            'com_appointments/app-password/v1|' . $raw,
            $keys->key(KeyProvider::PURPOSE_TEAM_APP_PASSWORD),
            true
        );
    }

    /**
     * The key that wraps the user's secret key for this device (32 raw bytes).
     * It needs the app password, the device's salt and the server key; none of
     * the three alone is enough.
     *
     * @throws  \InvalidArgumentException  When the salt has the wrong length.
     */
    public static function wrappingKey(string $raw, string $salt, KeyProvider $keys): string
    {
        if (\strlen($salt) !== self::SALT_BYTES) {
            throw new \InvalidArgumentException('The device salt has exactly 16 bytes.');
        }

        return hash_hkdf(
            'sha256',
            $raw . $keys->key(KeyProvider::PURPOSE_TEAM_DEVICE_KEK),
            32,
            'com_appointments/device-kek/v1',
            $salt
        );
    }

    private static function encode(string $raw): string
    {
        $bits = '';

        foreach (str_split($raw) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function decode(string $text): string
    {
        $bits = '';

        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split(substr($bits, 0, self::BYTES * 8), 8) as $byte) {
            $out .= \chr(bindec($byte));
        }

        return $out;
    }
}
