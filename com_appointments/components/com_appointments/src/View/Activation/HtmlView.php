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
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/**
 * Account activation of the internet calendars: an administrator created the
 * account and sent a one-time link; this page shows the user name and the
 * masked address and sets the password, nothing else. Joomla enforces the
 * second factor at the first sign-in.
 *
 * The first request carries the token from the mail. It is checked, kept in
 * this browser session for a short time together with a random form value,
 * and the browser is sent on to the same page without the token, so the token
 * stays out of the address bar, the history and any later request. The form
 * carries only the form value. An unknown, used or expired link, a disabled
 * feature, a malformed token and an expired exchange all answer 404 alike.
 *
 * @since  1.4.0
 */
class HtmlView extends BaseHtmlView
{
    use PageTitleTrait;

    /** User state of the last refused submission: messages only, never a password. */
    public const FORM_STATE = 'com_appointments.team.activation_errors';

    /** Session key of the exchanged token: token, form value, expiry. */
    public const EXCHANGE = 'com_appointments.team.activation';

    /** Seconds the exchanged token stays usable in the session. */
    public const EXCHANGE_SECONDS = 900;

    /** @var array{username: string, email_masked: string, expires_utc: string}|null */
    protected $account;

    protected $nonce = '';

    protected $signedIn = false;

    /** @var array<string, string> */
    protected $errors = [];

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        MycalendarsView::privateHeaders();
        $services = TeamServices::fromApplication();

        if (!$services->enabled()) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $token = (string) $app->getInput()->get->getString('token', '');

        if ($token !== '') {
            if (!self::isToken($token) || self::inspect($services, $token) === null) {
                throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
            }

            $app->getSession()->set(self::EXCHANGE, [
                'token' => $token,
                'nonce' => bin2hex(random_bytes(16)),
                'expires' => time() + self::EXCHANGE_SECONDS,
            ]);
            $app->redirect(Route::_('index.php?option=com_appointments&view=activation', false), 303);
        }

        $exchange = self::exchange();
        $this->account = $exchange !== null ? self::inspect($services, $exchange['token']) : null;

        if ($this->account === null) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $this->nonce = $exchange['nonce'];
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

    /**
     * The exchanged token of this session while it is valid, null otherwise (an
     * expired exchange is removed).
     *
     * @return  array{token: string, nonce: string, expires: int}|null
     */
    public static function exchange(): ?array
    {
        $session = Factory::getApplication()->getSession();
        $exchange = $session->get(self::EXCHANGE);

        if (!\is_array($exchange) || !self::isToken((string) ($exchange['token'] ?? ''))
            || preg_match('/\A[0-9a-f]{32}\z/', (string) ($exchange['nonce'] ?? '')) !== 1
            || (int) ($exchange['expires'] ?? 0) < time()
        ) {
            $session->set(self::EXCHANGE, null);

            return null;
        }

        return ['token' => (string) $exchange['token'], 'nonce' => (string) $exchange['nonce'], 'expires' => (int) $exchange['expires']];
    }

    /**
     * @return  array{username: string, email_masked: string, expires_utc: string}|null
     */
    private static function inspect(TeamServices $services, string $token): ?array
    {
        try {
            return $services->invitations()->inspect($token);
        } catch (TeamException $e) {
            return null;
        }
    }
}
