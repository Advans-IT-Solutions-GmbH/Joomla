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
     * Keys the link with which an additional guest removes their own address
     * from a booking. Separate from the invitee's links, so neither kind of
     * link can ever verify as the other.
     */
    public const PURPOSE_GUEST_LINK = 'guest-link';

    /**
     * Keys the rate limiter hashes a visitor's IP address with, so the
     * rate-limit table never holds a readable address.
     */
    public const PURPOSE_RATE_LIMIT = 'rate-limit';

    /**
     * Team calendars, one key per purpose. See
     * `.claude/skills/appointments/references/team-calendars.md`, section
     * "Server keys". Changing the site secret invalidates every one of them.
     */
    public const PURPOSE_TEAM_APP_PASSWORD = 'team-app-password';
    public const PURPOSE_TEAM_DEVICE_KEK = 'team-device-kek';
    public const PURPOSE_TEAM_PASSWORD_KEK = 'team-password-kek';
    public const PURPOSE_TEAM_TIMING = 'team-timing';
    public const PURPOSE_TEAM_ROW_MAC = 'team-row-mac';
    public const PURPOSE_TEAM_UID = 'team-uid';
    public const PURPOSE_TEAM_LOG = 'team-log';
    public const PURPOSE_TEAM_AUTH_BUCKET = 'team-auth-bucket';
    public const PURPOSE_TEAM_INVITATION = 'team-invitation';
    public const PURPOSE_TEAM_ANCHOR = 'team-anchor';
    public const PURPOSE_TEAM_ENVELOPE = 'team-envelope';

    /**
     * Personal data of bookings at rest (encryption), see team-calendars.md,
     * section 5.10. There is deliberately no blind index of e-mail addresses:
     * whoever holds the server key could test known addresses against it.
     */
    public const PURPOSE_BOOKING_DATA = 'booking-data';

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
