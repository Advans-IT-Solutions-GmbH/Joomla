<?php
/**
 * @package     Appointments User Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\User\Appointments\Extension;

use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Service\Team\KeySession;
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
 * Connects Joomla's account events to the internet calendars. It only
 * delegates: the key handling to the key session (work package B), the
 * lock-out, the log and the clean-up to work package F. Work package B owns
 * this file.
 *
 * Event names and getters are the same in Joomla 5.4 and 6:
 * `LoginEvent::getAuthenticationResponse()` (array; `password` holds what was
 * typed, `type` the authentication plugin), `AuthorisationEvent::getAuthenticationResponse()`
 * (object), `AfterSaveEvent::getUser()['password_clear']` (empty unless a new
 * password was set), `LogoutEvent::getParameters()['id']`.
 *
 * Every handler does nothing while the internet calendars are switched off,
 * and no failure here may break a sign-in, a sign-out or a save of an account:
 * every delegation is guarded on its own, errors are logged without detail.
 * The one deliberate refusal is the lock-out in onUserAuthorisation, the second
 * safeguard behind the lock-out check of the system plugin.
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
        $userId = $this->userIdOf($username);

        if ($userId <= 0) {
            return;
        }

        $ip = $this->clientAddress();

        if ($this->isCalendarUser($services, $userId)) {
            $this->guard(static fn () => $services->lockout()->recordSuccess($username, $ip));
            $this->guard(static fn () => $services->audit()->log(AuditEvent::LOGIN_SUCCESS, ['actor_user_id' => $userId, 'ip' => $ip]));
        }

        // A sign-in without a password (passkey, "remember me": its response
        // carries the stored hash instead) passes an empty password, which
        // clears any key of the session. The key session checks the password
        // against the account's hash once more and decides on eligibility.
        $silent = \in_array(strtolower((string) ($response['type'] ?? '')), KeySession::silentResponseTypes(), true);
        $password = $silent ? '' : (string) ($response['password'] ?? '');

        $this->guard(static fn () => $services->keySession()->onLogin($userId, $password));
    }

    /**
     * The second safeguard of the lock-out. Joomla asks the user plugins
     * whether an authenticated account may sign in before it starts the
     * session; a denied response here stops the sign-in, even with the correct
     * password. The error text is the one of a wrong password, but Joomla 5.4
     * and 6 add their own «login denied» message for a denied authorisation
     * (CMSApplication::login), so only the first safeguard is free of an oracle.
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

        try {
            $userId = $this->userIdOf($username);

            if ($userId <= 0 || !$this->isCalendarUser($services, $userId)
                || $services->lockout()->retryAfter($username, $ip) === 0) {
                return;
            }
        } catch (\Throwable $e) {
            $this->failed($e);

            return;
        }

        $this->guard(static fn () => $services->audit()->log(AuditEvent::LOGIN_LOCKED, ['subject_user_id' => $userId, 'ip' => $ip]));
        $response->status = Authentication::STATUS_DENIED;
        $response->error_message = Text::_('JGLOBAL_AUTH_INVALID_PASS');
        $event->addResult($response);
    }

    public function onUserLoginFailure(LoginFailureEvent $event): void
    {
        $services = $this->services();

        if ($services === null) {
            return;
        }

        $username = (string) ($event->getAuthenticationResponse()['username'] ?? '');
        $userId = $this->userIdOf($username);
        $ip = $this->clientAddress();

        // Only accounts with a calendar right are counted and logged here; the
        // site's other accounts are outside the internet calendars.
        if ($userId > 0 && $this->isCalendarUser($services, $userId)) {
            // A sign-in the lock-out refused before the password check is no
            // failure of its own: F counts nothing for it, and nothing is logged
            // here (`login.locked` is logged by the lock-out). Asked before
            // recordFailure(), which clears the mark.
            $refused = false;

            $this->guard(static function () use ($services, $username, $ip, &$refused): void {
                $refused = $services->lockout()->wasRefused($username, $ip);
            });
            $this->guard(static fn () => $services->lockout()->recordFailure($username, $ip));

            if (!$refused) {
                $this->guard(static fn () => $services->audit()->log(AuditEvent::LOGIN_FAILURE, ['subject_user_id' => $userId, 'ip' => $ip]));
            }
        }
    }

    public function onUserLogout(LogoutEvent $event): void
    {
        $services = $this->services();
        $userId = (int) ($event->getParameters()['id'] ?? 0);

        if ($services === null || $userId <= 0) {
            return;
        }

        $this->guard(static fn () => $services->keySession()->onLogout($userId));
    }

    public function onUserAfterSave(AfterSaveEvent $event): void
    {
        $services = $this->services();
        $user = $event->getUser();
        $userId = (int) ($user['id'] ?? 0);

        if ($services === null || $userId <= 0 || $event->getIsNew() || !$event->getSavingResult()) {
            return;
        }

        // A new password first: a reset suspends the grants and starts the
        // clean-up, a block below does the same again, idempotently.
        $password = (string) ($user['password_clear'] ?? '');

        if ($password !== '') {
            $this->guard(static fn () => $services->keySession()->onPasswordChanged($userId, $password));
        }

        if ((int) ($user['block'] ?? 0) === 1) {
            $this->guard(static fn () => $services->cleanup()->onUserBlocked($userId, null));
        }
    }

    public function onUserAfterDelete(AfterDeleteEvent $event): void
    {
        $services = $this->services();
        $userId = (int) ($event->getUser()['id'] ?? 0);

        if ($services === null || $userId <= 0 || !$event->getDeletingResult()) {
            return;
        }

        $this->guard(static fn () => $services->cleanup()->onUserDeleted($userId));
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

    /**
     * The account id of a user name, 0 when unknown; a database error is
     * logged and never interrupts the sign-in.
     */
    private function userIdOf(string $username): int
    {
        if ($username === '') {
            return 0;
        }

        try {
            return (int) UserHelper::getUserId($username);
        } catch (\Throwable $e) {
            $this->failed($e);

            return 0;
        }
    }
    private function isCalendarUser(TeamServices $services, int $userId): bool
    {
        try {
            return $services->grants()->audienceOf($userId) !== 'none';
        } catch (\Throwable $e) {
            $this->failed($e);

            return false;
        }
    }

    private function guard(callable $operation): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->failed($e);
        }
    }

    private function failed(\Throwable $e): void
    {
        // No message text: it could name a user, a key state or a path.
        try {
            Log::add('Internet calendars: an account event could not be processed (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
        } catch (\Throwable $ignored) {
        }
    }

    private function clientAddress(): string
    {
        return (string) $this->getApplication()->getInput()->server->getString('REMOTE_ADDR', '');
    }
}
