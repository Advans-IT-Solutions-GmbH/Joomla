<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Calendar;

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Service\Team\GrantService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * One internet calendar: its settings, its grants, key rotation and deletion.
 * Reading needs the edit id held (DisplayController); changing anything needs
 * the manager role, which the services check on every call.
 *
 * @since  1.4.0
 */
class HtmlView extends BaseHtmlView
{
    /** @var bool */
    protected $enabled = false;

    /** @var bool */
    protected $available = true;

    /** @var \Joomla\CMS\Form\Form|null */
    protected $form;

    /** @var object|null  The verified calendar row, null for a new calendar. */
    protected $item;

    /** @var object[] */
    protected $grants = [];

    /** @var object[]  Accounts that may receive a grant and hold none on this calendar. */
    protected $candidates = [];

    /** @var object[]  Team members that may own a new calendar. */
    protected $owners = [];

    /** @var CalendarRole|null  The signed-in user's effective role. */
    protected $myRole;

    /** @var bool */
    protected $canShareExternally = false;

    /** @var bool */
    protected $canDelete = false;

    /** @var bool  Whether the signed-in user may see who holds which grant. */
    protected $showGrants = false;

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user->authorise('core.manage', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $app->setHeader('Cache-Control', 'private, no-store', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $services = TeamServices::fromApplication();
        $this->enabled = $services->enabled();
        $id = $app->getInput()->getInt('id', 0);
        $this->setLayout('edit');

        if (!$this->enabled) {
            ToolbarHelper::title(Text::_('COM_APPOINTMENTS_TEAM_CALENDARS_TITLE'), 'calendar');

            return parent::display($tpl);
        }

        $app->getInput()->set('hidemainmenu', true);
        $data = (array) $app->getUserState('com_appointments.edit.calendar.data', []);

        try {
            if ($id > 0) {
                $this->item = $services->calendars()->get($id);

                if ($this->item === null) {
                    throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
                }

                $this->myRole = $services->grants()->accessFor((int) $user->id, $id)?->role;

                // Who holds which grant (and whether it is effective, which
                // tells about a second factor) is shown to the calendar's
                // managers and to administrators only, not to every editor.
                $this->showGrants = $this->myRole === CalendarRole::Manage || $user->authorise('core.admin', 'com_appointments');
                $this->grants = $this->showGrants ? $services->grants()->grantsOf($id) : [];
                $held = array_map(static fn (object $grant): int => (int) $grant->user_id, $this->grants);
                $this->canShareExternally = (int) $this->item->allow_external === 1
                    && GrantService::sharesExternally($services, (int) $user->id);

                foreach (GrantService::eligibleUsers($services) as $account) {
                    if (!\in_array($account->id, $held, true) && $account->id !== (int) $user->id
                        && ($account->audience === 'team' ? $this->myRole === CalendarRole::Manage : $this->canShareExternally)) {
                        $this->candidates[] = $account;
                    }
                }

                $this->canDelete = $user->authorise('core.delete', 'com_appointments') && $this->myRole === CalendarRole::Manage;
                $data = $data ?: (array) $this->item;
            } else {
                foreach (GrantService::eligibleUsers($services) as $account) {
                    if ($account->audience === 'team') {
                        $this->owners[] = $account;
                    }
                }
            }
        } catch (TeamException) {
            $this->available = false;
        }

        $this->form = $this->getModel()->getForm($data);
        $editable = $id > 0 ? $this->myRole === CalendarRole::Manage : $user->authorise('core.create', 'com_appointments');

        if (!$editable && $this->form) {
            foreach (['title', 'description', 'colour', 'allow_external', 'blocks_availability', 'retention_days'] as $field) {
                $this->form->setFieldAttribute($field, 'disabled', 'true');
            }
        }

        ToolbarHelper::title(Text::_($id > 0 ? 'COM_APPOINTMENTS_TEAM_C_CALENDAR_EDIT_TITLE' : 'COM_APPOINTMENTS_TEAM_C_CALENDAR_NEW_TITLE'), 'calendar');
        $toolbar = Toolbar::getInstance('toolbar');

        if ($editable && $this->available) {
            $toolbar->apply('calendars.apply');
            $toolbar->save('calendars.save');
        }

        $toolbar->cancel('calendars.cancel', $id > 0 ? 'JTOOLBAR_CLOSE' : 'JTOOLBAR_CANCEL');

        return parent::display($tpl);
    }
}
