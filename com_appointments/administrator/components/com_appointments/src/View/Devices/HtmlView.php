<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Devices;

use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Internet calendars: every device with its user, state and last synchronisation; blocking with
 * clean-up, immediate revocation, blocking a user's calendar access. `core.admin` only.
 *
 * Work package F owns this view and its layout.
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

    protected $items = [];

    protected $pagination;

    protected $state;

    public function display($tpl = null)
    {
        $app = Factory::getApplication();

        if (!$app->getIdentity()->authorise('core.admin', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $app->setHeader('Cache-Control', 'private, no-store', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $this->enabled = TeamServices::fromApplication()->enabled();
        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_TEAM_DEVICES_TITLE'), 'calendar');
        $toolbar = Toolbar::getInstance('toolbar');

        if ($this->enabled) {
            $model = $this->getModel();
            $this->items = $model->getItems();
            $this->pagination = $model->getPagination();
            $this->state = $model->getState();
            $toolbar->standardButton('lock')->text(Text::_('COM_APPOINTMENTS_TEAM_F_DEVICES_BLOCK'))->task('devices.block')->listCheck(true);
            $toolbar->standardButton('unpublish')->text(Text::_('COM_APPOINTMENTS_TEAM_F_DEVICES_BLOCK_USER'))->task('devices.blockUser')->listCheck(true);
            $toolbar->standardButton('delete')->text(Text::_('COM_APPOINTMENTS_TEAM_F_DEVICES_REVOKE'))->task('devices.revoke')->listCheck(true);
            $toolbar->standardButton('unlock')->text(Text::_('COM_APPOINTMENTS_TEAM_F_DEVICES_UNLOCK'))->task('devices.unlock')->listCheck(true);
            $toolbar->standardButton('trash')->text(Text::_('COM_APPOINTMENTS_TEAM_F_DEVICES_SET_CLEANUP'))->task('devices.modeCleanup')->listCheck(true);
            $toolbar->standardButton('cancel-circle')->text(Text::_('COM_APPOINTMENTS_TEAM_F_DEVICES_SET_BLOCK'))->task('devices.modeBlock')->listCheck(true);
        }

        $toolbar->linkButton('dashboard')->url('index.php?option=com_appointments')->text(Text::_('COM_APPOINTMENTS_TOOLBAR_DASHBOARD'));
        $toolbar->linkButton('calendars')->url('index.php?option=com_appointments&view=calendars')
            ->text(Text::_('COM_APPOINTMENTS_TEAM_C_NAV_CALENDARS'))->icon('icon-calendar');

        return parent::display($tpl);
    }
}