<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Table;

use Advans\Component\Appointments\Administrator\Helper\SlotRules;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Table\Asset;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\Database\DatabaseDriver;

defined('_JEXEC') or die;

class EventtypeTable extends Table
{
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__appointments_eventtypes', 'id', $db);
    }

    public function bind($src, $ignore = [])
    {
        if (isset($src['rules']) && is_array($src['rules'])) {
            $this->setRules($src['rules']);
        }

        return parent::bind($src, $ignore);
    }

    protected function _getAssetName()
    {
        return 'com_appointments.eventtype.' . (int) $this->id;
    }

    protected function _getAssetTitle()
    {
        return $this->title;
    }

    protected function _getAssetParentId(Table $table = null, $id = null)
    {
        $asset = new Asset($this->getDatabase());
        $asset->loadByName('com_appointments');

        return (int) ($asset->id ?: 1);
    }

    public function check(): bool
    {
        $this->title = trim((string) $this->title);
        $this->alias = OutputFilter::stringURLSafe(trim((string) $this->alias) ?: $this->title);

        if ($this->title === '' || $this->alias === '' || (int) $this->host_user_id < 1 || (int) $this->schedule_id < 1) {
            $this->setError(Text::_('COM_APPOINTMENTS_EVENTTYPE_ERROR_REQUIRED'));

            return false;
        }

        foreach (['horizon_start_utc', 'horizon_end_utc'] as $column) {
            if ($this->$column === '') {
                $this->$column = null;
            }

            if ($this->$column !== null && UtcTimestamp::parse((string) $this->$column) === null) {
                $this->setError(Text::_('COM_APPOINTMENTS_EVENTTYPE_ERROR_RULES'));

                return false;
            }
        }

        try {
            SlotRules::fromEventType($this->getProperties());
        } catch (\InvalidArgumentException $e) {
            $this->setError(Text::_('COM_APPOINTMENTS_EVENTTYPE_ERROR_RULES'));

            return false;
        }

        foreach (['duration_minutes', 'slot_step_minutes', 'buffer_before_minutes', 'buffer_after_minutes',
            'horizon_days', 'max_per_day', 'max_per_week', 'retention_days'] as $column) {
            if ($this->$column !== null && ((int) $this->$column < 0 || (int) $this->$column > 65535)) {
                $this->setError(Text::_('COM_APPOINTMENTS_EVENTTYPE_ERROR_RULES'));

                return false;
            }
        }

        if ((int) $this->min_notice_minutes < 0 || (int) $this->min_notice_minutes > 4294967295) {
            $this->setError(Text::_('COM_APPOINTMENTS_EVENTTYPE_ERROR_RULES'));

            return false;
        }

        if (!in_array($this->location_type, ['phone_host_calls', 'phone_invitee_calls', 'in_person', 'custom_text', 'conference'], true)
            || ($this->colour !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/D', (string) $this->colour))) {
            $this->setError(Text::_('COM_APPOINTMENTS_EVENTTYPE_ERROR_LOCATION'));

            return false;
        }

        if (!$this->id) {
            $this->feed_token = bin2hex(random_bytes(32));
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

        if (!$this->id) {
            return parent::store(true);
        }

        // Exclude the credential from metadata UPDATEs, rather than reloading a
        // value that a concurrent rotation can replace before the UPDATE runs.
        $credential = $this->feed_token;
        unset($this->feed_token);
        try {
            return parent::store(true);
        } finally {
            $this->feed_token = $credential;
        }
    }
}
