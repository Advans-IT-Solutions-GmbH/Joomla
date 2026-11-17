<?php
/**
 * @package     Appointments Attribution
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsAttribution\Extension;

use Advans\Component\Appointments\Administrator\Service\AttributionTracker;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

/**
 * Records the origin of a visit (landing page, campaign parameters, referrer from another host)
 * in the Joomla session on every page of the site, also on a landing article that is no page
 * of the Appointments component, so a booking made later in the same session carries it.
 *
 * Runs at onAfterInitialise, before routing: it reads the address as requested (path, query,
 * Referer) and needs nothing the router decides. Everything else is in the component
 * (Service\AttributionTracker, Helper\Attribution).
 */
final class AppointmentsAttribution extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onAfterInitialise' => 'onAfterInitialise'];
    }

    public function onAfterInitialise(): void
    {
        // The component may be missing (removed without its plugins): then there is nothing to record for.
        if (!class_exists(AttributionTracker::class)) {
            return;
        }

        AttributionTracker::capture($this->getApplication());
    }
}
