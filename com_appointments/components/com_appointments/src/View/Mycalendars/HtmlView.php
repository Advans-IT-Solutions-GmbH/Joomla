<?php
/**
 * @package     Appointments Component
 * @subpackage  Site
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View\Mycalendars;

use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

defined('_JEXEC') or die;

/**
 * «My calendars»: the signed-in user's calendars with their roles, the user's
 * devices and «Add device». Work package D owns this view and its layout.
 *
 * Reachable only signed in and only with the team calendars switched on; a
 * guest and a disabled feature both get 404, so the page does not reveal
 * whether the feature exists.
 *
 * @since  1.4.0
 */
class HtmlView extends BaseHtmlView
{
    public function display($tpl = null)
    {
        $app = Factory::getApplication();

        if ($app->getIdentity()->guest || !TeamServices::fromApplication()->enabled()) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $app->setHeader('Cache-Control', 'private, no-store', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $this->getDocument()->setTitle(Text::_('COM_APPOINTMENTS_MYCALENDARS_TITLE'));

        return parent::display($tpl);
    }
}
