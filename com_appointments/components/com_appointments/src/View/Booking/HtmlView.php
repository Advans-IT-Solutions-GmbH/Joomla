<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View\Booking;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Advans\Component\Appointments\Site\View\PageTitleTrait;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    use PageTitleTrait;

    protected $item;

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $app->setHeader('Cache-Control', 'private, no-store', true);
        $this->item = $this->getModel()->getItem();
        $assets = $app->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_appointments');
        $assets->useStyle('com_appointments.site');
        $this->getDocument()->setMetaData('robots', 'noindex,nofollow');
        $this->applyPageTitle('booking', Text::_('COM_APPOINTMENTS_BOOKING_CONFIRMED_HEADING'));

        return parent::display($tpl);
    }
}
