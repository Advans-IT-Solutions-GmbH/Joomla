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
 * Autoloader of the bundled, namespace-prefixed sabre/dav libraries in
 * `administrator/components/com_appointments/lib/` (work package A).
 *
 * The libraries are prefixed with `Advans\Component\Appointments\Vendor\`
 * (php-scoper), so they can never collide with another extension that ships
 * its own copy of sabre/dav or sabre/vobject, nor with Joomla's own vendor
 * folder. Every caller that needs sabre (the CalDAV plugin, the timing index)
 * calls register() first; a second call does nothing.
 *
 * @since  1.4.0
 */
final class VendorAutoloader
{
    /** Namespace prefix of the bundled libraries. */
    public const PREFIX = 'Advans\\Component\\Appointments\\Vendor\\';

    /**
     * @return  bool  Whether the bundled libraries are present and registered.
     */
    public static function register(): bool
    {
        return false;
    }
}
