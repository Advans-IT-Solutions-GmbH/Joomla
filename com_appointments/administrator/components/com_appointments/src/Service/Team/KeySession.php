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

    /** Seconds after the password sign-in within which the counting factor must have been used. */
    private const PROOF_WINDOW_SECONDS = 600;

    /** MFA methods that do not count as a second factor for keys: backup codes, and e-mail codes (the channel of the password reset). */
    private const NOT_A_SECOND_FACTOR = ['backupcodes', 'email'];

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

    public function onLogin(int $userId, #[\SensitiveParameter] string $clearPassword): void
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

    public function onPasswordChanged(int $userId, #[\SensitiveParameter] string $clearPassword): void
    {
        try {
            $keys = $this->keyService();
            $status = $keys->userKeyStatus($userId)['status'];
            $actor = $this->identityId();
            $reason = $actor !== null && $actor !== $userId ? 'password_set' : 'password_reset';
            $actorUserId = $actor !== $userId ? $actor : null;

            // A new account has no key pair yet: its activation sets the first
            // password this way. Nothing to reset, nothing to mail.
            if ($status === 'none') {
                $this->forgetIfOwnedBy($userId);

                return;
            }

            // Reset already (grants given again since then wait for a key), or a
            // row that fails its MAC (after a change of the site secret every row
            // does): suspend and clean up, but never destroy key material.
            if ($status === 'reset' || $status === 'tampered') {
                $this->forgetIfOwnedBy($userId);

                // A changed site secret makes every row fail: suspending would
                // re-sign the grants under the wrong secret. Log only, so the old
                // secret can still be restored with everything intact.
                if ($status === 'tampered' && $keys->siteSecretSuspect()) {
                    $keys->reportMacFailure('user_keys', ['subject_user_id' => $userId]);

                    return;
                }

                $this->suspendFlow($userId, $reason, $actorUserId);

                return;
            }

            $context = $this->current();

            if ($context !== null && $context->userId() === $userId) {
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

            // The same password saved again (for example in a passkey session)
            // changes nothing.
            $same = $keys->unlockWithPassword($userId, $clearPassword);

            if ($same !== null) {
                $same->wipe();

                return;
            }

            $this->forgetIfOwnedBy($userId);
            $this->resetFlow($userId, $reason, $actorUserId);
        } catch (\Throwable $e) {
            $this->warn('a password change could not be applied to the keys', $e);
        }
    }

    public function current(): ?KeyContext
    {
        try {
            return $this->release();
        } catch (TransactionLostException $e) {
            throw $e;
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

    private function login(int $userId, #[\SensitiveParameter] string $password): void
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

                // A row from before a raise of the default limits is rewrapped
                // with the current ones (same key pair, same password).
                if ($status['opslimit'] < CipherSuite::PWHASH_OPSLIMIT || $status['memlimit'] < CipherSuite::PWHASH_MEMLIMIT) {
                    try {
                        $keys->rewrapForPassword($context, $password);
                    } catch (\Throwable $e) {
                        $this->warn('the password wrap could not be strengthened', $e);
                    }
                }

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
            || !$this->accountActive($userId)
            || empty($escrow['mfa'])
            || (int) $session->get('com_users.mfa_checked', 0) !== 1
            || (int) $session->get('com_users.mandatory_mfa_setup', 0) !== 0
            || !$this->joomlaSecondFactor($userId)
            || !$this->services->grants()->hasSecondFactor($userId)) {
            return null;
        }

        $signedInAt = (int) ($escrow['at'] ?? 0);
        $proven = !empty($escrow['ok']);

        // The proof that this sign-in passed with a counting factor is judged
        // once, at the first release, and then frozen in the escrow: a later
        // use of a counting factor in another session can never turn a failed
        // proof into a success, and a failed one ends the escrow for good.
        if (!$proven && (!hash_equals((string) ($escrow['nc'] ?? ''), $this->factorSnapshot($userId))
            || !$this->countingFactorUsedSince($userId, (string) ($escrow['cs'] ?? ''), $signedInAt))) {
            $this->forget();

            return null;
        }

        $sessionKey = $this->cookieKey($app, $client);

        if ($sessionKey === null) {
            return null;
        }

        $nonce = (string) base64_decode((string) $escrow['nonce'], true);
        $observedPublic = (string) base64_decode((string) ($escrow['pub'] ?? ''), true);
        $salt = (string) base64_decode((string) ($escrow['salt'] ?? ''), true);
        $payload = CipherSuite::decrypt(
            $sessionKey,
            (string) base64_decode((string) $escrow['box'], true),
            self::escrowAd($userId, $client, (string) $escrow['mode'], (int) $escrow['version'], $nonce, $observedPublic, $salt, (bool) $escrow['mfa'], (string) ($escrow['nc'] ?? ''), (string) ($escrow['cs'] ?? ''), $signedInAt, $proven)
        );

        if ($payload === null || \strlen($payload) !== CipherSuite::KEY_BYTES) {
            CipherSuite::wipe($sessionKey);
            CipherSuite::wipe($payload);

            return null;
        }

        $keys = $this->keyService();
        $status = $keys->userKeyStatus($userId);
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
                        $context = $keys->createUserKeyWithKek($userId, $payload, $salt, CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT);
                    }

                    break;

                case self::MODE_REPLACE:
                    // A new pair only after the old one is reset and the grants are
                    // suspended together; otherwise nothing, and the escrow goes.
                    // The observed version and public key are compared again under
                    // the lock of the reset, so a second stale escrow can never
                    // reset a key that another session created meanwhile.
                    if ($status['status'] === 'usable' && $unchanged && $this->resetFlow($userId, 'key_unreachable', null, (int) $escrow['version'], $observedPublic)) {
                        $context = $keys->createUserKeyWithKek($userId, $payload, $salt, CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT);
                    }

                    break;
            }

            if ($context === null) {
                // The stored key changed since the sign-in (reset, another session):
                // this escrow is stale for good.
                $this->forget();

                return null;
            }

            if ($escrow['mode'] !== self::MODE_KEY || !$proven) {
                // From now on the session holds the key itself, under the same
                // cookie, with the proof frozen as passed.
                $this->seal($session, $client, $sessionKey, $userId, self::MODE_KEY, $context->userKeyVersion(), $context->publicKey(), '', true, (string) $escrow['nc'], (string) $escrow['cs'], $signedInAt, true, $context->secretKey());
            }

            return $context;
        } finally {
            CipherSuite::wipe($payload);
            CipherSuite::wipe($sessionKey);
        }
    }

    /**
     * Password reset, password set by somebody else, or a key the password no
     * longer opens: new key pair and wraps gone together with the suspension of
     * every grant, in one transaction, so a reset never leaves grants active
     * that the next key pair would receive again. Then devices into clean-up
     * (also when the reset failed) and the mail (only when it succeeded).
     *
     * @return  bool  Whether key and grants were reset.
     */
    private function resetFlow(int $userId, string $reason, ?int $actorUserId, ?int $expectedVersion = null, ?string $expectedPublicKey = null): bool
    {
        $db = $this->services->db();
        $db->transactionStart(true);

        try {
            if ($expectedVersion === null) {
                $this->keyService()->resetUserKey($userId);
            } else {
                $this->keyService()->resetUserKeyIf($userId, $expectedVersion, (string) $expectedPublicKey);
            }

            $this->services->grants()->suspendAllForUser($userId, $reason);
            $db->transactionCommit(true);
            $done = true;
        } catch (\Throwable $e) {
            try {
                $db->transactionRollback(true);
            } catch (\Throwable $lost) {
                // The savepoint is gone: the outer transaction was rolled back.
                throw new TransactionLostException('The database transaction was lost.', 500, $lost);
            }

            if ($e instanceof TransactionLostException) {
                throw $e;
            }

            $this->warn('a key reset was refused', $e);

            // The observed key is no longer the stored one: another session
            // created a newer key. Nothing of this stale reset may touch it.
            if ($e instanceof TeamException && $e->getMessage() === 'COM_APPOINTMENTS_TEAM_B_ERROR_KEY_CHANGED') {
                return false;
            }

            $done = false;

            // Written after the rollback, which would otherwise take the log row
            // of a tampered key row with it.
            if ($e instanceof TeamException && $e->getMessage() === 'COM_APPOINTMENTS_TEAM_B_ERROR_KEY_TAMPERED') {
                $this->keyService()->reportMacFailure('user_keys', ['subject_user_id' => $userId]);
            }
        }

        $this->step(fn () => $this->services->cleanup()->startForUser($userId, $reason, $actorUserId));

        if ($done) {
            $this->step(fn () => $this->services->mailer()->accessSuspended($userId, $reason));
        }

        return $done;
    }

    /**
     * Grants suspended, devices into clean-up, mail; the key row is not touched.
     */
    private function suspendFlow(int $userId, string $reason, ?int $actorUserId): void
    {
        $this->step(fn () => $this->services->grants()->suspendAllForUser($userId, $reason));
        $this->step(fn () => $this->services->cleanup()->startForUser($userId, $reason, $actorUserId));
        $this->step(fn () => $this->services->mailer()->accessSuspended($userId, $reason));
    }

    private function step(callable $operation): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->warn('a step of a key reset failed', $e);
        }
    }

    /**
     * Whether the account exists and is not blocked, read from the database
     * (the identity in the session may be older).
     */
    private function accountActive(int $userId): bool
    {
        $db = $this->services->db();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $block = $db->setQuery(
            $query->select($db->quoteName('block'))->from($db->quoteName('#__users'))->where($db->quoteName('id') . ' = ' . $userId)
        )->loadResult();

        return $block !== null && (int) $block === 0;
    }

    private function store(CMSApplicationInterface $app, string $client, int $userId, string $mode, int $version, string $publicKey, string $salt, bool $secondFactor, #[\SensitiveParameter] string $payload): void
    {
        $sessionKey = random_bytes(CipherSuite::KEY_BYTES);

        try {
            $this->seal($app->getSession(), $client, $sessionKey, $userId, $mode, $version, $publicKey, $salt, $secondFactor, $this->factorSnapshot($userId), $this->countingSnapshot($userId), time(), false, $payload);
            $this->setCookie($app, $client, bin2hex($sessionKey), 0);
        } finally {
            CipherSuite::wipe($sessionKey);
        }
    }

    private function seal(object $session, string $client, #[\SensitiveParameter] string $sessionKey, int $userId, string $mode, int $version, string $publicKey, string $salt, bool $secondFactor, string $factorSnapshot, string $countingSnapshot, int $signedInAt, bool $proven, #[\SensitiveParameter] string $payload): void
    {
        $nonce = random_bytes(16);
        $session->set(self::SESSION_KEY . $client, [
            'uid'     => $userId,
            'mode'    => $mode,
            'version' => $version,
            'pub'     => base64_encode($publicKey),
            'salt'    => base64_encode($salt),
            'mfa'     => $secondFactor,
            'nc'      => $factorSnapshot,
            'cs'      => $countingSnapshot,
            'at'      => $signedInAt,
            'ok'      => $proven,
            'nonce'   => base64_encode($nonce),
            'box'     => base64_encode(CipherSuite::encrypt($sessionKey, $payload, self::escrowAd($userId, $client, $mode, $version, $nonce, $publicKey, $salt, $secondFactor, $factorSnapshot, $countingSnapshot, $signedInAt, $proven))),
        ]);
    }

    /**
     * Associated data of the escrow: bound to user, client, mode, user key
     * version, a fresh nonce and every other field of the session entry
     * (observed public key, salt, second factor at sign-in, snapshot of the
     * non-counting MFA records at sign-in), so neither a box nor a field can be
     * replayed or changed in the session table unnoticed.
     */
    public static function escrowAd(int $userId, string $client, string $mode, int $version, string $nonce, string $publicKey, string $salt, bool $secondFactor, string $factorSnapshot, string $countingSnapshot, int $signedInAt, bool $proven): string
    {
        return CipherSuite::associatedData('key-escrow', $userId, $client, $mode, $version, bin2hex($nonce), bin2hex($publicKey), bin2hex($salt), $secondFactor ? 1 : 0, $factorSnapshot, $countingSnapshot, $signedInAt, $proven ? 1 : 0);
    }

    /**
     * The state of the MFA records that do not count (backup codes, e-mail
     * codes) as a hash: record id, method and `last_used`. Joomla records in no
     * session which method passed its captive page, but it stamps the record it
     * accepted with `last_used`. Taken at the sign-in, before the captive page,
     * and required unchanged at the release, it proves by exclusion that this
     * session passed with a counting method: had it used a backup or e-mail
     * code, that record would have changed. A use of such a code in another
     * session locks this one too, which fails closed.
     */
    /**
     * `last_used` of every enabled counting MFA record at the sign-in, as JSON
     * (record id => value), for the positive half of the proof.
     */
    private function countingSnapshot(int $userId): string
    {
        $map = [];

        foreach ($this->countingRecords($userId) as $record) {
            $map[(string) (int) $record->id] = (string) ($record->last_used ?? '');
        }

        ksort($map, SORT_STRING);

        return json_encode((object) $map, JSON_THROW_ON_ERROR);
    }

    /**
     * The positive half of the proof: a counting record that existed at the
     * sign-in has been stamped later than then. Joomla stamps by the second, so
     * an exclusion alone could be passed by two backup codes within one second;
     * a counting stamp cannot be produced without that factor. A stamp by a
     * counting factor in another session also satisfies this half, but then
     * the exclusion still proves that this session used no backup or e-mail code.
     */
    private function countingFactorUsedSince(int $userId, string $countingSnapshot, int $signedInAt): bool
    {
        $before = json_decode($countingSnapshot, true);

        if (!\is_array($before) || $signedInAt <= 0) {
            return false;
        }

        // Joomla's captive page follows the sign-in at once: a stamp later than
        // this window belongs to another sign-in.
        $latest = gmdate('Y-m-d H:i:s', $signedInAt + self::PROOF_WINDOW_SECONDS);

        foreach ($this->countingRecords($userId) as $record) {
            $id = (string) (int) $record->id;
            $now = (string) ($record->last_used ?? '');

            if (\array_key_exists($id, $before) && $now !== '' && strcmp($now, (string) $before[$id]) > 0 && strcmp($now, $latest) <= 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return  object[]  The user's MFA records of an enabled, counting method.
     */
    private function countingRecords(int $userId): array
    {
        $methods = $this->enabledMethods();

        return array_values(array_filter(
            MfaHelper::getUserMfaRecords($userId),
            static fn ($record): bool => !\in_array($record->method, self::NOT_A_SECOND_FACTOR, true) && \in_array($record->method, $methods, true)
        ));
    }

    private function factorSnapshot(int $userId): string
    {
        $parts = [];

        foreach (MfaHelper::getUserMfaRecords($userId) as $record) {
            if (\in_array($record->method, self::NOT_A_SECOND_FACTOR, true)) {
                $parts[] = (int) $record->id . ':' . $record->method . ':' . (string) ($record->last_used ?? '');
            }
        }

        sort($parts, SORT_STRING);

        return hash('sha256', 'com_appointments/factor-snapshot/v1|' . implode('|', $parts));
    }    private function forget(): void
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
        $value = (string) $app->getInput()->cookie->get($this->cookieName($app, $client), '', 'ALNUM');

        if (\strlen($value) !== 2 * CipherSuite::KEY_BYTES || !ctype_xdigit($value)) {
            return null;
        }

        return $decode ? (string) hex2bin($value) : $value;
    }

    /**
     * `__Host-` when the site cookie settings allow it (path `/`, no domain):
     * the browser then never sends the cookie to another host or subdomain.
     */
    private function cookieName(CMSApplicationInterface $app, string $client): string
    {
        $hostOnly = ((string) ($app->get('cookie_path') ?: '/')) === '/' && (string) $app->get('cookie_domain', '') === '';

        return ($hostOnly ? '__Host-' : '') . self::COOKIE[$client];
    }

    private function setCookie(CMSApplicationInterface $app, string $client, string $value, int $expires): void
    {
        $app->getInput()->cookie->set($this->cookieName($app, $client), $value, [
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
     * enabled now. Not counted: backup codes, and the code by e-mail, which
     * travels the same way as the password reset link, so a reset by e-mail
     * plus that code would be one factor twice. An account for which Joomla
     * disallows MFA (a group in `neverMFAUserGroups` and none in
     * `forceMFAUserGroups`) never passes a second factor, records or not.
     */
    private function joomlaSecondFactor(int $userId): bool
    {
        $options = \Joomla\CMS\Component\ComponentHelper::getParams('com_users');
        $groups = array_map('intval', \Joomla\CMS\Access\Access::getGroupsByUser($userId, true));
        $never = array_map('intval', (array) $options->get('neverMFAUserGroups', []));
        $force = array_map('intval', (array) $options->get('forceMFAUserGroups', []));

        if (array_intersect($never, $groups) !== [] && array_intersect($force, $groups) === []) {
            return false;
        }

        $methods = $this->enabledMethods();

        foreach (MfaHelper::getUserMfaRecords($userId) as $record) {
            if (!\in_array($record->method, self::NOT_A_SECOND_FACTOR, true) && \in_array($record->method, $methods, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The MFA methods whose plugin is enabled now, read from the extensions
     * table (a method is named after its plugin). Neither the plugins nor the
     * plugin helper are asked: both may start a session.
     *
     * @return  string[]
     */
    private function enabledMethods(): array
    {
        $db = $this->services->db();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);

        return array_map('strval', $db->setQuery(
            $query->select($db->quoteName('element'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('multifactorauth'))
                ->where($db->quoteName('enabled') . ' = 1')
        )->loadColumn() ?: []);
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
