<?php
/**
 * @package     Appointments Component
 * @subpackage  Site
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Controller;

use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Advans\Component\Appointments\Site\View\Activation\HtmlView;
use Advans\Component\Appointments\Site\View\Mycalendars\HtmlView as MycalendarsView;
use Joomla\CMS\Form\Rule\PasswordRule;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

defined('_JEXEC') or die;

/**
 * Account activation: set the password of an account an administrator created,
 * with the one-time link. POST only, with the form token, signed out. The
 * password is checked against Joomla's password rules (Users → Options), then
 * the invitation service sets it and closes the link. The token comes from the
 * session exchange of the activation page, never from the form. Afterwards the
 * browser goes to the sign-in page, and Joomla asks for the second factor there.
 *
 * @since  1.4.0
 */
class ActivationController extends BaseController
{
    protected $default_view = 'activation';

    public function complete(): bool
    {
        if ($this->input->getMethod() !== 'POST' || !Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        MycalendarsView::privateHeaders();
        $services = TeamServices::fromApplication();
        $exchange = HtmlView::exchange();

        // The form carries the value of this session's exchange, never the token itself.
        if (!$services->enabled() || $exchange === null || !hash_equals($exchange['nonce'], $this->input->post->getAlnum('nonce', ''))) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $token = $exchange['token'];

        if (!$this->app->getIdentity()->guest) {
            return $this->back(['password1' => 'COM_APPOINTMENTS_TEAM_D_ACTIVATION_SIGNED_IN']);
        }

        // Raw: a password keeps every character the person typed.
        $password = (string) $this->input->post->get('password1', '', 'raw');
        $repeat = (string) $this->input->post->get('password2', '', 'raw');

        if ($password === '' || \strlen($password) > 4096) {
            return $this->back(['password1' => 'COM_APPOINTMENTS_TEAM_D_ACTIVATION_ERROR_PASSWORD']);
        }

        if (!hash_equals($password, $repeat)) {
            return $this->back(['password2' => 'COM_APPOINTMENTS_TEAM_D_ACTIVATION_ERROR_REPEAT']);
        }

        // Joomla's own rules from the user options; the rule explains itself in a message.
        $rule = new \SimpleXMLElement('<field name="password1" type="password" validate="password" required="true" />');

        if ((new PasswordRule())->test($rule, $password) !== true) {
            return $this->back(['password1' => 'COM_APPOINTMENTS_TEAM_D_ACTIVATION_ERROR_PASSWORD']);
        }

        try {
            $services->invitations()->complete($token, $password);
        } catch (TeamException $e) {
            if ($e->getCode() === 400) {
                return $this->back(['password1' => 'COM_APPOINTMENTS_TEAM_D_ACTIVATION_ERROR_PASSWORD']);
            }

            // Unknown, used, expired: one answer for all.
            $this->app->getSession()->set(HtmlView::EXCHANGE, null);

            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $this->app->getSession()->set(HtmlView::EXCHANGE, null);
        $this->setRedirect(Route::_('index.php?option=com_users&view=login', false), Text::_('COM_APPOINTMENTS_TEAM_D_ACTIVATION_DONE'), 'success');

        return true;
    }

    /**
     * Back to the activation page with the messages of the refused submission.
     *
     * @param   array<string, string>  $errors
     */
    private function back(array $errors): bool
    {
        $this->app->setUserState(HtmlView::FORM_STATE, $errors);
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=activation', false));

        return false;
    }
}
