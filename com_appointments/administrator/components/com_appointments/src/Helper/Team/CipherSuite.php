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

    /** Salt length of the password key derivation (SODIUM_CRYPTO_PWHASH_SALTBYTES). */
    public const PWHASH_SALT_BYTES = 16;

    /** SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE; spelled out so the class loads without libsodium. */
    public const PWHASH_OPSLIMIT = 2;

    /** SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE (64 MiB), chosen because shared hosts limit memory. */
    public const PWHASH_MEMLIMIT = 67108864;

    /** Upper bounds accepted from a stored row, so a row can never make one sign-in exhaust the server. */
    public const PWHASH_OPSLIMIT_MAX = 16;
    public const PWHASH_MEMLIMIT_MAX = 1073741824;

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
            'sodium_crypto_box_keypair_from_secretkey_and_publickey',
            'sodium_crypto_box_publickey_from_secretkey',
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

    /**
     * The X25519 public key that belongs to a secret key. Used to check that an
     * unwrapped secret really is the secret of the stored (MAC-protected) public key.
     */
    public static function publicKeyOf(string $secretKey): string
    {
        self::assertKey($secretKey);

        return sodium_crypto_box_publickey_from_secretkey($secretKey);
    }

    /**
     * The password key of specification 5.2:
     * `s = Argon2id(password, salt, opslimit, memlimit)`,
     * `K = HKDF-SHA256(s || serverKey, 32, 'com_appointments/password-kek/v1', salt)`.
     *
     * @throws  \InvalidArgumentException  On a wrong salt, server key or limits outside the accepted range.
     * @throws  \SodiumException           When the server cannot allocate the memory (never a wrong password).
     */
    public static function passwordKey(string $password, string $salt, int $opslimit, int $memlimit, string $serverKey): string
    {
        self::assertKey($serverKey);

        if (\strlen($salt) !== self::PWHASH_SALT_BYTES) {
            throw new \InvalidArgumentException('The password salt has exactly 16 bytes.');
        }

        if (!self::acceptableLimits($opslimit, $memlimit)) {
            throw new \InvalidArgumentException('The password hashing limits are outside the accepted range.');
        }

        $stretched = sodium_crypto_pwhash(self::KEY_BYTES, $password, $salt, $opslimit, $memlimit, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $material = $stretched . $serverKey;
        self::wipe($stretched);
        $key = hash_hkdf('sha256', $material, self::KEY_BYTES, 'com_appointments/password-kek/v1', $salt);
        self::wipe($material);

        return $key;
    }

    /**
     * Whether stored Argon2id limits may be used: never weaker than INTERACTIVE
     * (a downgrade), never so high that a sign-in becomes a denial of service.
     */
    public static function acceptableLimits(int $opslimit, int $memlimit): bool
    {
        return $opslimit >= self::PWHASH_OPSLIMIT && $opslimit <= self::PWHASH_OPSLIMIT_MAX
            && $memlimit >= self::PWHASH_MEMLIMIT && $memlimit <= self::PWHASH_MEMLIMIT_MAX;
    }

    /**
     * Overwrite a string that held key material and leave the variable null.
     * When PHP shares the string with another variable (copy on write), only
     * this variable is cleared; the other copy is wiped by its own owner.
     */
    public static function wipe(?string &$bytes): void
    {
        if (\is_string($bytes) && $bytes !== '' && \function_exists('sodium_memzero')) {
            sodium_memzero($bytes);
        }

        $bytes = null;
    }

    private static function assertKey(string $key): void
    {
        if (\strlen($key) !== self::KEY_BYTES) {
            throw new \InvalidArgumentException('A key has exactly 32 bytes.');
        }
    }
}
