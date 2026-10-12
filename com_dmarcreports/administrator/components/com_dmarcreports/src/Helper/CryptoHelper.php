<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

use Joomla\CMS\Factory;

defined('_JEXEC') or die;

/**
 * Encrypts and decrypts mailbox credentials at rest. The key is derived from
 * the Joomla site secret, so the ciphertext is bound to this installation and
 * is useless if the database alone is leaked. AES-256-GCM provides
 * authenticated encryption, so tampered ciphertext is rejected rather than
 * silently mis-decrypted.
 *
 * @since  1.0.0
 */
final class CryptoHelper
{
    private const PREFIX = 'g1:';

    private const CIPHER = 'aes-256-gcm';

    /**
     * Encrypt a plaintext secret for storage.
     *
     * @param   string  $plaintext  The value to protect.
     *
     * @return  string  An opaque, versioned token (empty input yields empty output).
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key   = self::key();
        $ivLen = openssl_cipher_iv_length(self::CIPHER) ?: 12;
        $iv    = random_bytes($ivLen);
        $tag   = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException('Unable to encrypt the credential.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a value produced by encrypt().
     *
     * @param   string  $token  The stored token.
     *
     * @return  string  The plaintext, or an empty string when it cannot be recovered.
     */
    public static function decrypt(string $token): string
    {
        if (!self::isEncrypted($token)) {
            return $token;
        }

        $raw = base64_decode(substr($token, \strlen(self::PREFIX)), true);

        if ($raw === false) {
            return '';
        }

        $ivLen = openssl_cipher_iv_length(self::CIPHER) ?: 12;

        if (\strlen($raw) < $ivLen + 16) {
            return '';
        }

        $iv         = substr($raw, 0, $ivLen);
        $tag        = substr($raw, $ivLen, 16);
        $ciphertext = substr($raw, $ivLen + 16);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? '' : $plaintext;
    }

    /**
     * Whether a stored value is an encrypted token produced by this helper.
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    private static function key(): string
    {
        $secret = (string) Factory::getApplication()->get('secret', '');

        if ($secret === '') {
            throw new \RuntimeException('The Joomla site secret is not configured.');
        }

        // A 32 byte key for AES-256, deterministically derived from the site secret.
        return hash('sha256', 'com_dmarcreports:' . $secret, true);
    }
}
