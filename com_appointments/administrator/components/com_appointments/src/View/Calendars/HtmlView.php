<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Calendars;

use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Service\Team\CalendarService;
use Advans\Component\Appointments\Administrator\Service\Team\GrantService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Internet calendars: the calendars, their owners, the signed-in user's own
 * role, and the site recovery key (setup, rotation, use). Lists hold no key
 * material and no calendar content: a Super User without a grant sees the
 * list, never what is in a calendar.
 *
 * @since  1.4.0
 */
class HtmlView extends BaseHtmlView
{
    /** @var bool */
    protected $enabled = false;

    /** @var bool  False while a service this view needs is not available. */
    protected $available = true;

    /** @var object[] */
    protected $items = [];

    /** @var array<int, string>  Calendar id => role label key of the signed-in user. */
    protected $roles = [];

    /** @var string|null  Fingerprint of the recovery key, null when none exists. */
    protected $recoveryFingerprint;

    /** @var bool */
    protected $recoveryAvailable = true;

    /** @var string|null  The recovery secret, only in the response that created it. */
    protected $recoverySecret;

    /** @var object[] */
    protected $users = [];

    /** @var object[]  Accounts whose stored key row no longer verifies (id, name, username). */
    protected $invalidKeys = [];

    /** @var bool */
    protected $canCreate = false;

    /** @var bool */
    protected $canAdmin = false;

    /** @var bool */
    protected $canEdit = false;

    /** @var bool  core.admin and core.delete: may delete a calendar that fails its check. */
    protected $canDeleteInvalid = false;

    /** @var bool */
    protected $canInvite = false;

    /**
     * Called by the controller in the response that created or rotated the
     * recovery key; the secret is never stored anywhere else.
     */
    public function setRecoverySecret(string $secret): void
    {
        $this->recoverySecret = $secret;
    }

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user->authorise('core.manage', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $app->setHeader('Cache-Control', 'private, no-store', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $app->setHeader('Referrer-Policy', 'no-referrer', true);
        $services = TeamServices::fromApplication();
        $this->enabled = $services->enabled();
        $this->canCreate = $user->authorise('core.create', 'com_appointments');
        $this->canEdit = $user->authorise('core.edit', 'com_appointments');
        $this->canAdmin = $user->authorise('core.admin', 'com_appointments');
        $this->canDeleteInvalid = $this->canAdmin && $user->authorise('core.delete', 'com_appointments');
        $this->canInvite = $this->canAdmin || $user->authorise('appointments.team.external', 'com_appointments');
        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_TEAM_CALENDARS_TITLE'), 'calendar');

        if ($this->enabled) {
            try {
                $this->items = CalendarService::listing($services);

                // The signed-in user's effective accesses in one call, not one per calendar.
                foreach ($services->grants()->calendarsFor((int) $user->id) as $access) {
                    $this->roles[$access->calendarId] = GrantService::roleLabelKey($access->role);
                }

                if ($this->canAdmin) {
                    $this->users = GrantService::eligibleUsers($services);
                    $this->invalidKeys = $this->invalidKeys($services);
                }
            } catch (TeamException) {
                $this->available = false;
                $this->items = [];
            }

            try {
                $this->recoveryFingerprint = $services->keys()->recoveryFingerprint();
            } catch (TeamException) {
                $this->recoveryAvailable = false;
            }

            $toolbar = Toolbar::getInstance('toolbar');

            if ($this->canCreate && $this->available) {
                $toolbar->addNew('calendars.add');
            }

            if ($this->canInvite) {
                $toolbar->linkButton('invitations')->url('index.php?option=com_appointments&view=invitations')
                    ->text(Text::_('COM_APPOINTMENTS_TEAM_C_NAV_INVITATIONS'))->icon('icon-envelope');
            }
        }

        return parent::display($tpl);
    }

    /**
     * Accounts with a key row that exists but does not verify (the key service
     * returns no public key for it). Only these can be discarded.
     *
     * @return  object[]
     */
    private function invalidKeys(TeamServices $services): array
    {
        $db = $services->db();
        $rows = $db->setQuery(
            'SELECT k.user_id AS id, u.name, u.username FROM ' . $db->quoteName('#__appointments_user_keys') . ' AS k'
            . ' LEFT JOIN ' . $db->quoteName('#__users') . ' AS u ON u.id = k.user_id ORDER BY u.name, k.user_id LIMIT 1000'
        )->loadObjectList() ?: [];

        return array_values(array_filter($rows, static fn (object $row): bool => $services->keys()->userPublicKey((int) $row->id) === null));
    }
}
