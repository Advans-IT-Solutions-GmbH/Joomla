<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\Language\Text;
defined('_JEXEC') or die;
?>
<p><?php echo Text::_('COM_APPOINTMENTS_SYSTEM_DESC'); ?></p>
<table class="table">
    <caption><?php echo Text::_('COM_APPOINTMENTS_SYSTEM_TITLE'); ?></caption>
    <thead><tr><th scope="col"><?php echo Text::_('COM_APPOINTMENTS_SYSTEM_CHECK'); ?></th><th scope="col"><?php echo Text::_('JSTATUS'); ?></th><th scope="col"><?php echo Text::_('COM_APPOINTMENTS_SYSTEM_VALUE'); ?></th></tr></thead>
    <tbody>
        <?php foreach ($this->checks as $check) : ?>
            <tr><th scope="row"><?php echo Text::_($check['label']); ?></th>
                <td class="<?php echo $check['ok'] ? 'text-success' : 'text-warning'; ?>"><?php echo Text::_($check['ok'] ? 'COM_APPOINTMENTS_SYSTEM_OK' : 'COM_APPOINTMENTS_SYSTEM_ATTENTION'); ?></td>
                <td><?php echo $this->escape((string) $check['value']); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
