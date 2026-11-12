<?php
/**
 * @package     Privacy Requests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\PrivacyRequests\Service;

use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/** Connection-scoped: cannot be silently released by a callback's transaction. */
final class OperationLock
{
    private string $key;
    private bool $postgres;

    public function __construct(private DatabaseInterface $db, int $id, string $secret)
    {
        $this->postgres = strtolower($db->getServerType()) === 'postgresql';
        $hash = hash_hmac('sha256', 'privacy-operation:' . $id, $secret);
        $this->key = $this->postgres ? (string) hexdec(substr($hash, 0, 15)) : $db->quote($hash);
        $sql = $this->postgres ? "SELECT pg_try_advisory_lock($this->key)" : "SELECT GET_LOCK($this->key, 0)";
        $result = $db->setQuery($sql)->loadResult();
        if (!in_array($result, [1, '1', true, 't'], true)) {
            throw new \RuntimeException('Another privacy operation is still running.');
        }
    }

    public function release(): void
    {
        $sql = $this->postgres ? "SELECT pg_advisory_unlock($this->key)" : "SELECT RELEASE_LOCK($this->key)";
        $result = $this->db->setQuery($sql)->loadResult();
        if (!in_array($result, [1, '1', true, 't'], true)) {
            throw new \RuntimeException('The privacy operation lock could not be released.');
        }
    }
}
