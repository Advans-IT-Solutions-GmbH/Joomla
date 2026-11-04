<?php
/**
 * @package     Appointments Component
 * @subpackage  Site
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View\Activation;

use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Advans\Component\Appointments\Site\View\Mycalendars\HtmlView as MycalendarsView;
use Advans\Component\Appointments\Site\View\PageTitleTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

defined('_JEXEC') or die;

/**
 * Account activation of the internet calendars: an administrator created the
 * account and sent a one-time link; this page shows the user name and the
 * masked address and sets the password, nothing else. Joomla enforces the
 * second factor at the first sign-in. An unknown, used or expired link, a
 * disabled feature and a malformed token all answer 404 alike.
 *
 * @since  1.4.0
 */
class HtmlView extends BaseHtmlView
{
    use PageTitleTrait;

    /** User state of the last refused submission: messages only, never a password. */
    public const FORM_STATE = 'com_appointments.team.activation_errors';

    /** @var array{username: string, email_masked: string, expires_utc: string}|null */
    protected $account;

    protected $token = '';

    protected $signedIn = false;

    /** @var array<string, string> */
    protected $errors = [];

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        MycalendarsView::privateHeaders();
        $services = TeamServices::fromApplication();
        $token = (string) $app->getInput()->getString('token', '');

        if (!$services->enabled() || !self::isToken($token)) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        try {
            $this->account = $services->invitations()->inspect($token);
        } catch (TeamException $e) {
            $this->account = null;
        }

        if ($this->account === null) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $this->token = $token;
        $this->signedIn = !$app->getIdentity()->guest;
        $this->errors = array_filter((array) $app->getUserState(self::FORM_STATE, []), 'is_string');
        $app->setUserState(self::FORM_STATE, null);

        $assets = $this->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_appointments');
        $assets->useStyle('com_appointments.site');
        $assets->registerAndUseStyle('com_appointments.mycalendars', 'com_appointments/mycalendars.css', [], [], ['com_appointments.site']);
        $assets->registerAndUseScript('com_appointments.mycalendars', 'com_appointments/mycalendars.js', [], ['defer' => true], []);
        $this->getDocument()->setMetaData('robots', 'noindex, nofollow');
        $this->applyPageTitle('activation', Text::_('COM_APPOINTMENTS_TEAM_D_ACTIVATION_TITLE'));

        return parent::display($tpl);
    }

    /**
     * 32 random bytes in base64url without padding, as the activation link carries them.
     */
    public static function isToken(string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) === 1;
    }
}
