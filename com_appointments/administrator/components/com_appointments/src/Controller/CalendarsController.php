<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/**
 * Internet calendars in the administrator: create, edit, delete, grants, key
 * rotation, recovery key creation, rotation and use.
 *
 * Every task: `core.manage` on the component, the feature switched on, and for
 * every change a POST with the form token. Tasks on one calendar need its edit
 * id held (taken by the edit task), so a forged form for another calendar is
 * refused before the services decide. The services then check the actual
 * right (manager, `core.create`, `core.delete`, `appointments.team.external`,
 * `core.admin`) on every call; nothing here grants by itself.
 *
 * @since  1.4.0
 */
class CalendarsController extends BaseController
{
    private const CONTEXT = 'com_appointments.edit.calendar';

    /**
     * No views through this controller: they go through DisplayController and
     * its edit-view checks only.
     */
    public function display($cachable = false, $urlparams = [])
    {
        throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
    }

    public function add(): bool
    {
        $this->guard(false);

        if (!$this->app->getIdentity()->authorise('core.create', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->app->setUserState(self::CONTEXT . '.data', null);
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=calendar&layout=edit', false));

        return true;
    }

    public function edit(): bool
    {
        $services = $this->guard(false);
        $id = $this->input->getInt('id', 0);

        if (!$this->app->getIdentity()->authorise('core.edit', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if ($id <= 0 || $services->calendars()->get($id) === null) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $this->holdEditId(self::CONTEXT, $id);
        $this->app->setUserState(self::CONTEXT . '.data', null);
        $this->setRedirect($this->editUrl($id));

        return true;
    }

    public function apply(): bool
    {
        return $this->store(false);
    }

    public function save(): bool
    {
        return $this->store(true);
    }

    public function cancel(): bool
    {
        // A held edit must also close after the feature has been switched off.
        $this->guard(true, false);
        $id = $this->input->getInt('id', 0);

        if ($id > 0) {
            $this->releaseEditId(self::CONTEXT, $id);
        }

        $this->app->setUserState(self::CONTEXT . '.data', null);
        $this->setRedirect($this->listUrl());

        return true;
    }

    public function delete(): bool
    {
        $services = $this->guard(true);
        $id = $this->heldId();

        if ($this->input->post->getInt('confirm', 0) !== 1) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_C_DELETE_CONFIRM_MISSING'), 'warning');
            $this->setRedirect($this->editUrl($id));

            return false;
        }

        return $this->attempt(function () use ($services, $id): void {
            $services->calendars()->delete($this->actor(), $id);
            $this->releaseEditId(self::CONTEXT, $id);
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_C_DELETED'), 'message');
            $this->setRedirect($this->listUrl());
        }, $this->editUrl($id));
    }

    /**
     * Delete a calendar whose row no longer verifies (for example after the
     * site secret changed): core.admin and core.delete, confirmed. Such a
     * calendar cannot be edited, so it has no edit id; this path accepts only
     * calendars that fail their check, every other deletion goes through the
     * edit page.
     */
    public function deleteInvalid(): bool
    {
        $services = $this->guard(true);
        $this->requireAdmin();
        $id = $this->input->post->getInt('id', 0);

        if (!$this->app->getIdentity()->authorise('core.delete', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if ($this->input->post->getInt('confirm', 0) !== 1 || $id <= 0) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_C_DELETE_CONFIRM_MISSING'), 'warning');
            $this->setRedirect($this->listUrl());

            return false;
        }

        return $this->attempt(function () use ($services, $id): void {
            if ($services->calendars()->get($id) !== null) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
            }

            $services->calendars()->delete($this->actor(), $id);
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_C_DELETED'), 'message');
            $this->setRedirect($this->listUrl());
        }, $this->listUrl());
    }

    public function grant(): bool
    {
        $services = $this->guard(true);
        $id = $this->heldId();
        $grantee = $this->input->post->getInt('user_id', 0);
        $role = CalendarRole::tryFrom($this->input->post->getInt('role', 0));

        if ($role === null || $grantee <= 0) {
            throw new \RuntimeException(Text::_('COM_APPOINTMENTS_TEAM_C_ERROR_INVALID'), 400);
        }

        return $this->attempt(function () use ($services, $id, $grantee, $role): void {
            // Granting needs a session that signed in with password and second
            // factor (spec 5.3 and 6): a remember-me or passkey session cannot grant.
            $keys = $services->keySession()->requireUnlocked();
            $services->grants()->grant($this->actor(), $id, $grantee, $role, $keys);
            $pending = $role->holdsKey() && $services->grants()->accessFor($grantee, $id) === null;
            $this->app->enqueueMessage(Text::_($pending ? 'COM_APPOINTMENTS_TEAM_C_GRANT_SAVED_PENDING' : 'COM_APPOINTMENTS_TEAM_C_GRANT_SAVED'), 'message');
            $this->setRedirect($this->editUrl($id));
        }, $this->editUrl($id));
    }

    public function revoke(): bool
    {
        $services = $this->guard(true);
        $id = $this->heldId();
        $grantee = $this->input->post->getInt('user_id', 0);

        return $this->attempt(function () use ($services, $id, $grantee): void {
            $services->grants()->revoke($this->actor(), $id, $grantee, $this->sessionKeys($services));
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_REMOVED'), 'message');
            $this->setRedirect($this->editUrl($id));
        }, $this->editUrl($id));
    }

    public function assignOwner(): bool
    {
        $services = $this->guard(true);
        $this->requireAdmin();
        $id = $this->heldId();
        $grantee = $this->input->post->getInt('user_id', 0);

        return $this->attempt(function () use ($services, $id, $grantee): void {
            $services->grants()->grant($this->actor(), $id, $grantee, CalendarRole::Manage, $services->keySession()->requireUnlocked(), true);
            $pending = $services->grants()->accessFor($grantee, $id) === null;
            $this->app->enqueueMessage(Text::_($pending ? 'COM_APPOINTMENTS_TEAM_C_GRANT_SAVED_PENDING' : 'COM_APPOINTMENTS_TEAM_C_GRANT_SAVED'), 'message');
            $this->setRedirect($this->editUrl($id));
        }, $this->editUrl($id));
    }

    public function rotate(): bool
    {
        $services = $this->guard(true);
        $id = $this->heldId();

        return $this->attempt(function () use ($services, $id): void {
            if ($services->grants()->accessFor($this->actor(), $id)?->role !== CalendarRole::Manage) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
            }

            $keyring = $services->keys()->openKeyring($id, $services->keySession()->requireUnlocked());

            if ($keyring === null) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
            }

            try {
                $services->keys()->rotateCalendarKey($keyring)->wipe();
            } finally {
                $keyring->wipe();
            }

            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_C_ROTATED'), 'message');
            $this->setRedirect($this->editUrl($id));
        }, $this->editUrl($id));
    }

    /**
     * Create the site recovery key. The secret is rendered once into this very
     * response and is neither stored, nor put in the session, nor redirected.
     */
    public function recoveryCreate(): bool
    {
        $services = $this->guard(true);
        $this->requireAdmin();

        return $this->attempt(function () use ($services): void {
            $services->keySession()->requireUnlocked();
            $this->showSecret($services->keys()->createRecoveryKey($this->actor()));
        }, $this->listUrl(), true);
    }

    public function recoveryRotate(): bool
    {
        // Taken out of the request data before anything can fail.
        $old = $this->secretFromPost('recovery_secret');
        $services = $this->guard(true);
        $this->requireAdmin();

        return $this->attempt(function () use ($services, &$old): void {
            $services->keySession()->requireUnlocked();

            try {
                $new = $services->keys()->rotateRecoveryKey($old, $this->actor());
            } finally {
                $this->wipe($old);
            }

            $this->showSecret($new);
        }, $this->listUrl(), true);
    }

    /**
     * Use the recovery key: grant a calendar to a user, and optionally make that
     * user the owner of a calendar without one.
     */
    public function recoveryUse(): bool
    {
        $secret = $this->secretFromPost('recovery_secret');
        $services = $this->guard(true);
        $this->requireAdmin();
        $id = $this->input->post->getInt('calendar_id', 0);
        $grantee = $this->input->post->getInt('user_id', 0);
        $role = CalendarRole::tryFrom($this->input->post->getInt('role', 0));
        $owner = $this->input->post->getInt('make_owner', 0) === 1;

        if ($role === null || $id <= 0 || $grantee <= 0) {
            $this->wipe($secret);

            throw new \RuntimeException(Text::_('COM_APPOINTMENTS_TEAM_C_ERROR_INVALID'), 400);
        }

        return $this->attempt(function () use ($services, &$secret, $id, $grantee, $role, $owner): void {
            try {
                // Like creating and replacing the recovery key: only in a session
                // that signed in with password and second factor (spec 5.3), so
                // a remember-me or passkey session cannot use it.
                $services->keySession()->requireUnlocked();
                $keyring = $services->keys()->recoverKeyring($id, $secret);
            } finally {
                $this->wipe($secret);
            }

            if ($keyring === null || $keyring->calendarId() !== $id) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_RECOVERY_SECRET', 403);
            }

            try {
                $services->grants()->grantWithRecovery($this->actor(), $keyring, $grantee, $role, $owner);
            } finally {
                $keyring->wipe();
            }

            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_USED'), 'message');
            $this->setRedirect($this->listUrl());
        }, $this->listUrl(), true);
    }

    /**
     * Discard the key row of an account that no longer verifies (core.admin,
     * confirmed). The key service refuses a row that still verifies, so this is
     * never a way around a password reset.
     */
    public function discardKey(): bool
    {
        $services = $this->guard(true);
        $this->requireAdmin();
        $userId = $this->input->post->getInt('user_id', 0);

        if ($this->input->post->getInt('confirm', 0) !== 1 || $userId <= 0) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_C_KEY_DISCARD_CONFIRM_MISSING'), 'warning');
            $this->setRedirect($this->listUrl());

            return false;
        }

        return $this->attempt(function () use ($services, $userId): void {
            // Destructive (grants suspended, devices cleaned up): only an
            // administrator with a second factor, in a session that signed in
            // with password and second factor.
            if (!$services->grants()->hasSecondFactor($this->actor())) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
            }

            $services->keySession()->requireUnlocked();

            $services->keys()->discardUserKey($this->actor(), $userId);
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_C_KEY_DISCARDED'), 'message');
            $this->setRedirect($this->listUrl());
        }, $this->listUrl());
    }

    // ------------------------------------------------------------------

    private function store(bool $close): bool
    {
        $services = $this->guard(true);
        $id = $this->input->getInt('id', 0);
        $data = $this->input->post->get('jform', [], 'array');
        $data = \is_array($data) ? $data : [];
        $allowed = ['title', 'description', 'colour', 'allow_external', 'blocks_availability', 'retention_days'];

        if ($id > 0 && !$this->checkEditId(self::CONTEXT, $id)) {
            throw new \RuntimeException(Text::sprintf('JLIB_APPLICATION_ERROR_UNHELD_ID', $id), 403);
        }

        // The permission is checked again at save time: an edit id held from
        // before a permission change must not keep the right to save.
        if (!$this->app->getIdentity()->authorise($id > 0 ? 'core.edit' : 'core.create', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if ($id === 0) {
            $allowed[] = 'owner_user_id';
        }

        // Only the editable fields pass on; everything else in the request is ignored.
        $data = array_intersect_key($data, array_flip($allowed));
        $this->app->setUserState(self::CONTEXT . '.data', $data);
        $back = $id > 0 ? $this->editUrl($id) : Route::_('index.php?option=com_appointments&view=calendar&layout=edit', false);

        return $this->attempt(function () use ($services, $id, $data, $close): void {
            if ($id > 0) {
                $services->calendars()->update($this->actor(), $id, $data);
                $saved = $id;
            } else {
                $saved = $services->calendars()->create($this->actor(), $data);
                $this->holdEditId(self::CONTEXT, $saved);
            }

            $this->app->setUserState(self::CONTEXT . '.data', null);
            $this->app->enqueueMessage(Text::_($id > 0 ? 'COM_APPOINTMENTS_TEAM_C_SAVED' : 'COM_APPOINTMENTS_TEAM_C_CREATED'), 'message');

            // Without core.edit the new calendar's edit page is closed to this
            // user (its manager edits it), so «apply» ends in the list as well.
            if ($close || !$this->app->getIdentity()->authorise('core.edit', 'com_appointments')) {
                $this->releaseEditId(self::CONTEXT, $saved);
                $this->setRedirect($this->listUrl());
            } else {
                $this->setRedirect($this->editUrl($saved));
            }
        }, $back);
    }

    /**
     * Run a service call; a refusal becomes a message and a redirect, so the
     * administrator sees why, and nothing else of the exception leaves.
     */
    private function attempt(callable $work, string $back, bool $secret = false): bool
    {
        try {
            $work();

            return true;
        } catch (\Throwable $e) {
            // Around a recovery secret every error becomes a plain message, so
            // no stack trace with arguments ever leaves this request.
            if (!$e instanceof TeamException && !$secret) {
                throw $e;
            }

            $key = $e->getMessage();
            $this->app->enqueueMessage(
                Text::_($this->app->getLanguage()->hasKey($key) ? $key : 'COM_APPOINTMENTS_TEAM_C_ERROR_UNAVAILABLE'),
                'error'
            );
            $this->setRedirect($back);

            return false;
        }
    }

    private function guard(bool $post, bool $requireEnabled = true): TeamServices
    {
        if (!$this->app->getIdentity()->authorise('core.manage', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if ($post && ($this->input->getMethod() !== 'POST' || !$this->checkToken('post', false))) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $services = TeamServices::fromApplication();

        if ($requireEnabled && !$services->enabled()) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $this->app->setHeader('Cache-Control', 'private, no-store', true);

        return $services;
    }

    private function heldId(): int
    {
        $id = $this->input->getInt('id', 0);

        if ($id <= 0 || !$this->checkEditId(self::CONTEXT, $id)) {
            throw new \RuntimeException(Text::sprintf('JLIB_APPLICATION_ERROR_UNHELD_ID', $id), 403);
        }

        // Checked on every task, not only when the edit id was taken.
        if (!$this->app->getIdentity()->authorise('core.edit', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        return $id;
    }

    private function requireAdmin(): void
    {
        if (!$this->app->getIdentity()->authorise('core.admin', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    private function actor(): int
    {
        return (int) $this->app->getIdentity()->id;
    }

    /**
     * The signed-in administrator's unlocked key, null when the session holds
     * none (then key-holding grants wait for the next key holder).
     */
    private function sessionKeys(TeamServices $services): ?KeyContext
    {
        try {
            return $services->keySession()->current();
        } catch (TeamException) {
            return null;
        }
    }

    /**
     * The recovery secret as typed, read from the raw POST body: no filter that
     * could log or alter it. Never stored, never put back into a form.
     */
    private function secretFromPost(string $name): string
    {
        $value = $this->input->post->get($name, '', 'raw');

        // Out of the request data at once, so no debug output, error page or
        // later code of this request can show it.
        $this->input->post->set($name, null);
        $this->input->set($name, null);
        unset($_POST[$name], $_REQUEST[$name]);

        return \is_string($value) ? trim($value) : '';
    }

    private function wipe(string &$secret): void
    {
        if ($secret !== '' && \function_exists('sodium_memzero')) {
            sodium_memzero($secret);
        }

        $secret = '';
    }

    private function showSecret(string $secret): void
    {
        $view = $this->getView('Calendars', 'html', 'Administrator');
        $view->setRecoverySecret($secret);
        $view->setDocument($this->app->getDocument());
        $view->display();
        $this->wipe($secret);
    }

    private function editUrl(int $id): string
    {
        return Route::_('index.php?option=com_appointments&view=calendar&layout=edit&id=' . $id, false);
    }

    private function listUrl(): string
    {
        return Route::_('index.php?option=com_appointments&view=calendars', false);
    }
}
