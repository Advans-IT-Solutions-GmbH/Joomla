<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/** Counts/flags only: no copy of the request address, content or calendar keys. */
final class PrivacyOutcome
{
    public static function record(DatabaseInterface $db, int $requestId, bool $complete): void
    {
        if ($requestId < 1) {
            return;
        }
        $pending = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__appointments_privacy_cleanup')
            . ' WHERE request_id = ' . $requestId)->loadResult();
        $value = $complete && $pending === 0 && !PrivacyStaffCleanup::pending($db, $requestId, true) ? 1 : 0;
        $sql = 'INSERT INTO ' . $db->quoteName('#__appointments_privacy_outcomes')
            . ' (request_id, complete) VALUES (' . $requestId . ', ' . $value . ')'
            . ' ON DUPLICATE KEY UPDATE complete = VALUES(complete)';
        $db->setQuery($sql)->execute();
    }

    public static function incomplete(DatabaseInterface $db, int $requestId): bool
    {
        if ($requestId < 1) {
            return false;
        }
        $outcome = (string) $db->setQuery('SELECT complete FROM ' . $db->quoteName('#__appointments_privacy_outcomes')
            . ' WHERE request_id = ' . $requestId)->loadResult();
        return $outcome === '0'
            || PrivacyStaffCleanup::pending($db, $requestId, $outcome !== '')
            || (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__appointments_privacy_cleanup')
                . ' WHERE request_id = ' . $requestId)->loadResult() > 0;
    }
}
