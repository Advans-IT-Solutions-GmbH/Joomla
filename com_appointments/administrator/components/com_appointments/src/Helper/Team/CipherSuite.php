<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Team;

defined('_JEXEC') or die;

/**
 * The only place that calls libsodium for the team calendars. Every other class
 * goes through these functions, so the primitives and the byte layout are fixed
 * in one file:
 *
 * - Symmetric: XChaCha20-Poly1305 (IETF), random 24-byte nonce, stored as
 *   `nonce || ciphertext`. Associated data binds a ciphertext to its place, so
 *   an object cannot be moved to another calendar or URI unnoticed.
 * - Asymmetric: X25519 sealed boxes (`crypto_box_seal`), for writing to a key
 *   holder without holding any secret: the booking flow writes to a calendar,
 *   a grant wraps a calendar key for a user, every calendar key is sealed to
 *   the recovery key.
 *
 * Pure class: no Joomla service, no database, no clock.
 *
 * @since  1.4.0
 */
final class CipherSuite
{
    public const KEY_BYTES = 32;
    public const NONCE_BYTES = 24;

    /**
     * Whether libsodium is available with every function this class needs. The
     * system check reports it, and the team calendars refuse to activate without.
     */
    public static function available(): bool
    {
        foreach ([
            'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt',
            'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt',
            'sodium_crypto_box_keypair',
            'sodium_crypto_box_seal',
            'sodium_crypto_box_seal_open',
            'sodium_crypto_pwhash',
            'sodium_memzero',
        ] as $function) {
            if (!\function_exists($function)) {
                return false;
            }
        }

        return true;
    }

    public static function randomKey(): string
    {
        return random_bytes(self::KEY_BYTES);
    }

    /**
     * @return  array{secret: string, public: string}  X25519 key pair, 32 raw bytes each.
     */
    public static function keyPair(): array
    {
        $pair = sodium_crypto_box_keypair();
        $keys = [
            'secret' => sodium_crypto_box_secretkey($pair),
            'public' => sodium_crypto_box_publickey($pair),
        ];
        sodium_memzero($pair);

        return $keys;
    }

    /**
     * Associated data in one canonical form: purpose and parts, length-prefixed,
     * so `('a', 'bc')` and `('ab', 'c')` never produce the same bytes.
     */
    public static function associatedData(string $purpose, string|int ...$parts): string
    {
        $out = 'com_appointments/' . $purpose . '/v1';

        foreach ($parts as $part) {
            $part = (string) $part;
            $out .= '|' . \strlen($part) . ':' . $part;
        }

        return $out;
    }

    /**
     * @return  string  `nonce || ciphertext`.
     */
    public static function encrypt(string $key, string $plaintext, string $associatedData): string
    {
        self::assertKey($key);
        $nonce = random_bytes(self::NONCE_BYTES);

        return $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associatedData, $nonce, $key);
    }

    /**
     * @return  string|null  The plaintext, or null when the key, the associated
     *                       data or the ciphertext does not match.
     */
    public static function decrypt(string $key, string $payload, string $associatedData): ?string
    {
        self::assertKey($key);

        if (\strlen($payload) < self::NONCE_BYTES + 16) {
            return null;
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($payload, self::NONCE_BYTES),
            $associatedData,
            substr($payload, 0, self::NONCE_BYTES),
            $key
        );

        return $plaintext === false ? null : $plaintext;
    }

    public static function seal(string $publicKey, string $plaintext): string
    {
        self::assertKey($publicKey);

        return sodium_crypto_box_seal($plaintext, $publicKey);
    }

    /**
     * @return  string|null  The plaintext, or null when the box was not sealed to this key pair.
     */
    public static function open(string $secretKey, string $publicKey, string $sealed): ?string
    {
        self::assertKey($secretKey);
        self::assertKey($publicKey);
        $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, $publicKey);
        $plaintext = sodium_crypto_box_seal_open($sealed, $pair);
        sodium_memzero($pair);

        return $plaintext === false ? null : $plaintext;
    }

    private static function assertKey(string $key): void
    {
        if (\strlen($key) !== self::KEY_BYTES) {
            throw new \InvalidArgumentException('A key has exactly 32 bytes.');
        }
    }
}
