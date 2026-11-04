<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;

defined('_JEXEC') or die;

/**
 * Activation of accounts that an administrator created (work package C); the
 * contract is {@see InvitationServiceInterface}.
 *
 * Row MAC of `#__appointments_invitations` ({@see self::macFields()}):
 * token_hash, user_id, email_hash, state, invited_by, expires_utc,
 * completed_utc. `revoked_by` and `created_utc` are bookkeeping.
 *
 * - Token: 32 random bytes, base64url without padding (43 characters), only in
 *   the mail. Stored: `HMAC-SHA256(key(TEAM_INVITATION), "token\0" . token)`.
 * - Address binding: `HMAC-SHA256(key(TEAM_INVITATION), "email\0" . address)`
 *   of the lower-cased address at the time the link was sent. When the
 *   account's address changes afterwards, the link stops working.
 * - Once: completion locks the row (FOR UPDATE) and leaves «open» in the same
 *   transaction; every check of {@see self::inspect()} is repeated under the lock.
 * - Accounts that may sign in to the administrator never get a link: an
 *   intercepted mail must never set the password of a backend account.
 *
 * @since  1.4.0
 */
final class InvitationService implements InvitationServiceInterface
{
    public const TABLE = 'appointments_invitations';

    /** Minimum password length when com_users asks for less. */
    public const MIN_PASSWORD = 12;

    public function __construct(private readonly TeamServices $services)
    {
    }

    // ------------------------------------------------------------------
    // Pure functions
    // ------------------------------------------------------------------

    /**
     * @return  array{token_hash: string, user_id: int, email_hash: string, state: int, invited_by: int, expires_utc: string, completed_utc: string|null}
     */
    public static function macFields(object|array $row): array
    {
        $row = (array) $row;
        $completed = $row['completed_utc'] ?? null;

        return [
            'token_hash'    => bin2hex((string) ($row['token_hash'] ?? '')),
            'user_id'       => (int) ($row['user_id'] ?? 0),
            'email_hash'    => bin2hex((string) ($row['email_hash'] ?? '')),
            'state'         => (int) ($row['state'] ?? 0),
            'invited_by'    => (int) ($row['invited_by'] ?? 0),
            'expires_utc'   => (string) ($row['expires_utc'] ?? ''),
            'completed_utc' => $completed === null || $completed === '' ? null : (string) $completed,
        ];
    }

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Whether a string has the form of a token. Checked before any database
     * access, so nothing else is ever looked up.
     */
    public static function tokenFormatValid(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/D', $token);
    }

    public static function tokenHash(string $key, string $token): string
    {
        return hash_hmac('sha256', "token\0" . $token, $key, true);
    }

    public static function emailHash(string $key, string $email): string
    {
        return hash_hmac('sha256', "email\0" . strtolower(trim($email)), $key, true);
    }

    /**
     * The address as the activation page may show it: «a***@example.org».
     */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /**
     * Joomla's password rules (com_users options, as Joomla's own PasswordRule
     * counts them), with at least {@see self::MIN_PASSWORD} characters.
     *
     * @param   array{minimum_length?: int, minimum_integers?: int, minimum_symbols?: int, minimum_uppercase?: int, minimum_lowercase?: int}  $rules
     */
    public static function passwordAcceptable(#[\SensitiveParameter] string $password, array $rules): bool
    {
        if (!mb_check_encoding($password, 'UTF-8') || trim($password) !== $password || \strlen($password) > 4096) {
            return false;
        }

        $minimum = max(self::MIN_PASSWORD, (int) ($rules['minimum_length'] ?? 0));

        return mb_strlen($password) >= $minimum
            && preg_match_all('/[0-9]/', $password) >= (int) ($rules['minimum_integers'] ?? 0)
            && preg_match_all('/[\W]/', $password) >= (int) ($rules['minimum_symbols'] ?? 0)
            && preg_match_all('/[A-Z]/', $password) >= (int) ($rules['minimum_uppercase'] ?? 0)
            && preg_match_all('/[a-z]/', $password) >= (int) ($rules['minimum_lowercase'] ?? 0);
    }

    /**
     * The activation link with the token, for the activation mail only.
     */
    public static function link(string $token): string
    {
        return Route::link('site', 'index.php?option=com_appointments&view=activation&token=' . rawurlencode($token), false, Route::TLS_FORCE, true);
    }

    /**
     * The administrator page that revokes a completed activation in one step.
     * It shows the account and asks for a confirmation (POST with token).
     */
    public static function revokeUrl(int $invitationId): string
    {
        return rtrim(Uri::root(), '/') . '/administrator/index.php?option=com_appointments&view=invitations&layout=revoke&id=' . $invitationId;
    }

    // ------------------------------------------------------------------
    // Administrator lists
    // ------------------------------------------------------------------

    /**
     * Every activation link, newest first. No token hash, no address hash; the
     * account's current address only masked.
     *
     * @return  object[]
     */
    public static function listing(TeamServices $services, int $invitationId = 0): array
    {
        $db = $services->db();
        $rows = $db->setQuery(
            'SELECT i.*, u.name AS user_name, u.username AS user_username, u.email AS user_email, u.block AS user_block,'
            . ' b.name AS invited_by_name'
            . ' FROM ' . $db->quoteName('#__' . self::TABLE) . ' AS i'
            . ' LEFT JOIN ' . $db->quoteName('#__users') . ' AS u ON u.id = i.user_id'
            . ' LEFT JOIN ' . $db->quoteName('#__users') . ' AS b ON b.id = i.invited_by'
            . ($invitationId > 0 ? ' WHERE i.id = ' . $invitationId : '')
            . ' ORDER BY i.id DESC LIMIT 500'
        )->loadObjectList() ?: [];

        foreach ($rows as $row) {
            $row->mac_valid = \strlen((string) $row->row_mac) === 32
                && $services->keys()->verifyRowMac(self::TABLE, self::macFields($row), (string) $row->row_mac);
            $row->email_masked = $row->user_email === null ? '' : self::maskEmail((string) $row->user_email);
            $row->audience = $services->grants()->audienceOf((int) $row->user_id);
            unset($row->row_mac, $row->token_hash, $row->email_hash, $row->user_email);
        }

        return $rows;
    }

    /**
     * One activation for the revoke page, or null.
     */
    public static function find(TeamServices $services, int $invitationId): ?object
    {
        return $invitationId > 0 ? (self::listing($services, $invitationId)[0] ?? null) : null;
    }

    /**
     * The accounts an administrator may send a link to now: in a team group or
     * the external group, not blocked, no administrator sign-in, no completed
     * activation.
     *
     * @return  object[]  id, name, username, audience.
     */
    public static function candidates(TeamServices $services): array
    {
        $db = $services->db();
        $groups = array_values(array_filter(array_merge(
            array_map('intval', (array) $services->params()->get('team_groups', [])),
            [(int) $services->params()->get('external_group', 0)]
        ), static fn (int $id): bool => $id > 0));

        if ($groups === []) {
            return [];
        }

        $rows = $db->setQuery(
            'SELECT DISTINCT u.id, u.name, u.username FROM ' . $db->quoteName('#__users') . ' AS u'
            . ' INNER JOIN ' . $db->quoteName('#__user_usergroup_map') . ' AS m ON m.user_id = u.id'
            . ' WHERE u.block = 0 AND m.group_id IN (' . implode(',', $groups) . ')'
            . " AND (u.lastvisitDate IS NULL OR u.lastvisitDate < '1000-01-01')"
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__' . self::TABLE) . ' AS i WHERE i.user_id = u.id'
            . ' AND i.state = ' . TeamStates::INVITATION_COMPLETED . ')'
            . ' ORDER BY u.name, u.id LIMIT 1000'
        )->loadObjectList() ?: [];
        $result = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;

            if (self::backendAccount($id)) {
                continue;
            }

            $row->audience = $services->grants()->audienceOf($id);
            $result[] = $row;
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // InvitationServiceInterface
    // ------------------------------------------------------------------

    public function invite(int $actorUserId, int $userId): int
    {
        $this->assertEnabled();
        $user = GrantService::user($userId);

        if ($user === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
        }

        $audience = $this->services->grants()->audienceOf($userId);

        if (!$this->mayAct($actorUserId, $audience) || $actorUserId === $userId) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
        }

        if ($user->block || self::backendAccount($userId, true)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ELIGIBLE', 409);
        }

        if (self::hasSignedIn($user)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_SIGNED_IN', 409);
        }

        $email = strtolower(trim((string) $user->email));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_EMAIL', 409);
        }

        $token = self::newToken();
        $expires = $this->services->now()->modify('+' . TeamStates::INVITATION_HOURS . ' hours');
        $id = $this->atomic(function () use ($actorUserId, $userId, $email, $token, $expires): int {
            $db = $this->services->db();

            foreach ($this->rows('user_id = ' . $userId, true) as $old) {
                if ((int) $old->state === TeamStates::INVITATION_COMPLETED && $this->macValid($old)) {
                    throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_ALREADY_ACTIVATED', 409);
                }

                // One open link per account: an older one stops working.
                if ((int) $old->state === TeamStates::INVITATION_OPEN) {
                    $this->close($old, $actorUserId);
                }
            }

            $row = (object) [
                'token_hash' => self::tokenHash($this->key(), $token), 'user_id' => $userId,
                'email_hash' => self::emailHash($this->key(), $email), 'state' => TeamStates::INVITATION_OPEN,
                'invited_by' => $actorUserId, 'expires_utc' => $expires->format('Y-m-d H:i:s'), 'completed_utc' => null,
            ];
            $mac = $this->services->keys()->rowMac(self::TABLE, self::macFields($row));
            $db->setQuery(
                'INSERT INTO ' . $db->quoteName('#__' . self::TABLE)
                . ' (token_hash, user_id, email_hash, state, invited_by, created_utc, expires_utc, row_mac) VALUES ('
                . $this->hex($row->token_hash) . ', ' . $userId . ', ' . $this->hex($row->email_hash) . ', '
                . TeamStates::INVITATION_OPEN . ', ' . $actorUserId . ', '
                . $db->quote($this->services->now()->format('Y-m-d H:i:s')) . ', ' . $db->quote($row->expires_utc) . ', '
                . $this->hex($mac) . ')'
            )->execute();

            return (int) $db->insertid();
        });

        try {
            $this->services->mailer()->invitation($email, self::link($token), $expires);
        } catch (\Throwable $e) {
            // The token exists only in that mail: without it the link is useless.
            $this->atomic(function () use ($id, $actorUserId): void {
                foreach ($this->rows('id = ' . $id, true) as $row) {
                    $this->close($row, $actorUserId);
                }
            });

            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_MAIL', 502, $e);
        }

        $this->log(AuditEvent::INVITATION_CREATED, [
            'actor_user_id' => $actorUserId, 'subject_user_id' => $userId, 'object_ref' => 'invitation:' . $id,
            'details' => ['audience' => $audience],
        ]);

        return $id;
    }

    public function inspect(string $token): ?array
    {
        if (!$this->services->enabled() || !self::tokenFormatValid($token)) {
            return null;
        }

        $open = $this->openRow($token, false);

        if ($open === null) {
            return null;
        }

        [$row, $user] = $open;

        return [
            'username'     => (string) $user->username,
            'email_masked' => self::maskEmail((string) $user->email),
            'expires_utc'  => (string) $row->expires_utc,
        ];
    }

    public function complete(string $token, #[\SensitiveParameter] string $password): int
    {
        $this->assertEnabled();

        if (!self::tokenFormatValid($token)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
        }

        $params = ComponentHelper::getParams('com_users');
        $rules = [];

        foreach (['minimum_length', 'minimum_integers', 'minimum_symbols', 'minimum_uppercase', 'minimum_lowercase'] as $rule) {
            $rules[$rule] = (int) $params->get($rule, 0);
        }

        if (!self::passwordAcceptable($password, $rules)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_PASSWORD', 400);
        }

        $peek = $this->openRow($token, false);

        if ($peek === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
        }

        // Everything below runs under the account lock that revoke() holds as
        // well: the link is used up in its own committed transaction (two
        // requests with the same token never both get past it), then the
        // password is saved outside every transaction (Joomla's user plugins
        // react with transactions of their own), and a revoke can neither
        // block the account in between nor be undone by a stale save. When
        // the lock is not available, nothing has changed.
        [$outcome, $row] = $this->withAccountLock((int) $peek[0]->user_id, function () use ($token, $password, $peek): array {
            $row = $this->atomic(function () use ($token, $peek): ?object {
                $open = $this->openRow($token, true);

                if ($open === null || (int) $open[0]->user_id !== (int) $peek[0]->user_id) {
                    return null;
                }

                [$row] = $open;
                $row->state = TeamStates::INVITATION_COMPLETED;
                $row->completed_utc = $this->services->now()->format('Y-m-d H:i:s');
                $this->store($row, []);

                return $row;
            });

            // Checked again right before the save: an address changed, a
            // promotion to the administrator or a sign-in meanwhile ends it.
            $user = $row === null ? null : $this->eligibleUser($row);

            if ($row === null || $user === null) {
                if ($row !== null) {
                    $this->atomic(function () use ($row): void {
                        $current = $this->rows('id = ' . (int) $row->id, true)[0] ?? null;

                        if ($current !== null && $this->macValid($current)) {
                            $this->close($current, null);
                        }
                    });
                }

                return ['gone', $row];
            }

            $before = $this->storedPasswordHash((int) $row->user_id);

            try {
                $saved = $user->bind(['password' => $password, 'password2' => $password, 'requireReset' => 0, 'activation' => ''])
                    && $user->save(true);
            } catch (\Throwable) {
                // A plugin may throw after Joomla stored the password: then the
                // activation did happen and must be logged and mailed.
                $saved = $this->storedPasswordHash((int) $row->user_id) !== $before;
            }

            if (!$saved) {
                // Joomla refused the password: the link is given back unchanged
                // (still bound to the account and its address), so the person
                // can try again until it expires.
                $this->atomic(function () use ($row): void {
                    $current = $this->rows('id = ' . (int) $row->id, true)[0] ?? null;

                    if ($current !== null && $this->macValid($current) && (int) $current->state === TeamStates::INVITATION_COMPLETED
                        && (string) $current->completed_utc === (string) $row->completed_utc) {
                        $current->state = TeamStates::INVITATION_OPEN;
                        $current->completed_utc = null;
                        $this->store($current, []);
                    }
                });

                return ['refused', $row];
            }

            return ['saved', $row];
        });

        if ($outcome === 'gone') {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
        }

        if ($outcome !== 'saved') {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_PASSWORD', 400);
        }

        $userId = (int) $row->user_id;
        $this->log(AuditEvent::INVITATION_ACCEPTED, ['subject_user_id' => $userId, 'object_ref' => 'invitation:' . (int) $row->id]);

        try {
            $this->services->mailer()->activationCompleted((int) $row->id, $userId, self::revokeUrl((int) $row->id));
        } catch (\Throwable) {
            // The log holds the completion; the administrators see it in the list.
        }

        return $userId;
    }

    public function revoke(int $actorUserId, int $invitationId): void
    {
        $this->assertEnabled();
        $peek = $this->rows('id = ' . $invitationId, false)[0] ?? null;

        if ($peek === null || !$this->macValid($peek)
            || !\in_array((int) $peek->state, [TeamStates::INVITATION_OPEN, TeamStates::INVITATION_COMPLETED], true)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
        }

        $userId = (int) $peek->user_id;

        if ($actorUserId === $userId || !$this->mayRevoke($actorUserId, $userId)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
        }

        // The whole revoke runs under the account lock that complete() holds
        // while it uses up a link and saves the password: a completion can
        // neither slip in between nor undo the block with a stale save. Under
        // the lock the link and the right to revoke are read again. The block
        // comes first and outside every transaction (Joomla's user plugins
        // react with transactions of their own): when Joomla refuses, nothing
        // has changed; when a later step fails, the account stays blocked and
        // the link «completed», so the revoke can be repeated.
        $result = $this->withAccountLock($userId, function () use ($actorUserId, $invitationId, $userId): array {
            $current = $this->rows('id = ' . $invitationId, false)[0] ?? null;

            if ($current === null || !$this->macValid($current) || (int) $current->user_id !== $userId
                || !\in_array((int) $current->state, [TeamStates::INVITATION_OPEN, TeamStates::INVITATION_COMPLETED], true)) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
            }

            if (!$this->mayRevoke($actorUserId, $userId)) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_ALLOWED', 403);
            }

            $state = (int) $current->state;

            if ($state === TeamStates::INVITATION_COMPLETED) {
                $this->block($userId);
            }

            return $this->atomic(function () use ($actorUserId, $invitationId, $userId, $state): array {
                $row = $this->rows('id = ' . $invitationId, true)[0] ?? null;

                if ($row === null || !$this->macValid($row) || (int) $row->state !== $state || (int) $row->user_id !== $userId) {
                    throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_NOT_FOUND', 404);
                }

                $completed = $state === TeamStates::INVITATION_COMPLETED;
                $calendars = $completed ? GrantService::removeAllForUser($this->services, $userId) : [];
                $this->close($row, $actorUserId);

                return ['completed' => $completed, 'calendars' => $calendars, 'user' => $userId];
            });
        });

        if ($result['completed']) {
            $this->endAccess($result['user'], $actorUserId);

            foreach (array_keys($result['calendars']) as $calendarId) {
                $this->log(AuditEvent::GRANT_REVOKED, [
                    'actor_user_id' => $actorUserId, 'subject_user_id' => $result['user'], 'calendar_id' => $calendarId,
                    'details' => ['reason' => 'activation_revoked'],
                ]);

                try {
                    $this->services->mailer()->grantChanged($calendarId, $result['user'], GrantService::roleLabelKey(null), $actorUserId);
                } catch (\Throwable) {
                }
            }
        }

        $this->log(AuditEvent::INVITATION_REVOKED, [
            'actor_user_id' => $actorUserId, 'subject_user_id' => $result['user'], 'object_ref' => 'invitation:' . $invitationId,
            'details' => ['completed' => $result['completed'] ? 1 : 0],
        ]);
    }

    public function purgeExpired(\DateTimeImmutable $nowUtc): int
    {
        $db = $this->services->db();
        $now = $nowUtc->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return $this->atomic(function () use ($db, $now): int {
            $rows = $this->rows('state = ' . TeamStates::INVITATION_OPEN . ' AND expires_utc <= ' . $db->quote($now), true);

            foreach ($rows as $row) {
                $this->close($row, null);
            }

            return \count($rows);
        });
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The open, unexpired, verified link of a token whose account is unblocked,
     * eligible, without administrator sign-in and still has the bound address.
     *
     * @return  array{0: object, 1: User}|null
     */
    private function openRow(string $token, bool $forUpdate): ?array
    {
        $hash = self::tokenHash($this->key(), $token);
        $row = $this->rows('token_hash = ' . $this->hex($hash), $forUpdate)[0] ?? null;

        if ($row === null || !hash_equals((string) $row->token_hash, $hash) || !$this->macValid($row)
            || (int) $row->state !== TeamStates::INVITATION_OPEN
            || (string) $row->expires_utc <= $this->services->now()->format('Y-m-d H:i:s')) {
            return null;
        }

        $user = $this->eligibleUser($row);

        return $user === null ? null : [$row, $user];
    }

    /**
     * The account of a link, freshly loaded, when it may still be activated:
     * unblocked, without administrator sign-in, in a calendar group, never
     * signed in, and still with the address the link was sent to.
     */
    private function eligibleUser(object $row): ?User
    {
        $userId = (int) $row->user_id;
        $user = GrantService::user($userId);

        if ($user === null || $user->block || self::backendAccount($userId, true)
            || $this->services->grants()->audienceOf($userId) === GrantService::AUDIENCE_NONE
            || self::hasSignedIn($user)
            || !hash_equals((string) $row->email_hash, self::emailHash($this->key(), (string) $user->email))) {
            return null;
        }

        return $user;
    }

    /**
     * Run work while holding a database-wide named lock for one account, so a
     * completion and a revoke of the same account never interleave. The lock
     * belongs to the connection, not to a transaction, so it also covers
     * Joomla's own saves with their plugins.
     *
     * @template T
     * @param   callable():T  $work
     * @return  T
     */
    private function withAccountLock(int $userId, callable $work): mixed
    {
        $db = $this->services->db();
        $name = $db->quote('com_appointments.activation.' . $userId);

        if ((int) $db->setQuery('SELECT GET_LOCK(' . $name . ', 10)')->loadResult() !== 1) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_UNAVAILABLE', 503);
        }

        try {
            return $work();
        } finally {
            try {
                $db->setQuery('SELECT RELEASE_LOCK(' . $name . ')')->loadResult();
            } catch (\Throwable) {
                // Never mask the outcome; the lock ends with the connection anyway.
            }
        }
    }

    /**
     * The stored password hash, compared only, never shown or logged.
     */
    private function storedPasswordHash(int $userId): string
    {
        $db = $this->services->db();

        return hash('sha256', (string) $db->setQuery(
            'SELECT ' . $db->quoteName('password') . ' FROM ' . $db->quoteName('#__users') . ' WHERE id = ' . $userId
        )->loadResult());
    }

    /**
     * Whether the account has ever signed in. An activation link is only for an
     * account that never did (spec 5.11): a link played back from an old copy of
     * the database cannot take over an account in use.
     */
    private static function hasSignedIn(User $user): bool
    {
        $last = trim((string) ($user->lastvisitDate ?? ''));

        return $last !== '' && !str_starts_with($last, '0000-00-00');
    }

    /**
     * Team accounts need `core.admin` on the component; external accounts the
     * right to share externally. An account outside both groups gets nothing.
     */
    private function mayAct(int $actorUserId, string $audience): bool
    {
        $actor = GrantService::user($actorUserId);

        if ($actor === null || $actor->block) {
            return false;
        }

        if ($audience === TeamStates::AUDIENCE_TEAM) {
            return $actor->authorise('core.admin', 'com_appointments') && $this->services->grants()->hasSecondFactor($actorUserId);
        }

        // External accounts only with the right to share externally, also for
        // an administrator: core.admin alone does not include it.
        return $audience === TeamStates::AUDIENCE_EXTERNAL && GrantService::sharesExternally($this->services, $actorUserId);
    }

    /**
     * Whether the account may sign in to the administrator (or is a Super User).
     * With $fresh the permission caches of this request are dropped first, so a
     * group change made by an administrator a moment ago counts (Joomla keeps
     * groups and rules per request).
     */
    private static function backendAccount(int $userId, bool $fresh = false): bool
    {
        if ($fresh) {
            Access::clearStatics();
        }

        return Access::check($userId, 'core.admin') === true || Access::check($userId, 'core.login.admin') === true;
    }

    /**
     * Who may revoke (both with a second factor): `core.admin` every account;
     * the right to share externally only external accounts without
     * administrator sign-in.
     */
    private function mayRevoke(int $actorUserId, int $userId): bool
    {
        // Fresh permissions: a group change of either account a moment ago counts.
        Access::clearStatics();
        $actor = GrantService::user($actorUserId);

        if ($actor === null || $actor->block || !$this->services->grants()->hasSecondFactor($actorUserId)) {
            return false;
        }

        $admin = $actor->authorise('core.admin', 'com_appointments');

        // Team accounts and accounts with administrator sign-in: core.admin
        // only, as for sending their link. External accounts: also the right to
        // share externally (the recipients of the revoke mail).
        if ($admin || self::backendAccount($userId, true)) {
            return $admin;
        }

        return $this->services->grants()->audienceOf($userId) === TeamStates::AUDIENCE_EXTERNAL
            && $actor->authorise('appointments.team.external', 'com_appointments');
    }

    /**
     * Block the account through Joomla, so its user plugins react (the internet
     * calendar plugin starts the device clean-up).
     */
    private function block(int $userId): void
    {
        $user = GrantService::user($userId);

        if ($user === null || $user->block) {
            return;
        }

        $user->block = 1;

        try {
            $saved = $user->save(true);
        } catch (\Throwable $e) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_BLOCK', 500, $e);
        }

        if (!$saved) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_C_ERROR_BLOCK', 500);
        }
    }

    /**
     * After the block: start the clean-up directly as well and end the sessions.
     */
    private function endAccess(int $userId, int $actorUserId): void
    {
        try {
            $this->services->cleanup()->startForUser($userId, 'access_revoked', $actorUserId);
        } catch (\Throwable) {
            // The block alone keeps the account out; the clean-up follows with the user plugin.
        }

        foreach ([0, 1] as $client) {
            try {
                Factory::getApplication()->logout($userId, ['clientid' => $client]);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Close a link. A row whose MAC does not verify is deleted instead, so no
     * forged row ever receives a valid MAC.
     */
    private function close(object $row, ?int $actorUserId): void
    {
        if (!$this->macValid($row)) {
            $db = $this->services->db();
            $db->setQuery('DELETE FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE id = ' . (int) $row->id)->execute();

            return;
        }

        $row->state = TeamStates::INVITATION_CLOSED;
        $this->store($row, $actorUserId === null ? [] : ['revoked_by' => $actorUserId]);
    }

    /**
     * Write state and completion of a verified row with a new MAC, plus the
     * given unprotected columns.
     *
     * @param   array<string, int|string>  $extra
     */
    private function store(object $row, array $extra): void
    {
        $db = $this->services->db();
        $mac = $this->services->keys()->rowMac(self::TABLE, self::macFields($row));
        $sets = [
            'state = ' . (int) $row->state,
            'completed_utc = ' . ($row->completed_utc === null ? 'NULL' : $db->quote((string) $row->completed_utc)),
            'row_mac = ' . $this->hex($mac),
        ];

        foreach ($extra as $column => $value) {
            $sets[] = $db->quoteName($column) . ' = ' . (\is_int($value) ? $value : $db->quote($value));
        }

        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__' . self::TABLE) . ' SET ' . implode(', ', $sets) . ' WHERE id = ' . (int) $row->id
        )->execute();
    }

    /**
     * @return  object[]
     */
    private function rows(string $where, bool $forUpdate): array
    {
        $db = $this->services->db();

        return $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__' . self::TABLE) . ' WHERE ' . $where . ' ORDER BY id' . ($forUpdate ? ' FOR UPDATE' : '')
        )->loadObjectList() ?: [];
    }

    private function macValid(object $row): bool
    {
        $mac = (string) ($row->row_mac ?? '');

        return \strlen($mac) === 32 && $this->services->keys()->verifyRowMac(self::TABLE, self::macFields($row), $mac);
    }

    private function hex(string $bytes): string
    {
        return 'UNHEX(' . $this->services->db()->quote(bin2hex($bytes)) . ')';
    }

    private function key(): string
    {
        return $this->services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_INVITATION);
    }

    private function assertEnabled(): void
    {
        if (!$this->services->enabled()) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_DISABLED', 404);
        }
    }

    /**
     * @template T
     * @param   callable():T  $work
     * @return  T
     */
    private function atomic(callable $work): mixed
    {
        return GrantService::transaction($this->services, $work);
    }

    private function log(string $event, array $context): void
    {
        try {
            $this->services->audit()->log($event, $context);
        } catch (\Throwable) {
        }
    }
}
