<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Extension;

use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Advans\Plugin\System\AppointmentsDav\Dav\DavRequestHandler;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

/**
 * Serves the team calendars over CalDAV at `/.well-known/caldav` (RFC 6764,
 * redirect) and below the configured path, `/dav/` by default, relative to
 * the site root. Every other request passes through untouched.
 *
 * The request is taken over in onAfterInitialise, before routing, the session
 * cookie and any page rendering, and the application is closed afterwards.
 * In onAfterRoute it also runs the sign-in lock-out before any password check.
 * Work package A owns this plugin; onAfterRoute is the coordinator's.
 *
 * @since  1.4.0
 */
final class AppointmentsDav extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onAfterInitialise',
            'onAfterRoute'      => 'onAfterRoute',
        ];
    }

    public function onAfterInitialise(): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $services = TeamServices::fromApplication();

        if (!$services->enabled()) {
            return;
        }

        $handler = new DavRequestHandler($app, $this->params, $services);

        if (!$handler->matches()) {
            return;
        }

        $handler->handle();
        $app->close();
    }

    /**
     * The sign-in lock-out before Joomla checks a password, on the site and in
     * the administrator (work package F implements guardLogin()). A failure here
     * never breaks a sign-in.
     */
    public function onAfterRoute(): void
    {
        try {
            $services = TeamServices::fromApplication();

            if ($services->enabled()) {
                $services->lockout()->guardLogin($this->getApplication());
            }
        } catch (\Throwable $e) {
            // A failure must not turn every sign-in into an error page. The
            // second safeguard in the user plugin still applies. No detail is
            // logged: the request carries a password.
            \Joomla\CMS\Log\Log::add('Internet calendars: the sign-in lock-out check failed (' . \get_class($e) . ').', \Joomla\CMS\Log\Log::WARNING, 'com_appointments');
        }
    }
}
