<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Table;

use Advans\Component\Appointments\Administrator\Helper\BookingInput;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

defined('_JEXEC') or die;

class FieldTable extends Table
{
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__appointments_fields', 'id', $db);
    }

    public function check(): bool
    {
        $this->label = trim((string) $this->label);

        if ($this->label === '' || (int) $this->eventtype_id < 1
            || !in_array($this->type, ['text', 'textarea', 'number', 'tel', 'email', 'url', 'radio', 'checkboxes', 'checkbox', 'date'], true)) {
            $this->setError(Text::_('COM_APPOINTMENTS_FIELD_ERROR_REQUIRED'));

            return false;
        }

        // «Maximum characters»: 0 keeps the type's limit, otherwise 1 up to that limit. Only text
        // fields have one; for every other type the value is reset to 0.
        $limit = BookingInput::TEXT_LENGTH[$this->type] ?? 0;
        $maxLength = filter_var($this->max_length ?? 0, FILTER_VALIDATE_INT);

        if ($limit > 0 && ($maxLength === false || $maxLength < 0 || $maxLength > $limit)) {
            $this->setError(Text::sprintf('COM_APPOINTMENTS_FIELD_ERROR_MAX_LENGTH', $limit));

            return false;
        }

        $this->max_length = $limit > 0 ? $maxLength : 0;

        $options = json_decode((string) $this->options, true);

        if (!is_array($options) || !array_is_list($options)
            || count(array_filter($options, static fn ($value) => !is_string($value) || trim($value) === '')) > 0
            || (in_array($this->type, ['radio', 'checkboxes'], true) && $options === [])) {
            $this->setError(Text::_('COM_APPOINTMENTS_FIELD_ERROR_OPTIONS'));

            return false;
        }

        return true;
    }
}
