<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\DateDisplay;
use Advans\Component\Appointments\Site\Helper\BackLink;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * Confirmation page of the signed link with which an additional guest removes their own address
 * from a booking. $this->page holds: heading, completed, id, signature and, before the removal,
 * title, guest, zone, start, end (in that zone) and action (the form address).
 */
$page = $this->page;
?>
<div class="com-appointments com-appointments-manage com-appointments-guest">
    <div class="com-appointments-container">
        <h1 class="com-appointments-heading"><?php echo $this->escape($page['heading']); ?></h1>
        <?php if ($page['completed']) : ?>
            <p class="com-appointments-status"><?php echo Text::_('COM_APPOINTMENTS_GUEST_REMOVED'); ?></p>
        <?php else : ?>
            <?php
            $dates = new DateDisplay(static fn (string $key): string => Text::_($key));
            $dayFormat = Text::_('COM_APPOINTMENTS_DATE_FORMAT_DAY');
            ?>
            <h2 class="com-appointments-title"><?php echo $this->escape($page['title']); ?></h2>
            <dl class="com-appointments-summary">
                <dt><?php echo Text::_('COM_APPOINTMENTS_SUMMARY_DATE'); ?></dt>
                <dd><?php echo $this->escape($dates->format($page['start'], $dayFormat)); ?></dd>
                <dt><?php echo Text::_('COM_APPOINTMENTS_SUMMARY_TIME'); ?></dt>
                <dd><?php echo $this->escape($dates->range($page['start'], $page['end'], Text::_('COM_APPOINTMENTS_DATE_FORMAT_TIME'), $dayFormat,
                    Text::_('COM_APPOINTMENTS_TIME_WITH_OFFSET'), Text::_('COM_APPOINTMENTS_SUMMARY_TIME_RANGE'), Text::_('COM_APPOINTMENTS_DATE_TIME'))); ?></dd>
                <dt><?php echo Text::_('COM_APPOINTMENTS_SUMMARY_ZONE'); ?></dt>
                <dd><?php echo $this->escape($page['zone']->getName()); ?></dd>
                <dt><?php echo Text::_('COM_APPOINTMENTS_SUMMARY_EMAIL'); ?></dt>
                <dd><?php echo $this->escape($page['guest']); ?></dd>
            </dl>
            <div class="com-appointments-notice">
                <p><?php echo Text::_('COM_APPOINTMENTS_GUEST_EFFECT'); ?></p>
                <p><?php echo Text::_('COM_APPOINTMENTS_GUEST_CALENDAR'); ?></p>
            </div>
            <form method="post" action="<?php echo $this->escape($page['action']); ?>" class="com-appointments-form com-appointments-manage-form">
                <input type="hidden" name="id" value="<?php echo (int) $page['id']; ?>">
                <input type="hidden" name="signature" value="<?php echo $this->escape($page['signature']); ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
                <div class="com-appointments-actions">
                    <button type="submit" class="btn btn-danger"><?php echo Text::_('COM_APPOINTMENTS_GUEST_SUBMIT'); ?></button>
                </div>
            </form>
        <?php endif; ?>
        <p><a class="btn btn-secondary com-appointments-back-link" href="<?php echo BackLink::resolve(false); ?>"><?php echo Text::_($page['completed'] ? 'COM_APPOINTMENTS_BACK_TO_OVERVIEW' : 'JCANCEL'); ?></a></p>
    </div>
</div>
