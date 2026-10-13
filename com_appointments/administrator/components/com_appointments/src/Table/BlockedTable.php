<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Table;

use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

defined('_JEXEC') or die;

/**
 * Table for a blocked period: time a host is not available, independent of any
 * event type.
 *
 * @since  1.0.0
 */
class BlockedTable extends Table
{
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__appointments_blocked', 'id', $db);
    }

    public function check(): bool
    {
        $this->title = trim((string) $this->title);

        if ((int) $this->user_id <= 0) {
            $this->user_id = (int) Factory::getApplication()->getIdentity()->id;
        }

        // Strictly, through the shared helper: an impossible date such as
        // 2026-02-31 and relative text such as "tomorrow" are refused rather
        // than silently turned into some other instant.
        $start = UtcTimestamp::parse((string) $this->start_utc);
        $end   = UtcTimestamp::parse((string) $this->end_utc);

        if ($start === null || $end === null) {
            $this->setError(Text::_('COM_APPOINTMENTS_BLOCKED_ERROR_NO_PERIOD'));

            return false;
        }

        if ($start >= $end) {
            $this->setError(Text::_('COM_APPOINTMENTS_BLOCKED_ERROR_ORDER'));

            return false;
        }

        $this->start_utc = UtcTimestamp::toSql($start);
        $this->end_utc   = UtcTimestamp::toSql($end);

        return true;
    }

    public function store($updateNulls = false): bool
    {
        if (!$this->id) {
            $this->created_utc = UtcTimestamp::toSql(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        }

        return parent::store($updateNulls);
    }
}
