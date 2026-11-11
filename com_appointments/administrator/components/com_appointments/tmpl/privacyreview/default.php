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

$roleLabels = [
    'organizer' => 'COM_APPOINTMENTS_PRIVACY_REVIEW_ROLE_ORGANIZER',
    'attendee' => 'COM_APPOINTMENTS_PRIVACY_REVIEW_ROLE_ATTENDEE',
    'text' => 'COM_APPOINTMENTS_PRIVACY_REVIEW_ROLE_TEXT',
];
$actions = [
    'address' => 'COM_APPOINTMENTS_PRIVACY_REVIEW_ACTION_ADDRESS',
    'delete' => 'COM_APPOINTMENTS_PRIVACY_REVIEW_ACTION_DELETE',
    'keep' => 'COM_APPOINTMENTS_PRIVACY_REVIEW_ACTION_KEEP',
];
?>
<div id="appointments-privacy-review" class="com-appointments-privacy-review">
    <p><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_INTRO'); ?></p>
    <?php if (!$this->enabled) : ?>
        <div class="alert alert-info" role="status"><?php echo Text::_('COM_APPOINTMENTS_TEAM_DISABLED'); ?></div>
    <?php elseif ($this->rows === []) : ?>
        <div class="alert alert-info" role="status"><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_EMPTY'); ?></div>
    <?php else : ?>
        <?php if (!$this->unlocked) : ?>
            <div class="alert alert-warning" role="note"><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_LOCKED'); ?></div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-striped" id="privacy-review-table">
                <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_TITLE'); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_COL_REQUEST'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_COL_CALENDAR'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_COL_START'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_COL_TITLE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_COL_ROLE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_COL_DECISION'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->rows as $row) : ?>
                        <?php $rowId = (int) $row['id']; ?>
                        <tr>
                            <th scope="row">
                                #<?php echo (int) $row['request_id']; ?>
                                <?php if (!$row['request_found']) : ?>
                                    <div class="form-text"><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_REQUEST_GONE'); ?></div>
                                <?php endif; ?>
                            </th>
                            <td><?php echo $this->escape((string) $row['calendar'] !== '' ? (string) $row['calendar'] : '#' . (int) $row['calendar_id']); ?></td>
                            <?php if ($row['readable']) : ?>
                                <td><?php echo $this->escape((string) $row['start']); ?></td>
                                <td><?php echo $this->escape((string) $row['summary']); ?></td>
                                <td><?php echo implode(', ', array_map(static fn (string $role): string => Text::_($roleLabels[$role] ?? 'COM_APPOINTMENTS_PRIVACY_REVIEW_ROLE_TEXT'), $row['roles'])); ?></td>
                            <?php else : ?>
                                <td colspan="3"><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_UNREADABLE'); ?></td>
                            <?php endif; ?>
                            <td>
                                <form action="<?php echo Route::_('index.php?option=com_appointments&task=privacyreview.apply'); ?>" method="post">
                                    <fieldset>
                                        <legend class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_COL_DECISION'); ?></legend>
                                        <?php foreach ($actions as $value => $label) : ?>
                                            <?php $changes = $value !== 'keep'; ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="decision" id="review-<?php echo $rowId . '-' . $value; ?>" value="<?php echo $value; ?>"
                                                    <?php echo $value === 'address' && $row['writable'] ? 'checked' : ''; ?>
                                                    <?php echo $value === 'keep' && !$row['writable'] ? 'checked' : ''; ?>
                                                    <?php echo $changes && !$row['writable'] ? 'disabled' : ''; ?>>
                                                <label class="form-check-label" for="review-<?php echo $rowId . '-' . $value; ?>"><?php echo Text::_($label); ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </fieldset>
                                    <input type="hidden" name="id" value="<?php echo $rowId; ?>">
                                    <?php echo HTMLHelper::_('form.token'); ?>
                                    <button class="btn btn-primary btn-sm mt-2" type="submit"><?php echo Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_APPLY'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>