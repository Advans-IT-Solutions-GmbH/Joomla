<?php
/**
 * @package     Appointments User Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\User\Appointments\Extension;

use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Event\User\AfterDeleteEvent;
use Joomla\CMS\Event\User\AfterSaveEvent;
use Joomla\CMS\Event\User\AuthorisationEvent;
use Joomla\CMS\Event\User\LoginEvent;
use Joomla\CMS\Event\User\LoginFailureEvent;
use Joomla\CMS\Event\User\LogoutEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\UserHelper;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

/**
 * Connects Joomla's account events to the team calendars. It only delegates:
 * the key handling to the key session (work package B), the lock-out, the log
 * and the clean-up to work package F. Work package B owns this file.
 *
 * Every handler does nothing while the team calendars are switched off, and
 * no failure of the team calendars may break a sign-in, a sign-out or a save
 * of an account: errors are logged without detail and swallowed. The one
 * deliberate exception is the lock-out in onUserAuthorisation, which refuses a
 * sign-in on purpose.
 *
 * @since  1.4.0
 */
final class Appointments extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onUserAuthorisation' => 'onUserAuthorisation',
            'onUserLogin'        => 'onUserLogin',
            'onUserLoginFailure' => 'onUserLoginFailure',
            'onUserLogout'       => 'onUserLogout',
            'onUserAfterSave'    => 'onUserAfterSave',
            'onUserAfterDelete'  => 'onUserAfterDelete',
        ];
    }

    public function onUserLogin(LoginEvent $event): void
    {
        $services = $this->services();

        if ($services === null) {
            return;
        }

        $response = $event->getAuthenticationResponse();
        $username = (string) ($response['username'] ?? '');
        $ip = $this->clientAddress();

        $this->guard(static function () use ($services, $response, $username, $ip): void {
            $userId = (int) UserHelper::getUserId($username);

            if ($userId <= 0 || $services->grants()->audienceOf($userId) === 'none') {
                return;
            }

            $services->lockout()->recordSuccess($username, $ip);
            $services->audit()->log(AuditEvent::LOGIN_SUCCESS, ['actor_user_id' => $userId, 'ip' => $ip]);
            $services->keySession()->onLogin($userId, (string) ($response['password'] ?? ''));
        });
    }

    /**
     * The lock-out. Joomla asks the user plugins whether an authenticated
     * account may sign in before it starts the session; a denied response here
     * stops the sign-in, even with the correct password.
     */
    public function onUserAuthorisation(AuthorisationEvent $event): void
    {
        $services = $this->services();

        if ($services === null) {
            return;
        }

        $response = $event->getAuthenticationResponse();
        $username = (string) $response->username;
        $ip = $this->clientAddress();

        $this->guard(static function () use ($services, $event, $response, $username, $ip): void {
            $userId = (int) UserHelper::getUserId($username);

            if ($userId <= 0 || $services->grants()->audienceOf($userId) === 'none'
                || $services->lockout()->retryAfter($username, $ip) === 0) {
                return;
            }

            $services->audit()->log(AuditEvent::LOGIN_LOCKED, ['subject_user_id' => $userId, 'ip' => $ip]);
            $response->status = Authentication::STATUS_DENIED;
            $response->error_message = Text::_('PLG_USER_APPOINTMENTS_LOGIN_LOCKED');
            $event->addResult($response);
        });
    }

    public function onUserLoginFailure(LoginFailureEvent $event): void
    {
        $services = $this->services();

        if ($services === null) {
            return;
        }

        $username = (string) ($event->getAuthenticationResponse()['username'] ?? '');
        $ip = $this->clientAddress();

        $this->guard(static function () use ($services, $username, $ip): void {
            $userId = (int) UserHelper::getUserId($username);

            // Only accounts with a calendar right are counted and logged here;
            // the site's other accounts are outside the team calendars.
            if ($userId > 0 && $services->grants()->audienceOf($userId) !== 'none') {
                $services->lockout()->recordFailure($username, $ip);
                $services->audit()->log(AuditEvent::LOGIN_FAILURE, ['subject_user_id' => $userId, 'ip' => $ip]);
            }
        });
    }

    public function onUserLogout(LogoutEvent $event): void
    {
        $services = $this->services();
        $userId = (int) ($event->getParameters()['id'] ?? 0);

        if ($services === null || $userId <= 0) {
            return;
        }

        $this->guard(static function () use ($services, $userId): void {
            $services->keySession()->onLogout($userId);
        });
    }

    public function onUserAfterSave(AfterSaveEvent $event): void
    {
        $services = $this->services();
        $user = $event->getUser();
        $userId = (int) ($user['id'] ?? 0);

        if ($services === null || $userId <= 0 || $event->getIsNew() || !$event->getSavingResult()) {
            return;
        }

        $this->guard(static function () use ($services, $user, $userId): void {
            if ((int) ($user['block'] ?? 0) === 1) {
                $services->cleanup()->onUserBlocked($userId, null);

                return;
            }

            $password = (string) ($user['password_clear'] ?? '');

            if ($password !== '') {
                $services->keySession()->onPasswordChanged($userId, $password);
            }
        });
    }

    public function onUserAfterDelete(AfterDeleteEvent $event): void
    {
        $services = $this->services();
        $userId = (int) ($event->getUser()['id'] ?? 0);

        if ($services === null || $userId <= 0 || !$event->getDeletingResult()) {
            return;
        }

        $this->guard(static function () use ($services, $userId): void {
            $services->cleanup()->onUserDeleted($userId);
        });
    }

    private function services(): ?TeamServices
    {
        try {
            $services = TeamServices::fromApplication();

            return $services->enabled() ? $services : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function guard(callable $operation): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            // No message text: it could name a user, a key state or a path.
            Log::add('Team calendars: an account event could not be processed (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
        }
    }

    private function clientAddress(): string
    {
        return (string) $this->getApplication()->getInput()->server->getString('REMOTE_ADDR', '');
    }
}
