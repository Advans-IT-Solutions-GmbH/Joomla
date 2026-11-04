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
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\DeviceService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Advans\Component\Appointments\Site\View\Mycalendars\HtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

defined('_JEXEC') or die;

/**
 * Internet calendars, «My calendars»: add a device, rename it, remove it.
 *
 * Every task takes POST only with the form token (403 otherwise) and a
 * signed-in user with the feature on (404 otherwise). «Add device»
 * additionally needs a session that passed the second factor with the key
 * unlocked (423 otherwise), the one-time form value, the confirmed usage rule
 * and an effective grant; its response renders the app password directly,
 * because a redirect would have to keep it in the session.
 *
 * @since  1.4.0
 */
class DevicesController extends BaseController
{
    protected $default_view = 'mycalendars';

    public function create(): bool
    {
        $services = $this->guard();
        $user = $this->app->getIdentity();
        $session = $this->app->getSession();
        $post = $this->input->post;
        $name = $post->getString('name', '');
        $client = $post->getCmd('client', '');
        $nonce = $post->getAlnum('form_nonce', '');
        $expected = (string) $session->get(HtmlView::FORM_NONCE, '');

        // Spent before anything else: a second submission of the same form never gets past here.
        $session->set(HtmlView::FORM_NONCE, null);

        if ($expected === '' || !hash_equals($expected, $nonce)) {
            return $this->back('COM_APPOINTMENTS_TEAM_D_ERROR_RESUBMIT');
        }

        $errors = [];

        if (DeviceService::normaliseName($name) === null) {
            $errors['name'] = 'COM_APPOINTMENTS_TEAM_D_ERROR_NAME';
        }

        if (!DeviceService::isClient($client)) {
            $errors['client'] = 'COM_APPOINTMENTS_TEAM_D_ERROR_CLIENT';
        }

        if ($post->getInt('rule', 0) !== 1) {
            $errors['rule'] = 'COM_APPOINTMENTS_TEAM_D_ERROR_RULE';
        } elseif ($post->getString('rule_version', '') !== TeamStates::DEVICE_RULE_VERSION) {
            // The rule changed after the form was shown: the confirmation is for the text the person read.
            $errors['rule'] = 'COM_APPOINTMENTS_TEAM_D_ERROR_RULE_CHANGED';
        }

        if ($errors !== []) {
            return $this->back('', ['name' => $name, 'client' => $client, 'errors' => $errors]);
        }

        // Joomla's own record that this session passed the second factor; the key
        // session checks it too, and the device service checks that a factor is set up.
        if ((int) $session->get('com_users.mfa_checked', 0) !== 1) {
            return $this->locked();
        }

        // The lock-out of failed sign-ins also stops «Add device» (fail closed, LockoutServiceInterface):
        // a locked account gets no new device, and a check that cannot be made adds none either.
        try {
            if ($services->lockout()->retryAfter((string) $user->username, (string) $this->input->server->getString('REMOTE_ADDR', '')) > 0) {
                return $this->back('COM_APPOINTMENTS_TEAM_D_ERROR_LOCKED');
            }
        } catch (\Throwable $e) {
            return $this->failed($e);
        }

        try {
            $keys = $services->keySession()->requireUnlocked();
        } catch (TeamException $e) {
            return $e->getCode() === 423 ? $this->locked() : $this->back('COM_APPOINTMENTS_MYCALENDARS_NOT_READY');
        } catch (\Throwable $e) {
            return $this->failed($e);
        }

        if ($keys->userId() !== (int) $user->id) {
            return $this->locked();
        }

        try {
            $result = $services->devices()->create($keys, $name, $client, true);
        } catch (TeamException $e) {
            $key = $this->known($e->getMessage());

            if ($key === 'COM_APPOINTMENTS_TEAM_D_ERROR_NAME_TAKEN' || $key === 'COM_APPOINTMENTS_TEAM_D_ERROR_NAME') {
                return $this->back('', ['name' => $name, 'client' => $client, 'errors' => ['name' => $key]]);
            }

            return $this->back($key, ['name' => $name, 'client' => $client]);
        } catch (\Throwable $e) {
            return $this->failed($e);
        }

        $view = $this->getView('Mycalendars', 'html', 'Site');

        if (method_exists($view, 'setDocument')) {
            $view->setDocument($this->app->getDocument());
        }

        $view->setCreated($result, (string) DeviceService::normaliseName($name), $client);
        $view->display();

        return true;
    }

    public function rename(): bool
    {
        $services = $this->guard();
        $id = $this->input->post->getInt('id', 0);
        $name = $this->input->post->getString('name', '');

        try {
            $services->devices()->rename((int) $this->app->getIdentity()->id, $id, $name);
        } catch (TeamException $e) {
            if ($e->getCode() === 404) {
                throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
            }

            return $this->back('', ['name' => $name, 'device' => $id, 'errors' => ['name' => $this->known($e->getMessage())]], $id);
        }

        $this->setRedirect(Route::_('index.php?option=com_appointments&view=mycalendars', false), Text::_('COM_APPOINTMENTS_TEAM_D_RENAMED'));

        return true;
    }

    public function remove(): bool
    {
        $services = $this->guard();
        $userId = (int) $this->app->getIdentity()->id;
        $id = $this->input->post->getInt('id', 0);
        $device = null;

        foreach ($services->devices()->listForUser($userId) as $candidate) {
            if ($candidate->id === $id) {
                $device = $candidate;
            }
        }

        if ($device === null) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        if ($device->state !== TeamStates::DEVICE_ACTIVE) {
            return $this->back('COM_APPOINTMENTS_TEAM_D_ALREADY_REMOVING');
        }

        if ($this->input->post->getInt('confirm', 0) !== 1) {
            return $this->back('', ['device' => $id, 'errors' => ['confirm' => 'COM_APPOINTMENTS_TEAM_D_ERROR_CONFIRM']], $id);
        }

        try {
            // The device receives its calendars empty, then not at all, then 401 (clean-up).
            $services->cleanup()->startForDevice($id, 'user_removed', $userId);
        } catch (TeamException $e) {
            return $this->back($this->known($e->getMessage()));
        }

        $this->setRedirect(Route::_('index.php?option=com_appointments&view=mycalendars', false), Text::_('COM_APPOINTMENTS_TEAM_D_REMOVED'));

        return true;
    }

    /**
     * Fail closed: a security check that could not be made (lock-out, second factor, key
     * session, database) creates no device. The user sees a general message, the log only
     * the class of the error, never its message, which may carry data.
     */
    private function failed(\Throwable $e): bool
    {
        Log::add('Internet calendar device not added: ' . \get_class($e), Log::ERROR, 'com_appointments');

        return $this->back('COM_APPOINTMENTS_TEAM_D_ERROR_GENERAL');
    }

    /**
     * 423 Locked: the session holds no unlocked key (sign-in without password, or
     * the key cookie is gone). The page is shown again in this response, with the
     * request to sign in with the password and the second factor.
     */
    private function locked(): bool
    {
        $this->app->setHeader('status', 423, true);
        $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_D_ADD_NEEDS_PASSWORD'), 'warning');
        $view = $this->getView('Mycalendars', 'html', 'Site');

        if (method_exists($view, 'setDocument')) {
            $view->setDocument($this->app->getDocument());
        }

        $view->setLayout('default');
        $view->display();

        return false;
    }

    /**
     * POST with the form token, a signed-in user, the feature on.
     */
    private function guard(): TeamServices
    {
        // Checked here, not with Session::checkToken(): that one redirects a new session instead of refusing.
        if ($this->input->getMethod() !== 'POST' || $this->input->post->get(Session::getFormToken(), '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $services = TeamServices::fromApplication();
        $user = $this->app->getIdentity();

        if ($user === null || $user->guest || !$services->enabled()) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        HtmlView::privateHeaders();

        return $services;
    }

    /**
     * Back to «My calendars» (or the device page) with a message and the field
     * values of the refused form. Never the app password: it exists only after success.
     *
     * @param   array<string, mixed>  $form
     */
    private function back(string $message, array $form = [], int $deviceId = 0): bool
    {
        $this->app->setUserState(HtmlView::FORM_STATE, $form === [] ? null : $form);
        $url = 'index.php?option=com_appointments&view=mycalendars' . ($deviceId > 0 ? '&layout=device&id=' . $deviceId : '');
        $this->setRedirect(Route::_($url, false), $message !== '' ? Text::_($message) : null, 'error');

        return false;
    }

    /**
     * A refusal of the services as one of the texts of this page; anything else as a general text.
     */
    private function known(string $key): string
    {
        return preg_match('/\ACOM_APPOINTMENTS_TEAM_D_(?:ERROR|ADD)_[A-Z_]+\z/', $key) === 1 || $key === 'COM_APPOINTMENTS_MYCALENDARS_NOT_READY'
            ? $key
            : ($key === 'COM_APPOINTMENTS_TEAM_NOT_READY' ? 'COM_APPOINTMENTS_MYCALENDARS_NOT_READY' : 'COM_APPOINTMENTS_TEAM_D_ERROR_GENERAL');
    }
}
