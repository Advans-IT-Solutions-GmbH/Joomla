<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\Attribution;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

/**
 * Collects the origin of a visit (Attribution) in the Joomla session and hands it to a booking.
 *
 * {@see self::capture()} runs once per request: from the bundled system plugin on every site
 * page (also a landing article that is no page of this component) and from the component's
 * own site dispatcher, so the booking page counts even with the plugin disabled. Only a page
 * view counts: a GET request of the site for an HTML page, not an Ajax or raw request.
 * Server side only: no cookie of its own, no script, nothing a visitor can edit in the form.
 *
 * Off until the component option `attribution_capture` is switched on (default off): only a site
 * whose privacy notice names this processing may collect and store it.
 *
 * @since  1.6.0
 */
final class AttributionTracker
{
    private static bool $captured = false;

    public static function enabled(): bool
    {
        return (int) ComponentHelper::getParams('com_appointments')->get('attribution_capture', 0) === 1;
    }

    /** Records one page view of the site in the session; never breaks a page. */
    public static function capture(CMSApplicationInterface $app): void
    {
        if (self::$captured) {
            return;
        }

        self::$captured = true;

        try {
            if (!$app->isClient('site') || !self::enabled()) {
                return;
            }

            $input = $app->getInput();
            $format = strtolower((string) $input->get->getCmd('format', 'html'));
            $option = strtolower((string) $input->get->getCmd('option', ''));

            if (strtoupper((string) $input->server->getString('REQUEST_METHOD', 'GET')) !== 'GET' || $format !== 'html' || $option === 'com_ajax'
                || strtolower((string) $input->server->getString('HTTP_X_REQUESTED_WITH', '')) === 'xmlhttprequest') {
                return;
            }

            $uri = Uri::getInstance();
            // The query as sent, parsed from the address: Joomla 6's input has no raw default filter for a
            // whole array and would strip markup from a value. Attribution cleans every value itself.
            parse_str((string) $uri->getQuery(), $query);
            $session = $app->getSession();
            $state = $session->get(Attribution::SESSION_KEY);
            $session->set(Attribution::SESSION_KEY, Attribution::visit(\is_array($state) ? $state : null, [
                'uri' => $uri->toString(['path', 'query']),
                'referrer' => (string) $input->server->get('HTTP_REFERER', '', 'raw'),
                'host' => $uri->getHost(),
                'query' => $query,
                'ip' => (string) $input->server->getString('REMOTE_ADDR', ''),
            ], new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        } catch (\Throwable $e) {
            Log::add('Appointment attribution: a page view could not be recorded (' . $e::class . ').', Log::WARNING, 'com_appointments');
        }
    }

    /**
     * The record for the booking the current request submits, as stored JSON; null when the
     * option is off. Taken from the session and the submit request itself.
     */
    public static function forBooking(CMSApplicationInterface $app, \DateTimeImmutable $now): ?string
    {
        if (!self::enabled()) {
            return null;
        }

        try {
            $input = $app->getInput();
            $state = $app->getSession()->get(Attribution::SESSION_KEY);

            return Attribution::encode(Attribution::record(\is_array($state) ? $state : null, [
                'user_agent' => (string) $input->server->get('HTTP_USER_AGENT', '', 'raw'),
                'accept_language' => (string) $input->server->get('HTTP_ACCEPT_LANGUAGE', '', 'raw'),
                'ip' => (string) $input->server->getString('REMOTE_ADDR', ''),
                'referrer' => (string) $input->server->get('HTTP_REFERER', '', 'raw'),
                'host' => Uri::getInstance()->getHost(),
            ], $now));
        } catch (\Throwable $e) {
            // The booking is taken all the same, without its origin.
            Log::add('Appointment attribution: the origin of a booking could not be read (' . $e::class . ').', Log::WARNING, 'com_appointments');

            return null;
        }
    }
}
