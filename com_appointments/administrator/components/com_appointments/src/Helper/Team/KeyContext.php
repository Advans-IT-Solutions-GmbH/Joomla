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
 * A user's unlocked X25519 key pair for the length of one request. Only the key
 * service creates it: from the Joomla password at sign-in, from an app password
 * on a CalDAV request, or from the session escrow of a signed-in session that
 * completed the second factor.
 *
 * @since  1.4.0
 */
final class KeyContext extends SecretValue
{
    /**
     * @var  int
     */
    private $userId;

    /**
     * @var  int
     */
    private $userKeyVersion;

    /**
     * @var  string
     */
    private $publicKey;

    /**
     * @param   int     $userId          Joomla user id.
     * @param   int     $userKeyVersion  `#__appointments_user_keys.key_version`.
     * @param   string  $secretKey       32 raw bytes, X25519 secret key.
     * @param   string  $publicKey       32 raw bytes, X25519 public key.
     */
    public static function create(int $userId, int $userKeyVersion, string $secretKey, string $publicKey): self
    {
        if ($userId <= 0 || \strlen($secretKey) !== 32 || \strlen($publicKey) !== 32) {
            throw new \InvalidArgumentException('A key context needs a user and a 32-byte key pair.');
        }

        $context = new self($secretKey);
        $context->userId = $userId;
        $context->userKeyVersion = $userKeyVersion;
        $context->publicKey = $publicKey;

        return $context;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function userKeyVersion(): int
    {
        return $this->userKeyVersion;
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * The X25519 secret key, for sodium_crypto_box_seal_open() with the public key.
     */
    public function secretKey(): string
    {
        return $this->bytes();
    }
}
