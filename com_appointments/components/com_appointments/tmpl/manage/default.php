<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\DateDisplay;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Advans\Component\Appointments\Site\Helper\BackLink;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;
$headings = ['reschedule' => 'COM_APPOINTMENTS_MANAGE_RESCHEDULE', 'cancel' => 'COM_APPOINTMENTS_MANAGE_CANCEL'];
$results = ['reschedule' => 'COM_APPOINTMENTS_MANAGE_RESCHEDULED', 'cancel' => 'COM_APPOINTMENTS_MANAGE_CANCELLED'];
$dates = new DateDisplay(static fn (string $key): string => Text::_($key));
$dayFormat = Text::_('COM_APPOINTMENTS_DATE_FORMAT_DAY');
$timeFormat = Text::_('COM_APPOINTMENTS_DATE_FORMAT_TIME');
$withOffset = Text::_('COM_APPOINTMENTS_TIME_WITH_OFFSET');
$errors = $this->errors;
// Focus the first invalid control in page order, not in the order the checks ran.
$firstError = current(array_intersect(['reason', 'start', 'confirm'], array_keys($errors))) ?: null;
$invalid = static fn (string $key): string => isset($errors[$key]) ? ' is-invalid' : '';
$aria = static function (string $key) use ($errors, $firstError): string {
    return isset($errors[$key])
        ? ' aria-invalid="true" aria-describedby="manage-' . $key . '-error"' . ($key === $firstError ? ' autofocus' : '')
        : '';
};
$error = fn (string $key): string => isset($errors[$key])
    ? '<div id="manage-' . $key . '-error" class="invalid-feedback com-appointments-field-error">' . $this->escape(Text::_($errors[$key])) . '</div>'
    : '';
// Title, date, time and zone of the appointment, on the receipt of a change and on the notice of a
// cancelled appointment; the form pages add the email address.
$summary = function (string $title, string $startUtc, string $endUtc, string $email = '') use ($dates, $dayFormat, $timeFormat, $withOffset): string {
    $start = UtcTimestamp::parse($startUtc);
    $end = UtcTimestamp::parse($endUtc);

    if ($start === null || $end === null) {
        return '';
    }

    $start = $start->setTimezone($this->zone);
    $end = $end->setTimezone($this->zone);

    return '<h2 class="com-appointments-title">' . $this->escape($title) . '</h2>'
        . '<dl class="com-appointments-summary">'
        . '<dt>' . Text::_('COM_APPOINTMENTS_SUMMARY_DATE') . '</dt><dd>' . $this->escape($dates->format($start, $dayFormat)) . '</dd>'
        . '<dt>' . Text::_('COM_APPOINTMENTS_SUMMARY_TIME') . '</dt><dd>' . $this->escape($dates->range($start, $end, $timeFormat, $dayFormat,
            $withOffset, Text::_('COM_APPOINTMENTS_SUMMARY_TIME_RANGE'), Text::_('COM_APPOINTMENTS_DATE_TIME'))) . '</dd>'
        . '<dt>' . Text::_('COM_APPOINTMENTS_SUMMARY_ZONE') . '</dt><dd>' . $this->escape($this->zone->getName()) . '</dd>'
        . ($email !== '' ? '<dt>' . Text::_('COM_APPOINTMENTS_SUMMARY_EMAIL') . '</dt><dd>' . $this->escape($email) . '</dd>' : '')
        . '</dl>';
};
?>
<?php
if (!$this->completed && !$this->cancelled && $this->action === 'reschedule') {
    echo $this->loadTemplate('reschedule');

    return;
}
?>
<?php
/*
 * The completed state is the target of the redirect after a successful change
 * (#appointment-result), so the outcome is in the first screen even below template modules;
 * the status takes the focus (autofocus, or the booking script when the address has the
 * fragment, which suppresses autofocus), so a screen reader reads it out.
 */
?>
<div class="com-appointments com-appointments-manage"<?php echo $this->completed ? ' id="appointment-result"' : ''; ?>>
    <div class="com-appointments-container">
        <h1 class="com-appointments-heading"><?php echo $this->escape($this->pageHeading); ?></h1>
        <?php if ($this->completed) : ?>
            <p class="com-appointments-status" role="status" tabindex="-1" autofocus data-appointments-result<?php echo $this->notices ? ' aria-describedby="appointment-notices"' : ''; ?>><?php echo Text::_($results[$this->action]); ?></p>
            <?php if ($this->notices) : ?>
                <?php // Messages of this outcome (see ResultNoticesTrait), rendered like Joomla's system messages. ?>
                <div id="appointment-notices" class="com-appointments-notices">
                    <?php foreach ($this->notices as $notice) : ?>
                        <div class="alert alert-<?php echo $notice['type']; ?> com-appointments-alert"><?php echo $notice['message']; ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php // The appointment as changed: cancelled, or at its new time. ?>
            <?php echo $summary((string) ($this->receipt['title'] ?? ''), (string) ($this->receipt['start_utc'] ?? ''), (string) ($this->receipt['end_utc'] ?? '')); ?>
            <?php if (!empty($this->receipt['mailed'])) : ?>
                <p class="com-appointments-mail-note"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_MAIL_FOLLOWS'); ?></p>
            <?php endif; ?>
            <?php if ($this->manageLinks !== []) : ?>
                <?php // After a reschedule the earlier links no longer apply: the new ones, as on the confirmation of a booking. ?>
                <div class="com-appointments-actions com-appointments-confirmation-actions">
                    <?php if (isset($this->manageLinks['reschedule_url'])) : ?>
                        <a class="btn btn-outline-secondary com-appointments-reschedule" href="<?php echo $this->escape($this->manageLinks['reschedule_url']); ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_RESCHEDULE'); ?></a>
                    <?php endif; ?>
                    <?php if (isset($this->manageLinks['cancel_url'])) : ?>
                        <a class="btn btn-outline-secondary com-appointments-cancel" href="<?php echo $this->escape($this->manageLinks['cancel_url']); ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CANCEL'); ?></a>
                    <?php endif; ?>
                </div>
                <p class="form-text com-appointments-confirmation-note"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CONFIRMATION_NOTE'); ?></p>
            <?php endif; ?>
        <?php elseif ($this->cancelled) : ?>
            <?php // A link of a cancelled appointment: what was cancelled, no form. ?>
            <?php if ($this->item->anonymised_utc !== null) : ?>
                <?php // Erased by the cancellation: nothing of the appointment is shown. ?>
                <p class="com-appointments-status" role="status" tabindex="-1" autofocus><?php echo Text::_('COM_APPOINTMENTS_MANAGE_CANCELLED_ERASED'); ?></p>
            <?php else : ?>
                <p class="com-appointments-status" role="status" tabindex="-1" autofocus><?php echo Text::_('COM_APPOINTMENTS_MANAGE_ALREADY_CANCELLED'); ?></p>
                <?php echo $summary((string) $this->item->title, (string) $this->item->start_utc, (string) $this->item->end_utc); ?>
            <?php endif; ?>
        <?php else : ?>
            <?php echo $summary((string) $this->item->title, (string) $this->item->start_utc, (string) $this->item->end_utc, (string) $this->item->invitee_email); ?>
            <?php if ($this->action === 'cancel') : ?>
                <div class="com-appointments-notice">
                    <p><?php echo Text::_('COM_APPOINTMENTS_MANAGE_ERASURE_DATA'); ?></p>
                    <p><?php echo Text::_($this->deleteMode ? 'COM_APPOINTMENTS_MANAGE_ERASURE_DELETE' : 'COM_APPOINTMENTS_MANAGE_ERASURE_ANONYMISE'); ?></p>
                </div>
                <form method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=booking.manage'); ?>" class="com-appointments-form com-appointments-manage-form">
                    <input type="hidden" name="id" value="<?php echo (int) $this->item->id; ?>">
                    <input type="hidden" name="action" value="<?php echo $this->escape($this->action); ?>">
                    <input type="hidden" name="signature" value="<?php echo $this->escape($this->signature); ?>">
                    <div class="com-appointments-field">
                        <label class="form-label" for="cancel-reason"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_REASON'); ?></label>
                        <textarea class="form-control<?php echo $invalid('reason'); ?>" id="cancel-reason" name="reason" maxlength="2000"<?php echo $aria('reason'); ?>><?php echo $this->escape($this->reason); ?></textarea>
                        <?php echo $error('reason'); ?>
                    </div>
                    <div class="com-appointments-field">
                        <div class="form-check com-appointments-confirm">
                            <input class="form-check-input<?php echo $invalid('confirm'); ?>" id="manage-confirm" type="checkbox" name="confirm" value="1" required<?php echo $aria('confirm'); ?>>
                            <label class="form-check-label" for="manage-confirm"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_CONFIRM'); ?></label>
                            <?php echo $error('confirm'); ?>
                        </div>
                    </div>
                    <?php echo HTMLHelper::_('form.token'); ?>
                    <div class="com-appointments-actions">
                        <button type="submit" class="btn btn-danger"><?php echo Text::_($headings[$this->action]); ?></button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($this->bookAgain !== '') : ?>
            <?php // After a cancellation: a new booking of the same event type, in place of the way back to that page. ?>
            <p><a class="btn btn-primary com-appointments-book-again" href="<?php echo $this->bookAgain; ?>"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_BOOK_AGAIN'); ?></a></p>
        <?php else : ?>
            <p><a class="btn btn-secondary com-appointments-return" href="<?php echo BackLink::resolve(false); ?>"><?php echo Text::_($this->completed || $this->cancelled ? 'COM_APPOINTMENTS_BACK_TO_OVERVIEW' : 'JCANCEL'); ?></a></p>
        <?php endif; ?>
    </div>
</div>
