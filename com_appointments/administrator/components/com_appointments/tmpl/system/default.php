<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;
?>
<p><?php echo Text::_('COM_APPOINTMENTS_SYSTEM_DESC'); ?></p>
<p><a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_appointments&view=system&probe=1'); ?>"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_SYSTEM_PROBE_RUN'); ?></a></p>
<table class="table">
    <caption><?php echo Text::_('COM_APPOINTMENTS_SYSTEM_TITLE'); ?></caption>
    <thead><tr><th scope="col"><?php echo Text::_('COM_APPOINTMENTS_SYSTEM_CHECK'); ?></th><th scope="col"><?php echo Text::_('JSTATUS'); ?></th><th scope="col"><?php echo Text::_('COM_APPOINTMENTS_SYSTEM_VALUE'); ?></th></tr></thead>
    <tbody>
        <?php foreach ($this->checks as $check) : ?>
            <tr><th scope="row"><?php echo Text::_($check['label']); ?></th>
                <td class="<?php echo $check['ok'] ? 'text-success' : 'text-warning'; ?>"><?php echo Text::_($check['ok'] ? 'COM_APPOINTMENTS_SYSTEM_OK' : 'COM_APPOINTMENTS_SYSTEM_ATTENTION'); ?></td>
                <td><?php echo $this->escape((string) $check['value']); ?>
                    <?php if (!$check['ok'] && !empty($check['remedy'])) : ?>
                        <br><small><?php echo Text::_($check['remedy']); ?></small>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>