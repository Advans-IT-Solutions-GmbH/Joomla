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
 * The secret of a token feed address. The database holds only a keyed digest
 * of it, "h1:" plus HMAC-SHA256 (hex) under the server key of
 * KeyProvider::PURPOSE_FEED_TOKEN, never the token itself: a copy of the
 * database alone cannot open a feed, and a value written into the database
 * without the site secret matches no token.
 *
 * A stored value without the prefix is a clear-text token from before 1.5.0.
 * It never authenticates. The installer converts the values of tables from up to 1.4
 * once (FeedService::convertLegacy); a value written later is never converted.
 */
final class FeedToken
{
    public const PREFIX = 'h1:';

    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** The stored form of a token. */
    public static function digest(string $key, string $token): string
    {
        return self::PREFIX . hash_hmac('sha256', $token, $key);
    }

    /**
     * A stored value for a feed whose address nobody has seen yet: no token
     * matches it until a rotation issues one.
     */
    public static function unissued(): string
    {
        return self::PREFIX . bin2hex(random_bytes(32));
    }

    public static function isDigest(string $stored): bool
    {
        return preg_match('/\Ah1:[a-f0-9]{64}\z/D', $stored) === 1;
    }

    /**
     * A new token whose digest differs from the stored one. The caller stores
     * the digest of the result and shows the token itself once.
     */
    public static function rotate(string $key, string $stored): string
    {
        do {
            $replacement = self::generate();
        } while (hash_equals($stored, self::digest($key, $replacement)));

        return $replacement;
    }

    /** Compares digests in constant time, so the comparison reveals nothing about the stored value. */
    public static function verify(string $key, string $stored, string $supplied): bool
    {
        // Even a missing/malformed stored value or token goes through the comparison.
        $valid = self::isDigest($stored);
        $wellFormed = preg_match('/\A[a-f0-9]{64}\z/D', $supplied) === 1;
        $matches = hash_equals($valid ? $stored : self::PREFIX . str_repeat('0', 64), self::digest($key, $supplied));

        return $valid && $wellFormed && $matches;
    }
}
