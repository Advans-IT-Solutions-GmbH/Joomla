<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Calendars;

use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Team calendars: The calendars, their owners and grants, and the recovery key.
 *
 * Work package C owns this view and its layout.
 *
 * @since  1.4.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * Whether the team calendars are switched on and libsodium is available.
     *
     * @var  bool
     */
    protected $enabled = false;

    public function display($tpl = null)
    {
        $app = Factory::getApplication();

        if (!$app->getIdentity()->authorise('core.manage', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $app->setHeader('Cache-Control', 'private, no-store', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $this->enabled = TeamServices::fromApplication()->enabled();
        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_TEAM_CALENDARS_TITLE'), 'calendar');

        return parent::display($tpl);
    }
}
