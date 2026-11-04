<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;

defined('_JEXEC') or die;

/**
 * The user's key in a signed-in web session (work package B).
 *
 * At sign-in the password unwraps the user's secret key. It is then held in
 * the Joomla session only encrypted with a random session key, which lives in
 * a separate HttpOnly, Secure, SameSite=Strict cookie and never in the
 * database. A copy of the session table alone therefore does not unlock it.
 * The key is released to callers only after the session passed the second
 * factor. Sign-in without a password (passkey, "remember me") leaves the key
 * locked: operations that need it answer 423 and ask for a password sign-in.
 *
 * Called by `plg_user_appointments`, which is frozen: it only delegates.
 *
 * @since  1.4.0
 */
interface KeySessionInterface
{
    /**
     * After a successful password sign-in, called only from the user plugin
     * inside Joomla's own sign-in (CMSApplication::login, after the password
     * check); no other page may offer «enter your password to unlock», because a
     * remember-me session would then unlock keys without the second factor.
     * Creates the user's key pair the first
     * time for an eligible user. Never throws into the sign-in: a failure leaves
     * the key locked and is logged.
     */
    public function onLogin(int $userId, #[\SensitiveParameter] string $clearPassword): void;

    public function onLogout(int $userId): void;

    /**
     * After a password change. The same user in an unlocked session: rewrap.
     * Anything else (reset by e-mail, an administrator setting it): reset the
     * user key, suspend the grants, start the device clean-up, mail the user.
     */
    public function onPasswordChanged(int $userId, #[\SensitiveParameter] string $clearPassword): void;

    /**
     * The current user's key, or null when the session holds none, has not
     * passed the second factor, or belongs to another user.
     */
    public function current(): ?KeyContext;

    /**
     * @throws  \Advans\Component\Appointments\Administrator\Helper\Team\TeamException  423 when locked.
     */
    public function requireUnlocked(): KeyContext;
}
