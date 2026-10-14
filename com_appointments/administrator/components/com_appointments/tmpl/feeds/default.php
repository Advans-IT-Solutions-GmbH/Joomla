<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
?>
<div class="alert alert-warning" role="alert">
    <?php echo Text::_('COM_APPOINTMENTS_FEEDS_PASSWORD_WARNING'); ?>
</div>
<p><?php echo Text::_('COM_APPOINTMENTS_FEEDS_READ_ONLY'); ?></p>
<table class="table">
    <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_FEEDS_TITLE'); ?></caption>
    <thead>
        <tr>
            <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_FEEDS_SCOPE'); ?></th>
            <th scope="col"><?php echo Text::_('JGLOBAL_TITLE'); ?></th>
            <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_FEEDS_URL'); ?></th>
            <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_FEEDS_ROTATE'); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($this->items as $item) : ?>
        <tr>
            <td><?php echo $item['scope'] === 'event' ? Text::_('COM_APPOINTMENTS_FEEDS_EVENT') : Text::_('COM_APPOINTMENTS_FEEDS_HOST'); ?></td>
            <th scope="row"><?php echo $this->escape($item['title']); ?></th>
            <td>
                <label class="visually-hidden" for="feed-<?php echo $item['scope'] . '-' . $item['id']; ?>"><?php echo Text::_('COM_APPOINTMENTS_FEEDS_URL'); ?></label>
                <input type="text" class="form-control" readonly autocomplete="off" spellcheck="false"
                    id="feed-<?php echo $item['scope'] . '-' . $item['id']; ?>" value="<?php echo $this->escape($item['url']); ?>">
            </td>
            <td>
                <form action="<?php echo Route::_('index.php?option=com_appointments'); ?>" method="post">
                    <input type="hidden" name="task" value="feeds.rotate">
                    <input type="hidden" name="scope" value="<?php echo $item['scope']; ?>">
                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                    <label>
                        <input type="checkbox" name="confirm" value="1" required>
                        <?php echo Text::_('COM_APPOINTMENTS_FEEDS_ROTATE_CONFIRM'); ?>
                    </label>
                    <button type="submit" class="btn btn-warning"><?php echo Text::_('COM_APPOINTMENTS_FEEDS_ROTATE'); ?></button>
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
