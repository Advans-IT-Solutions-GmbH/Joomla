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
$headings = ['reschedule' => 'COM_APPOINTMENTS_MANAGE_RESCHEDULE', 'cancel' => 'COM_APPOINTMENTS_MANAGE_CANCEL', 'erase' => 'COM_APPOINTMENTS_MANAGE_ERASE'];
$results = ['reschedule' => 'COM_APPOINTMENTS_MANAGE_RESCHEDULED', 'cancel' => 'COM_APPOINTMENTS_MANAGE_CANCELLED', 'erase' => 'COM_APPOINTMENTS_MANAGE_ERASED'];
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
$month = is_string($this->month) ? $this->month : '';
$monthStart = $month !== '' ? new DateTimeImmutable($month . '-01 00:00:00', $this->zone)
    : new DateTimeImmutable('first day of this month 00:00:00', $this->zone);
$days = $this->days;
$cells = $days === [] ? [] : array_merge(
    array_fill(0, (int) (new DateTimeImmutable($days[0], new DateTimeZone('UTC')))->format('N') - 1, null),
    $days
);
$cells = $cells === [] ? [] : array_merge($cells, array_fill(0, 42 - count($cells), null));
$calendarUrl = function (array $changes = []): string {
    $query = [
        'option' => 'com_appointments',
        'view' => 'manage',
        'id' => (int) $this->item->id,
        'action' => (string) $this->action,
        'signature' => (string) $this->signature,
        'month' => (string) $this->month,
        'timezone' => $this->zone->getName(),
    ];

    if ($this->itemId > 0) {
        $query['Itemid'] = $this->itemId;
    }

    return Route::_('index.php?' . http_build_query(array_merge($query, $changes), '', '&', PHP_QUERY_RFC3986), false);
};
$chevron = static fn (string $path): string => '<svg class="com-appointments-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path d="' . $path . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
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
            <p class="com-appointments-status" role="status" tabindex="-1" autofocus><?php echo Text::_('COM_APPOINTMENTS_MANAGE_ALREADY_CANCELLED'); ?></p>
            <?php echo $summary((string) $this->item->title, (string) $this->item->start_utc, (string) $this->item->end_utc); ?>
        <?php else : ?>
            <?php if ($this->action === 'reschedule') : ?>
                <p class="form-text com-appointments-current-label"><strong><?php echo Text::_('COM_APPOINTMENTS_MANAGE_CURRENT_APPOINTMENT'); ?></strong></p>
            <?php endif; ?>
            <?php echo $summary((string) $this->item->title, (string) $this->item->start_utc, (string) $this->item->end_utc, (string) $this->item->invitee_email); ?>
            <?php if ($this->action === 'erase') : ?>
                <div class="com-appointments-notice">
                    <p><?php echo Text::_('COM_APPOINTMENTS_MANAGE_ERASURE_DATA'); ?></p>
                    <p><?php echo Text::_($this->deleteMode ? 'COM_APPOINTMENTS_MANAGE_ERASURE_DELETE' : 'COM_APPOINTMENTS_MANAGE_ERASURE_ANONYMISE'); ?></p>
                    <p><?php echo Text::_('COM_APPOINTMENTS_MANAGE_ERASURE_FUTURE'); ?></p>
                </div>
            <?php endif; ?>
            <?php if ($this->action === 'reschedule') : ?>
                <form class="com-appointments-zone" method="get" action="<?php echo Route::_('index.php'); ?>">
                    <input type="hidden" name="option" value="com_appointments">
                    <input type="hidden" name="view" value="manage">
                    <input type="hidden" name="id" value="<?php echo (int) $this->item->id; ?>">
                    <input type="hidden" name="action" value="<?php echo $this->escape($this->action); ?>">
                    <input type="hidden" name="signature" value="<?php echo $this->escape($this->signature); ?>">
                    <?php if ($this->itemId > 0) : ?>
                        <?php // A GET form drops the query of its action: the frame of the page stays with this field. ?>
                        <input type="hidden" name="Itemid" value="<?php echo $this->itemId; ?>">
                    <?php endif; ?>
                    <input type="hidden" name="month" value="<?php echo $this->escape($month); ?>">
                    <?php if ($this->dayChosen) : ?>
                        <input type="hidden" name="day" value="<?php echo $this->escape($this->day); ?>">
                    <?php endif; ?>
                    <label class="form-label" for="manage-zone"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_ZONE'); ?></label>
                    <div class="com-appointments-zone-row">
                        <select class="form-select" id="manage-zone" name="timezone" aria-describedby="manage-zone-note">
                            <?php foreach (DateTimeZone::listIdentifiers() as $zone) : ?>
                                <option value="<?php echo $this->escape($zone); ?>"<?php echo $zone === $this->zone->getName() ? ' selected' : ''; ?>><?php echo $this->escape($zone); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-secondary com-appointments-zone-submit"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_SHOW_TIMES'); ?></button>
                    </div>
                    <p id="manage-zone-note" class="form-text com-appointments-zone-note"><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_ZONE_NOTE', $this->escape($this->zone->getName())); ?></p>
                </form>

                <div id="manage-picker" class="com-appointments-picker<?php echo $this->dayChosen ? ' com-appointments-picker-day' : ''; ?>">
                    <div class="com-appointments-picker-grid">
                        <section class="com-appointments-calendar" aria-labelledby="manage-calendar-title">
                            <nav class="com-appointments-calendar-nav" aria-label="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_MONTH'); ?>">
                                <?php if ($this->isFirstMonth) : ?>
                                    <span class="btn btn-outline-secondary com-appointments-calendar-prev disabled" aria-hidden="true"><?php echo $chevron('M10 3 5 8l5 5'); ?></span>
                                <?php else : ?>
                                    <a class="btn btn-outline-secondary com-appointments-calendar-prev" href="<?php echo $this->escape($calendarUrl(['month' => $monthStart->modify('-1 month')->format('Y-m')])); ?>" aria-label="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_PREVIOUS'); ?>" title="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_PREVIOUS'); ?>"><?php echo $chevron('M10 3 5 8l5 5'); ?></a>
                                <?php endif; ?>
                                <h3 id="manage-calendar-title" class="com-appointments-calendar-title"><?php echo $this->escape($dates->format($monthStart, Text::_('COM_APPOINTMENTS_DATE_FORMAT_MONTH'))); ?></h3>
                                <a class="btn btn-outline-secondary com-appointments-calendar-next" href="<?php echo $this->escape($calendarUrl(['month' => $monthStart->modify('+1 month')->format('Y-m')])); ?>" aria-label="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_NEXT'); ?>" title="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_NEXT'); ?>"><?php echo $chevron('m6 3 5 5-5 5'); ?></a>
                            </nav>
                            <table class="com-appointments-calendar-grid">
                                <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CHOOSE_DAY'); ?></caption>
                                <thead>
                                    <tr>
                                        <?php foreach ($dates->weekdays() as $weekday) : ?>
                                            <th scope="col" class="com-appointments-weekday"><abbr title="<?php echo $this->escape($weekday['full']); ?>"><?php echo $this->escape($weekday['short']); ?></abbr></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_chunk($cells, 7) as $week) : ?>
                                        <tr>
                                            <?php foreach ($week as $date) : ?>
                                                <?php if ($date === null) : ?>
                                                    <td class="com-appointments-day-cell com-appointments-day-empty"></td>
                                                <?php else : ?>
                                                    <?php
                                                    $label = $dates->format(new DateTimeImmutable($date, new DateTimeZone('UTC')), $dayFormat);
                                                    $selected = $this->dayChosen && $date === $this->day;
                                                    $current = $date === $this->currentDay;
                                                    ?>
                                                    <td class="com-appointments-day-cell">
                                                        <?php if (!empty($this->slots[$date])) : ?>
                                                            <a class="com-appointments-day com-appointments-day-available<?php echo $selected ? ' com-appointments-day-selected' : ''; ?><?php echo $current ? ' com-appointments-day-current' : ''; ?>" href="<?php echo $this->escape($calendarUrl(['day' => $date])); ?>" aria-label="<?php echo $this->escape($label); ?>"<?php echo $selected ? ' aria-current="date"' : ''; ?>><?php echo (int) substr($date, 8, 2); ?></a>
                                                        <?php else : ?>
                                                            <span class="com-appointments-day com-appointments-day-unavailable<?php echo $current ? ' com-appointments-day-current' : ''; ?>"><?php echo (int) substr($date, 8, 2); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </section>

                        <?php if ($this->dayChosen) : ?>
                            <div id="manage-times" class="com-appointments-times" tabindex="-1">
                                <a class="btn btn-outline-secondary com-appointments-back com-appointments-times-back" href="<?php echo $this->escape($calendarUrl()); ?>">
                                    <?php echo $chevron('M10 3 5 8l5 5'); ?>
                                    <span><?php echo Text::_('COM_APPOINTMENTS_BOOKING_BACK_CALENDAR'); ?></span>
                                </a>
                                <?php if (empty($this->slots[$this->day])) : ?>
                                    <p class="com-appointments-empty"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NO_SLOTS'); ?></p>
                                <?php else : ?>
                                    <form method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=booking.manage'); ?>" class="com-appointments-form com-appointments-manage-form">
                                        <input type="hidden" name="id" value="<?php echo (int) $this->item->id; ?>">
                                        <input type="hidden" name="action" value="<?php echo $this->escape($this->action); ?>">
                                        <input type="hidden" name="signature" value="<?php echo $this->escape($this->signature); ?>">
                                        <input type="hidden" name="month" value="<?php echo $this->escape($month); ?>">
                                        <input type="hidden" name="day" value="<?php echo $this->escape($this->day); ?>">
                                        <input type="hidden" name="timezone" value="<?php echo $this->escape($this->zone->getName()); ?>">
                                        <fieldset class="com-appointments-slots"<?php echo isset($errors['start']) ? ' aria-describedby="manage-start-error"' : ''; ?>>
                                            <legend class="com-appointments-slots-legend"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_NEW_SLOT'); ?> — <?php echo $this->escape($dates->format(new DateTimeImmutable($this->day, new DateTimeZone('UTC')), $dayFormat)); ?></legend>
                                            <?php if (isset($errors['start'])) : ?>
                                                <div id="manage-start-error" class="invalid-feedback com-appointments-field-error"><?php echo $this->escape(Text::_($errors['start'])); ?></div>
                                            <?php endif; ?>
                                            <div class="com-appointments-slot-list">
                                                <?php foreach ($this->slots[$this->day] as $i => $slot) : ?>
                                                    <?php $slotValue = $slot->format('Y-m-d H:i:s'); ?>
                                                    <div class="form-check com-appointments-slot">
                                                        <input class="form-check-input com-appointments-slot-input<?php echo isset($errors['start']) ? ' is-invalid' : ''; ?>" type="radio" id="manage-slot-<?php echo (int) $i; ?>" name="start" value="<?php echo $this->escape($slotValue); ?>" required<?php echo isset($errors['start']) && $i === 0 ? ' autofocus aria-invalid="true"' : ''; ?>>
                                                        <label class="form-check-label com-appointments-slot-time" for="manage-slot-<?php echo (int) $i; ?>"><?php echo $this->escape($dates->time($slot->setTimezone($this->zone), $timeFormat, $withOffset)); ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </fieldset>
                                        <div class="com-appointments-field">
                                            <div class="form-check com-appointments-confirm">
                                                <input class="form-check-input<?php echo $invalid('confirm'); ?>" id="manage-confirm" type="checkbox" name="confirm" value="1" required<?php echo $aria('confirm'); ?>>
                                                <label class="form-check-label" for="manage-confirm"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_CONFIRM'); ?></label>
                                                <?php echo $error('confirm'); ?>
                                            </div>
                                        </div>
                                        <?php echo HTMLHelper::_('form.token'); ?>
                                        <div class="com-appointments-actions">
                                            <button type="submit" class="btn btn-primary"><?php echo Text::_($headings[$this->action]); ?></button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php else : ?>
                            <div class="com-appointments-times-hint">
                                <p class="com-appointments-times-hint-text"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_TIMES_HINT'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($this->action === 'cancel') : ?>
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
            <?php elseif ($this->action === 'erase') : ?>
                <form method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=booking.manage'); ?>" class="com-appointments-form com-appointments-manage-form">
                    <input type="hidden" name="id" value="<?php echo (int) $this->item->id; ?>">
                    <input type="hidden" name="action" value="<?php echo $this->escape($this->action); ?>">
                    <input type="hidden" name="signature" value="<?php echo $this->escape($this->signature); ?>">
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
