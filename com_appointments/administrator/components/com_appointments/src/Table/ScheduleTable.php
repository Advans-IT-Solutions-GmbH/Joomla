<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Table;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

defined('_JEXEC') or die;

/**
 * Table for a named weekly schedule.
 *
 * @since  1.0.0
 */
class ScheduleTable extends Table
{
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__appointments_schedules', 'id', $db);
    }

    public function check(): bool
    {
        $this->title = trim((string) $this->title);

        if ($this->title === '') {
            $this->setError(Text::_('COM_APPOINTMENTS_SCHEDULE_ERROR_NO_TITLE'));

            return false;
        }

        // The time zone decides what "Tuesday 14:00" means for every event type
        // that uses this schedule, so an unusable value must never reach the
        // slot engine. Only IANA identifiers are accepted: an abbreviation such
        // as "CET" would be accepted by DateTimeZone but carries no daylight
        // saving rules, and the two days a year on which the clocks change
        // would then be computed wrongly for every booking.
        $this->timezone = trim((string) $this->timezone);

        if (!\in_array($this->timezone, \DateTimeZone::listIdentifiers(), true)) {
            $this->setError(Text::sprintf('COM_APPOINTMENTS_SCHEDULE_ERROR_TIMEZONE', $this->timezone));

            return false;
        }

        if ((int) $this->owner_user_id <= 0) {
            $this->owner_user_id = (int) Factory::getApplication()->getIdentity()->id;
        }

        return true;
    }

    public function store($updateNulls = false): bool
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        if (!$this->id) {
            $this->created_utc = $now;
        }

        $this->modified_utc = $now;

        return parent::store($updateNulls);
    }
}
