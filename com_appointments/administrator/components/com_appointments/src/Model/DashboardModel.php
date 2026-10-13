<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;

defined('_JEXEC') or die;

/**
 * Supplies the administrator dashboard.
 *
 * @since  1.0.0
 */
class DashboardModel extends BaseDatabaseModel
{
    /**
     * The counts the dashboard shows.
     *
     * @return  array{eventtypes:int, bookings:int}
     */
    public function getCounts(): array
    {
        return [
            'eventtypes' => $this->countRows('#__appointments_eventtypes'),
            'bookings'   => $this->countRows('#__appointments_bookings'),
        ];
    }

    /**
     * @param   string  $table  The table name, with the `#__` prefix.
     */
    private function countRows(string $table): int
    {
        $db    = $this->getDatabase();
        $query = $this->createDbQuery($db)
            ->select('COUNT(*)')
            ->from($db->quoteName($table));

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * Creates a query object compatible with Joomla 5 (getQuery) and 6 (createQuery).
     */
    private function createDbQuery(DatabaseInterface $db): QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
