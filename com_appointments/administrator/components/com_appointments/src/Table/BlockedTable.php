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

        $start = $this->toUtc((string) $this->start_utc);
        $end   = $this->toUtc((string) $this->end_utc);

        if ($start === null || $end === null) {
            $this->setError(Text::_('COM_APPOINTMENTS_BLOCKED_ERROR_NO_PERIOD'));

            return false;
        }

        if ($start >= $end) {
            $this->setError(Text::_('COM_APPOINTMENTS_BLOCKED_ERROR_ORDER'));

            return false;
        }

        $this->start_utc = $start->format('Y-m-d H:i:s');
        $this->end_utc   = $end->format('Y-m-d H:i:s');

        return true;
    }

    public function store($updateNulls = false): bool
    {
        if (!$this->id) {
            $this->created_utc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        }

        return parent::store($updateNulls);
    }

    /**
     * Read a stored or submitted timestamp as UTC.
     *
     * The form field hands over UTC already, so this only has to reject what is
     * not a timestamp at all, including the zero date a database can hold.
     */
    private function toUtc(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }
    }
}
