<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

$canEdit = Factory::getApplication()->getIdentity()->authorise('core.edit', 'com_appointments');

/**
 * Stored UTC shown in the reader's own time zone. A blocked period is an
 * instant, so showing the raw UTC value would be wrong for everyone who is not
 * in UTC.
 *
 * The time-zone argument is deliberately omitted: Joomla's date helper reads
 * the value as UTC and converts it to the reader's configured zone by default,
 * while a string such as 'UTC' would select that zone as the target and display
 * universal time to everybody.
 */
$asLocal = static function (string $utc): string {
    return HTMLHelper::_('date', $utc, Text::_('DATE_FORMAT_LC5'));
};

/**
 * The internal note of a blocked period, escaped, or the stand-in when there
 * is none.
 *
 * The comparison is against the empty string on purpose. `?:` would read a note
 * of "0" as false and replace a note somebody wrote with "without a note",
 * because that is how PHP reads the string "0".
 */
$noteOf = function ($title): string {
    $title = (string) $title;

    return $title === '' ? Text::_('COM_APPOINTMENTS_BLOCKED_UNTITLED') : $this->escape($title);
};
?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=blockeds'); ?>" method="post"
      name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php if ($this->items === []) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span>
                        <span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                        <?php echo Text::_('COM_APPOINTMENTS_BLOCKEDS_EMPTY'); ?>
                    </div>
                <?php else : ?>
                    <table class="table" id="blockedList">
                        <caption class="visually-hidden">
                            <?php echo Text::_('COM_APPOINTMENTS_BLOCKEDS_TITLE'); ?>
                        </caption>
                        <thead>
                            <tr>
                                <td class="w-1 text-center">
                                    <?php echo HTMLHelper::_('grid.checkall'); ?>
                                </td>
                                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_BLOCKED_FIELD_TITLE'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_BLOCKED_FIELD_USER'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_BLOCKED_FIELD_START'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_BLOCKED_FIELD_END'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->items as $i => $item) : ?>
                                <tr>
                                    <td class="text-center">
                                        <?php echo HTMLHelper::_('grid.id', $i, (int) $item->id); ?>
                                    </td>
                                    <td>
                                        <?php if ($canEdit) : ?>
                                            <a href="<?php echo Route::_('index.php?option=com_appointments&task=blocked.edit&id=' . (int) $item->id); ?>">
                                                <?php echo $noteOf($item->title); ?>
                                            </a>
                                        <?php else : ?>
                                            <?php echo $noteOf($item->title); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $this->escape((string) $item->user_name); ?></td>
                                    <td><?php echo $asLocal((string) $item->start_utc); ?></td>
                                    <td><?php echo $asLocal((string) $item->end_utc); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php echo $this->pagination->getListFooter(); ?>
                <?php endif; ?>

                <input type="hidden" name="task" value="">
                <input type="hidden" name="boxchecked" value="0">
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>
