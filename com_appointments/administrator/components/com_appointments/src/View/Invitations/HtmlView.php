<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Invitations;

use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Service\Team\InvitationService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Activation links of administrator-created accounts, and the page that
 * revokes a completed activation in one step (layout `revoke`, linked from the
 * administrators' mail). Needs `core.admin` or `appointments.team.external`;
 * which account an administrator may act on is decided by the service.
 *
 * @since  1.4.0
 */
class HtmlView extends BaseHtmlView
{
    /** @var bool */
    protected $enabled = false;

    /** @var bool */
    protected $available = true;

    /** @var object[] */
    protected $items = [];

    /** @var object[] */
    protected $candidates = [];

    /** @var object|null  The activation on the revoke page. */
    protected $item;

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user->authorise('core.manage', 'com_appointments')
            || (!$user->authorise('core.admin', 'com_appointments') && !$user->authorise('appointments.team.external', 'com_appointments'))) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $app->setHeader('Cache-Control', 'private, no-store', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $app->setHeader('Referrer-Policy', 'no-referrer', true);
        $services = TeamServices::fromApplication();
        $this->enabled = $services->enabled();
        $revoke = $this->getLayout() === 'revoke';
        ToolbarHelper::title(Text::_($revoke ? 'COM_APPOINTMENTS_TEAM_C_REVOKE_TITLE' : 'COM_APPOINTMENTS_TEAM_INVITATIONS_TITLE'), 'envelope');

        if ($this->enabled) {
            try {
                if ($revoke) {
                    $this->item = InvitationService::find($services, $app->getInput()->getInt('id', 0));
                } else {
                    $this->items = InvitationService::listing($services);
                    $this->candidates = InvitationService::candidates($services);
                }
            } catch (TeamException) {
                $this->available = false;
            }
        }

        Toolbar::getInstance('toolbar')->linkButton('calendars')->url('index.php?option=com_appointments&view=calendars')
            ->text(Text::_('COM_APPOINTMENTS_TEAM_C_NAV_CALENDARS'))->icon('icon-calendar');

        if ($revoke) {
            Toolbar::getInstance('toolbar')->linkButton('invitations')->url('index.php?option=com_appointments&view=invitations')
                ->text(Text::_('COM_APPOINTMENTS_TEAM_C_BACK_TO_LIST'))->icon('icon-arrow-left');
        }

        return parent::display($tpl);
    }
}
