<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Field;

use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;

defined('_JEXEC') or die;

/**
 * The events of the internet calendar log, for its event filter. The log shows the event names
 * as they are stored, so the filter lists the same names.
 *
 * @since  1.6.0
 */
class AuditeventField extends ListField
{
    protected $type = 'Auditevent';

    protected function getOptions()
    {
        $options = parent::getOptions();

        foreach (AuditEvent::all() as $event) {
            $options[] = HTMLHelper::_('select.option', (string) $event, (string) $event);
        }

        return $options;
    }
}
