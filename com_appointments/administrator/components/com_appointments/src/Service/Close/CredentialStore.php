<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Close;

use Advans\Component\Appointments\Administrator\Helper\CloseKeyCipher;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * The Close API key of each event type (booking page), sealed with the site secret.
 *
 * Only this class reads or writes the table. It never returns, logs or puts the key into an
 * exception message; the status for the form carries at most the last four characters. A value that
 * cannot be opened (for example after a change of the site secret) reads as "no key", so the profile's
 * environment variable applies again until the key is entered anew.
 */
final class CredentialStore
{
    public const TABLE = '#__appointments_close_credentials';

    private ?CloseKeyCipher $cipher = null;

    public function __construct(private DatabaseInterface $db, ?string $siteSecret = null)
    {
        try {
            $this->cipher = new CloseKeyCipher($siteSecret ?? (string) Factory::getApplication()->get('secret', ''));
        } catch (\InvalidArgumentException $e) {
            // No site secret: nothing can be sealed or opened, every stored value reads as unreadable.
            $this->cipher = null;
        }
    }

    /** The usable key of the event type, or null for "use the profile's variable". */
    public function key(int $eventType): ?string
    {
        $sealed = $this->sealed($eventType);

        return $sealed === null || $this->cipher === null ? null : $this->cipher->open($eventType, $sealed);
    }

    /** @return array{stored: bool, readable: bool, hint: string} */
    public function status(int $eventType): array
    {
        $sealed = $this->sealed($eventType);
        $key = $sealed === null || $this->cipher === null ? null : $this->cipher->open($eventType, $sealed);

        return ['stored' => $sealed !== null, 'readable' => $key !== null, 'hint' => $key === null ? '' : CloseKeyCipher::hint($key)];
    }

    /**
     * Stores or replaces the key. An empty or unusable value is refused and the stored key is kept.
     *
     * @throws \InvalidArgumentException When the value is unusable. The message never contains it.
     * @throws \RuntimeException         When the site has no secret to seal it with.
     */
    public function put(int $eventType, string $apiKey, int $user): void
    {
        if ($eventType < 1 || !CloseKeyCipher::valid($apiKey)) {
            throw new \InvalidArgumentException('The Close API key is not usable.');
        }

        if ($this->cipher === null) {
            throw new \RuntimeException('The site secret is not configured.');
        }

        $db = $this->db;
        $sealed = $this->cipher->seal($eventType, $apiKey);
        $db->transactionStart();

        try {
            // Parent before child, the lock order of EventtypeModel::delete(): a deletion that finished after the
            // event type was saved must not leave a key behind that no form can remove any more.
            $exists = $db->setQuery('SELECT id FROM ' . $db->quoteName('#__appointments_eventtypes') . ' WHERE id = ' . $eventType
                . ' FOR UPDATE')->loadResult();

            if ($exists === null || $exists === false) {
                throw new \RuntimeException('The event type no longer exists.');
            }

            $db->setQuery('REPLACE INTO ' . $db->quoteName(self::TABLE) . ' (eventtype_id, secret, modified_utc, modified_by) VALUES ('
                . $eventType . ', ' . $db->quote($sealed) . ', ' . $db->quote(gmdate('Y-m-d H:i:s')) . ', ' . max(0, $user) . ')')->execute();
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }
    }

    public function delete(int $eventType): void
    {
        $this->deleteMany([$eventType]);
    }

    /** @param int[] $eventTypes */
    public function deleteMany(array $eventTypes): void
    {
        $ids = array_values(array_filter(array_map('intval', $eventTypes), static fn (int $id): bool => $id > 0));

        if ($ids !== []) {
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName(self::TABLE) . ' WHERE eventtype_id IN (' . implode(',', $ids) . ')')
                ->execute();
        }
    }

    private function sealed(int $eventType): ?string
    {
        if ($eventType < 1) {
            return null;
        }

        $value = $this->db->setQuery('SELECT secret FROM ' . $this->db->quoteName(self::TABLE) . ' WHERE eventtype_id = ' . $eventType)
            ->loadResult();

        return is_string($value) && $value !== '' ? $value : null;
    }
}
