<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View\Manage;

use Advans\Component\Appointments\Administrator\Service\InvalidBookingLinkException;
use Advans\Component\Appointments\Site\Helper\NoPageCache;
use Joomla\CMS\Factory;

defined('_JEXEC') or die;

/**
 * The date-and-time part of the reschedule page on its own (`format=raw`), for the background
 * update of the booking script, like the booking page's own raw view: the same partials, the same
 * data. A link that no longer applies, or an appointment that is no longer open for a change,
 * gets an empty answer; the script then falls back to the full page.
 */
class RawView extends HtmlView
{
    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $app->setHeader('Referrer-Policy', 'no-referrer', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $app->setHeader('Cache-Control', 'private, no-store', true);
        NoPageCache::apply($app);
        $model = $this->getModel();

        try {
            $this->action = $model->action();
            $this->signature = $model->signature();
            $this->completed = $model->completed();
            $this->item = $model->getItem();
        } catch (InvalidBookingLinkException $e) {
            $app->setHeader('status', '404', true);

            return null;
        }

        if ($this->completed || $this->action !== 'reschedule' || $model->cancelled($this->item)) {
            $app->setHeader('status', '404', true);

            return null;
        }

        $active = $app->getMenu()->getActive();
        $this->itemId = $active !== null && ($active->query['option'] ?? '') === 'com_appointments' ? (int) $active->id : 0;
        $invitee = (string) $this->item->invitee_timezone;
        $this->zone = new \DateTimeZone(in_array($invitee, \DateTimeZone::listIdentifiers(), true) ? $invitee : 'UTC');
        $this->prepareReschedule();

        echo $this->loadTemplate('picker') . $this->loadTemplate('zone_note');

        return null;
    }
}
