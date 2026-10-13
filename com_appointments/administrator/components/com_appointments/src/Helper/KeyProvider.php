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
 * Supplies the component's signing keys.
 *
 * Every key is derived from the Joomla site secret, so it is stable for the
 * life of the installation, unique per site, never written to the database and
 * never rendered into a page. One key is derived per purpose, so a key that is
 * used for a signed link can never verify a rate-limit bucket and the two
 * cannot be confused in a later refactor.
 *
 * The class is a pure function of its input: no Joomla service, no database,
 * no clock. It is constructed with the secret, not with the application, which
 * is what makes it testable without a Joomla bootstrap.
 *
 * @since  1.0.0
 */
final class KeyProvider
{
    /**
     * Keys the reschedule, cancel and erasure links are signed with.
     */
    public const PURPOSE_SIGNED_LINK = 'signed-link';

    /**
     * Keys the rate limiter hashes a visitor's IP address with, so the
     * rate-limit table never holds a readable address.
     */
    public const PURPOSE_RATE_LIMIT = 'rate-limit';

    /**
     * @var  string
     */
    private $siteSecret;

    /**
     * @param   string  $siteSecret  The Joomla site secret of this installation.
     *
     * @throws  \InvalidArgumentException  When the installation has no secret.
     */
    public function __construct(string $siteSecret)
    {
        if ($siteSecret === '') {
            throw new \InvalidArgumentException('The Joomla site secret is not configured.');
        }

        $this->siteSecret = $siteSecret;
    }

    /**
     * The 32 raw bytes of the key for one purpose.
     *
     * @param   string  $purpose  One of the PURPOSE_* constants.
     *
     * @return  string  32 raw bytes.
     *
     * @throws  \InvalidArgumentException  When the purpose is empty.
     */
    public function key(string $purpose): string
    {
        if ($purpose === '') {
            throw new \InvalidArgumentException('A key purpose must not be empty.');
        }

        return hash_hmac('sha256', 'com_appointments:' . $purpose, $this->siteSecret, true);
    }
}
