<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\UserHelper;
use Joomla\Component\Users\Administrator\Helper\Mfa as MfaHelper;

defined('_JEXEC') or die;

/**
 * The user's key in a signed-in web session (specification 5.3), the escrow.
 *
 * At a password sign-in the password is stretched once and either unwraps the
 * user's secret key or, for a user without a usable key pair, becomes the key
 * that wraps the first pair. What is kept is encrypted with a random 32-byte
 * session key: the ciphertext in the Joomla session, the session key in a
 * separate cookie (HttpOnly, Secure, SameSite=Strict, browser session, one
 * name for the site and one for the administrator). Neither half alone, and
 * so neither the session table nor a stolen cookie alone, reveals anything.
 *
 * The key is released ({@see current()}) only when all of this holds now:
 * the session's user is the user of the escrow and not blocked; the escrow came
 * from a password sign-in of an account that already had an active second
 * factor at that moment; Joomla marks the session as having passed it
 * (`com_users.mfa_checked` = 1, no mandatory setup pending); and the account
 * still has an active second factor, both in Joomla's own records with an
 * enabled method and by the grant service. Joomla sets `mfa_checked` also for
 * accounts without any second factor and for silent sign-ins, so the flag
 * alone is never enough. Creating a first key pair, or replacing a key the
 * password no longer opens, happens only at that release, after the second
 * factor.
 *
 * Without HTTPS nothing is kept: a Secure cookie would never come back.
 *
 * @since  1.4.0
 */
final class KeySession implements KeySessionInterface
{
    private const SESSION_KEY = 'com_appointments.team_keys.';

    private const COOKIE = ['site' => 'appointments_keys_site', 'administrator' => 'appointments_keys_admin'];

    /** The escrow holds the user's secret key. */
    private const MODE_KEY = 'key';

    /** The escrow holds the password key for the first key pair (no usable pair yet). */
    private const MODE_CREATE = 'create';

    /** The password signed in but no longer opens the stored key: reset and create at release. */
    private const MODE_REPLACE = 'replace';

    /** Joomla's response types of sign-ins without a password. */
    private const SILENT_TYPES = ['cookie', 'passwordless'];

    public function __construct(private readonly TeamServices $services)
    {
    }

    /**
     * Response types that are no password sign-in: Joomla's defaults plus the
     * com_users option `silentresponses`. The user plugin passes an empty
     * password for them, which clears any escrow of the session.
     *
     * @return  string[]  Lower case.
     */
    public static function silentResponseTypes(): array
    {
        $types = self::SILENT_TYPES;

        try {
            $configured = (string) \Joomla\CMS\Component\ComponentHelper::getParams('com_users')->get('silentresponses', '');

            foreach (explode(',', $configured) as $type) {
                if (trim($type) !== '') {
                    $types[] = strtolower(trim($type));
                }
            }
        } catch (\Throwable $e) {
            // Defaults only.
        }

        return array_values(array_unique($types));
    }

    public function onLogin(int $userId, string $clearPassword): void
    {
        try {
            $this->forget();

            if ($clearPassword !== '') {
                $this->login($userId, $clearPassword);
            }
        } catch (\Throwable $e) {
            $this->forgetQuietly();
            $this->warn('the keys could not be prepared at sign-in', $e);
        }
    }

    public function onLogout(int $userId): void
    {
        $this->forgetQuietly();
    }

    public function onPasswordChanged(int $userId, string $clearPassword): void
    {
        try {
            $keys = $this->keyService();
            $status = $keys->userKeyStatus($userId)['status'];

            // An account without a usable key pair has nothing to protect: the
            // activation of a new account sets its first password this way, and a
            // reset account is reset already. Nothing is reset, nothing is mailed.
            if ($status === 'none' || $status === 'reset') {
                $this->forgetIfOwnedBy($userId);

                return;
            }

            $context = $this->current();

            if ($context !== null && $context->userId() === $userId && $status === 'usable') {
                try {
                    $keys->rewrapForPassword($context, $clearPassword);
                } finally {
                    $context->wipe();
                }

                return;
            }

            if ($context !== null) {
                $context->wipe();
            }

            $actor = $this->identityId();
            $this->forgetIfOwnedBy($userId);
            $this->resetFlow($userId, $actor !== null && $actor !== $userId ? 'password_set' : 'password_reset', $actor !== $userId ? $actor : null);
        } catch (\Throwable $e) {
            $this->warn('a password change could not be applied to the keys', $e);
        }
    }

    public function current(): ?KeyContext
    {
        try {
            return $this->release();
        } catch (\Throwable $e) {
            $this->warn('the keys of the session could not be released', $e);

            return null;
        }
    }

    public function requireUnlocked(): KeyContext
    {
        $context = $this->current();

        if ($context === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_B_ERROR_KEYS_LOCKED', 423);
        }

        return $context;
    }

    // ------------------------------------------------------------------

    private function login(int $userId, string $password): void
    {
        $app = Factory::getApplication();
        $client = $this->client($app);

        if ($client === null || $userId <= 0 || !$this->secure($app)) {
            return;
        }

        if ($this->services->grants()->audienceOf($userId) === 'none') {
            return;
        }

        // The response of a "remember me" sign-in carries the stored hash in the
        // password field. Only a password that verifies against the account's
        // hash is a password sign-in; nothing is rehashed or saved here.
        $db = $this->services->db();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $hash = (string) $db->setQuery(
            $query->select($db->quoteName('password'))->from($db->quoteName('#__users'))
                ->where($db->quoteName('id') . ' = ' . $userId)
                ->where($db->quoteName('block') . ' = 0')
        )->loadResult();

        if ($hash === '' || !UserHelper::verifyPassword($password, $hash)) {
            return;
        }

        $secondFactor = $this->joomlaSecondFactor($userId) && $this->services->grants()->hasSecondFactor($userId);
        $keys = $this->keyService();
        $status = $keys->userKeyStatus($userId);

        if ($status['status'] === 'tampered') {
            return;
        }

        if ($status['status'] === 'usable') {
            $kek = $keys->passwordKek($password, $status['salt'], $status['opslimit'], $status['memlimit']);
            $context = $keys->unlockWithKek($userId, $kek);
            CipherSuite::wipe($kek);

            if ($context !== null) {
                $this->store($app, $client, $userId, self::MODE_KEY, $context->userKeyVersion(), $context->publicKey(), '', $secondFactor, $context->secretKey());
                $context->wipe();

                return;
            }
        }

        // No usable pair, or the password no longer opens it (changed while the
        // feature was off or behind Joomla's back): prepare a new pair, created
        // only after the second factor.
        $salt = random_bytes(CipherSuite::PWHASH_SALT_BYTES);
        $kek = $keys->passwordKek($password, $salt, CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT);
        $mode = $status['status'] === 'usable' ? self::MODE_REPLACE : self::MODE_CREATE;
        $this->store($app, $client, $userId, $mode, $status['version'], $status['public_key'], $salt, $secondFactor, $kek);
        CipherSuite::wipe($kek);
    }

    private function release(): ?KeyContext
    {
        $app = Factory::getApplication();
        $client = $this->client($app);

        if ($client === null) {
            return null;
        }

        $session = $app->getSession();
        $escrow = $session->get(self::SESSION_KEY . $client);
        $escrow = \is_object($escrow) ? (array) $escrow : $escrow;

        if (!\is_array($escrow) || !isset($escrow['uid'], $escrow['mode'], $escrow['version'], $escrow['nonce'], $escrow['box'])) {
            return null;
        }

        $userId = (int) $escrow['uid'];
        $identity = $app->getIdentity();

        if ($identity === null || $identity->guest || (int) $identity->id !== $userId || (int) $identity->block !== 0
            || empty($escrow['mfa'])
            || (int) $session->get('com_users.mfa_checked', 0) !== 1
            || (int) $session->get('com_users.mandatory_mfa_setup', 0) !== 0
            || !$this->joomlaSecondFactor($userId)
            || !$this->services->grants()->hasSecondFactor($userId)) {
            return null;
        }

        $sessionKey = $this->cookieKey($app, $client);

        if ($sessionKey === null) {
            return null;
        }

        $nonce = (string) base64_decode((string) $escrow['nonce'], true);
        $payload = CipherSuite::decrypt(
            $sessionKey,
            (string) base64_decode((string) $escrow['box'], true),
            self::escrowAd($userId, $client, (string) $escrow['mode'], (int) $escrow['version'], $nonce)
        );

        if ($payload === null || \strlen($payload) !== CipherSuite::KEY_BYTES) {
            CipherSuite::wipe($sessionKey);
            CipherSuite::wipe($payload);

            return null;
        }

        $keys = $this->keyService();
        $status = $keys->userKeyStatus($userId);
        $observedPublic = (string) base64_decode((string) ($escrow['pub'] ?? ''), true);
        $unchanged = $status['version'] === (int) $escrow['version'] && hash_equals($status['public_key'], $observedPublic);
        $context = null;

        try {
            switch ($escrow['mode']) {
                case self::MODE_KEY:
                    if ($status['status'] === 'usable' && $unchanged && hash_equals($observedPublic, CipherSuite::publicKeyOf($payload))) {
                        $context = KeyContext::create($userId, $status['version'], $payload, $observedPublic);
                    }

                    break;

                case self::MODE_CREATE:
                    if (($status['status'] === 'none' || $status['status'] === 'reset') && $unchanged) {
                        $context = $keys->createUserKeyWithKek($userId, $payload, (string) base64_decode((string) ($escrow['salt'] ?? ''), true), CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT);
                    }

                    break;

                case self::MODE_REPLACE:
                    if ($status['status'] === 'usable' && $unchanged) {
                        $this->resetFlow($userId, 'key_unreachable', null);
                        $context = $keys->createUserKeyWithKek($userId, $payload, (string) base64_decode((string) ($escrow['salt'] ?? ''), true), CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT);
                    }

                    break;
            }

            if ($context === null) {
                // The stored key changed since the sign-in (reset, another session):
                // this escrow is stale for good.
                $this->forget();

                return null;
            }

            if ($escrow['mode'] !== self::MODE_KEY) {
                // From now on the session holds the key itself, under the same cookie.
                $this->seal($session, $client, $sessionKey, $userId, self::MODE_KEY, $context->userKeyVersion(), $context->publicKey(), '', true, $context->secretKey());
            }

            return $context;
        } finally {
            CipherSuite::wipe($payload);
            CipherSuite::wipe($sessionKey);
        }
    }

    /**
     * Password reset, password set by somebody else, or a key the password no
     * longer opens: new key pair, wraps gone, grants suspended, devices into
     * clean-up, mail. Each step on its own, so a failing one does not stop the others.
     */
    private function resetFlow(int $userId, string $reason, ?int $actorUserId): void
    {
        foreach ([
            fn () => $this->keyService()->resetUserKey($userId),
            fn () => $this->services->grants()->suspendAllForUser($userId, $reason),
            fn () => $this->services->cleanup()->startForUser($userId, $reason, $actorUserId),
            fn () => $this->services->mailer()->accessSuspended($userId, $reason),
        ] as $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $this->warn('a step of a key reset failed', $e);
            }
        }
    }

    private function store(CMSApplicationInterface $app, string $client, int $userId, string $mode, int $version, string $publicKey, string $salt, bool $secondFactor, string $payload): void
    {
        $sessionKey = random_bytes(CipherSuite::KEY_BYTES);

        try {
            $this->seal($app->getSession(), $client, $sessionKey, $userId, $mode, $version, $publicKey, $salt, $secondFactor, $payload);
            $this->setCookie($app, $client, bin2hex($sessionKey), 0);
        } finally {
            CipherSuite::wipe($sessionKey);
        }
    }

    private function seal(object $session, string $client, string $sessionKey, int $userId, string $mode, int $version, string $publicKey, string $salt, bool $secondFactor, string $payload): void
    {
        $nonce = random_bytes(16);
        $session->set(self::SESSION_KEY . $client, [
            'uid'     => $userId,
            'mode'    => $mode,
            'version' => $version,
            'pub'     => base64_encode($publicKey),
            'salt'    => base64_encode($salt),
            'mfa'     => $secondFactor,
            'nonce'   => base64_encode($nonce),
            'box'     => base64_encode(CipherSuite::encrypt($sessionKey, $payload, self::escrowAd($userId, $client, $mode, $version, $nonce))),
        ]);
    }

    /**
     * Associated data of the escrow: bound to user, client, mode, user key
     * version and a fresh nonce, so a box cannot be replayed in another place.
     */
    public static function escrowAd(int $userId, string $client, string $mode, int $version, string $nonce): string
    {
        return CipherSuite::associatedData('key-escrow', $userId, $client, $mode, $version, bin2hex($nonce));
    }

    private function forget(): void
    {
        $app = Factory::getApplication();
        $client = $this->client($app);

        if ($client === null) {
            return;
        }

        $session = $app->getSession();

        if ($session->has(self::SESSION_KEY . $client)) {
            $session->remove(self::SESSION_KEY . $client);
        }

        if ($this->cookieKey($app, $client, false) !== null) {
            $this->setCookie($app, $client, '', time() - 86400);
        }
    }

    private function forgetQuietly(): void
    {
        try {
            $this->forget();
        } catch (\Throwable $e) {
            $this->warn('the keys of the session could not be cleared', $e);
        }
    }

    private function forgetIfOwnedBy(int $userId): void
    {
        $app = Factory::getApplication();
        $client = $this->client($app);
        $escrow = $client === null ? null : $app->getSession()->get(self::SESSION_KEY . $client);
        $escrow = \is_object($escrow) ? (array) $escrow : $escrow;

        if (\is_array($escrow) && (int) ($escrow['uid'] ?? 0) === $userId) {
            $this->forget();
        }
    }

    /**
     * The 32-byte session key from the cookie, null when absent or malformed.
     */
    private function cookieKey(CMSApplicationInterface $app, string $client, bool $decode = true): ?string
    {
        $value = (string) $app->getInput()->cookie->get(self::COOKIE[$client], '', 'ALNUM');

        if (\strlen($value) !== 2 * CipherSuite::KEY_BYTES || !ctype_xdigit($value)) {
            return null;
        }

        return $decode ? (string) hex2bin($value) : $value;
    }

    private function setCookie(CMSApplicationInterface $app, string $client, string $value, int $expires): void
    {
        $app->getInput()->cookie->set(self::COOKIE[$client], $value, [
            'expires'  => $expires,
            'path'     => (string) ($app->get('cookie_path') ?: '/'),
            'domain'   => (string) $app->get('cookie_domain', ''),
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private function client(CMSApplicationInterface $app): ?string
    {
        foreach (array_keys(self::COOKIE) as $client) {
            if ($app->isClient($client)) {
                return $client;
            }
        }

        return null;
    }

    private function secure(CMSApplicationInterface $app): bool
    {
        return method_exists($app, 'isSslConnection') ? (bool) $app->isSslConnection() : \Joomla\CMS\Uri\Uri::getInstance()->isSsl();
    }

    private function identityId(): ?int
    {
        $identity = Factory::getApplication()->getIdentity();

        return $identity === null || $identity->guest ? null : (int) $identity->id;
    }

    /**
     * Joomla's own test of its captive page: a record of a method that is
     * enabled now, backup codes not counted.
     */
    private function joomlaSecondFactor(int $userId): bool
    {
        $methods = [];

        foreach (MfaHelper::getMfaMethods() as $method) {
            $methods[] = (string) ($method['name'] ?? '');
        }

        foreach (MfaHelper::getUserMfaRecords($userId) as $record) {
            if ($record->method !== 'backupcodes' && \in_array($record->method, $methods, true)) {
                return true;
            }
        }

        return false;
    }

    private function keyService(): KeyService
    {
        $keys = $this->services->keys();

        if (!$keys instanceof KeyService) {
            throw new \LogicException('The key session needs the key service of the same work package.');
        }

        return $keys;
    }

    private function warn(string $what, \Throwable $e): void
    {
        try {
            Log::add('Internet calendars: ' . $what . ' (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
        } catch (\Throwable $ignored) {
        }
    }
}
