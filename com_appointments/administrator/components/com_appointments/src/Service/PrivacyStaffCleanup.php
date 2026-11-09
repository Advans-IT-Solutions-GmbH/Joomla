<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/** Minimal authenticated staff scope, captured before Core mutates the shared User. */
final class PrivacyStaffCleanup
{
    private ?string $observedPayload = null;
    private ?string $attempt = null;

    public function __construct(private DatabaseInterface $db, private KeyProvider $keys, private int $requestId)
    {
        if ($requestId < 1) {
            throw new \InvalidArgumentException('A Core request is required for staff cleanup provenance.');
        }
    }

    public function remember(string $email, ?User $user): void
    {
        $current = $this->read();
        $userId = (int) ($user->id ?? 0);
        $address = hash_hmac('sha256', mb_strtolower(trim($email), 'UTF-8'), $this->keys->key('privacy-staff-address'));
        if ($userId > 0 && !PrivacyAddress::matches((string) $user->email, $email)) {
            throw new \UnexpectedValueException('The account does not match the Core request address.');
        }
        if ($current === null) {
            $previous = $this->db->setQuery('SELECT complete FROM ' . $this->db->quoteName('#__appointments_privacy_outcomes')
                . ' WHERE request_id = ' . $this->requestId)->loadResult();
            if ($previous !== null && $userId === 0) {
                // An older interrupted request may already have lost its original account.
                throw new \UnexpectedValueException('The original staff scope is unavailable; operator recovery is required.');
            }
            $payload = $this->seal(['user_id' => $userId, 'address' => $address, 'complete' => !$this->hasRows($userId)]);
            $this->db->setQuery('INSERT IGNORE INTO ' . $this->db->quoteName('#__appointments_privacy_staff')
                . ' (request_id, payload) VALUES (' . $this->requestId . ', ' . $this->db->quote($payload) . ')')->execute();
            $current = $this->read();
        }
        // Validate the winner of a concurrent first binding; never replace it.
        if ($current === null || !hash_equals($current['address'], $address)
            || ($userId > 0 && $current['user_id'] !== $userId)) {
            throw new \UnexpectedValueException('The Core request no longer matches its captured staff scope.');
        }
    }

    public function userId(): int
    {
        $scope = $this->read();
        if ($scope === null) {
            throw new \UnexpectedValueException('Staff scope was not captured before Core removal.');
        }
        return $scope['user_id'];
    }

    public function finish(): void
    {
        $scope = $this->read();
        if ($scope === null) {
            throw new \UnexpectedValueException('Staff scope is missing.');
        }
        if ($this->hasRows($scope['user_id'])) {
            throw new \RuntimeException('Staff cleanup left outstanding access records or calendar ownership.');
        }
        $scope['complete'] = true;
        $this->write($scope);
    }

    public function beginAttempt(): void
    {
        $scope = $this->read();
        if ($scope === null) {
            throw new \UnexpectedValueException('Removal scope is missing.');
        }
        $this->attempt = bin2hex(random_bytes(16));
        $scope['attempt'] = $this->attempt;
        $scope['attempt_pending'] = true;
        $this->write($scope);
    }

    public function finishAttempt(): void
    {
        $scope = $this->read();
        if ($scope === null || $this->attempt === null || $scope['attempt'] !== $this->attempt
            || !$scope['complete'] || $this->hasRows($scope['user_id'])) {
            throw new \RuntimeException('The removal attempt is no longer current or staff cleanup remains incomplete.');
        }
        $scope['attempt_pending'] = false;
        $this->write($scope);
    }

    public function staffPending(): bool
    {
        $scope = $this->read();
        if ($scope === null) {
            throw new \UnexpectedValueException('Staff cleanup scope is missing.');
        }
        return !$scope['complete'] || $this->hasRows($scope['user_id']);
    }

    private function write(array $scope): void
    {
        if ($this->observedPayload === null) {
            throw new \LogicException('An authenticated cleanup scope must be read before updating it.');
        }
        $this->db->setQuery('UPDATE ' . $this->db->quoteName('#__appointments_privacy_staff')
            . ' SET payload = ' . $this->db->quote($this->seal($scope))
            . ' WHERE request_id = ' . $this->requestId . ' AND BINARY payload = '
            . $this->db->quote($this->observedPayload))->execute();
        if ((int) $this->db->getAffectedRows() !== 1) {
            throw new \RuntimeException('The cleanup scope changed concurrently; a new removal attempt is required.');
        }
    }

    private function hasRows(int $userId): bool
    {
        if ($userId === 0) {
            return false;
        }
        foreach (['user_keys', 'devices', 'calendar_key_wraps', 'calendar_grants', 'invitations'] as $name) {
            if ((int) $this->db->setQuery('SELECT COUNT(*) FROM '
                . $this->db->quoteName('#__appointments_' . $name) . ' WHERE user_id = ' . $userId)->loadResult() > 0) {
                return true;
            }
        }
        return (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName('#__appointments_calendars')
            . ' WHERE owner_user_id = ' . $userId)->loadResult() > 0;
    }

    public static function pending(DatabaseInterface $db, int $requestId, bool $required = false): bool
    {
        $service = new self($db, new KeyProvider((string) Factory::getApplication()->get('secret')), $requestId);
        $scope = $service->read();
        return $scope === null ? $required
            : $scope['attempt_pending'] || !$scope['complete'] || $service->hasRows($scope['user_id']);
    }

    private function read(): ?array
    {
        $payload = $this->db->setQuery('SELECT payload FROM ' . $this->db->quoteName('#__appointments_privacy_staff')
            . ' WHERE request_id = ' . $this->requestId)->loadResult();
        if ($payload === null) {
            $this->observedPayload = null;
            return null;
        }
        $this->observedPayload = (string) $payload;
        $packed = base64_decode((string) $payload, true);
        $size = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $plain = $packed === false || strlen($packed) <= $size ? false
            : sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($packed, $size), $this->aad(),
                substr($packed, 0, $size), $this->keys->key('privacy-staff'));
        if ($plain === false) {
            throw new \UnexpectedValueException('Unreadable staff cleanup provenance.');
        }
        $scope = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($scope) || !is_int($scope['user_id'] ?? null) || $scope['user_id'] < 0
            || !is_string($scope['address'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $scope['address'])
            || !is_bool($scope['complete'] ?? null)
            || (array_key_exists('attempt_pending', $scope) && !is_bool($scope['attempt_pending']))
            || (array_key_exists('attempt', $scope)
                && $scope['attempt'] !== null
                && (!is_string($scope['attempt']) || !preg_match('/^[a-f0-9]{32}$/D', $scope['attempt'])))
            || (!empty($scope['attempt_pending']) && !is_string($scope['attempt'] ?? null))) {
            throw new \UnexpectedValueException('Invalid staff cleanup provenance.');
        }
        return $scope + ['attempt_pending' => false, 'attempt' => null];
    }

    private function seal(array $scope): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            json_encode($scope, JSON_THROW_ON_ERROR), $this->aad(), $nonce, $this->keys->key('privacy-staff')));
    }

    private function aad(): string
    {
        return 'appointments-privacy-staff:' . $this->requestId;
    }
}
