<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Feeds;

use Advans\Component\Appointments\Administrator\Service\FeedService;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    protected $items = [];

    /** Whether the deprecated token feeds are switched on (option `legacy_feeds`). */
    protected $legacy = false;

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$user->authorise('core.manage', 'com_appointments') || !$user->authorise('core.options', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        $app->setHeader('Cache-Control', 'private, no-store', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $app->setHeader('Referrer-Policy', 'no-referrer', true);
        // Switched off, no credential is read and no address is shown, only the replacement.
        $this->legacy = FeedService::legacyEnabled(ComponentHelper::getParams('com_appointments'));
        $this->items = $this->legacy ? $this->getModel()->getItems() : [];
        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_FEEDS_TITLE'), 'calendar');
        Toolbar::getInstance('toolbar')->linkButton('dashboard')->url('index.php?option=com_appointments')
            ->text(Text::_('COM_APPOINTMENTS_TOOLBAR_DASHBOARD'));

        return parent::display($tpl);
    }
}
