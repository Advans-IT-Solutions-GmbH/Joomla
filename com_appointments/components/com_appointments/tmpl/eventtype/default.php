<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\BookingInput;
use Advans\Component\Appointments\Administrator\Helper\DateDisplay;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Filter\InputFilter;

defined('_JEXEC') or die;

/*
 * Template-neutral markup: Joomla's standard form classes (form-label, form-control,
 * form-select, form-check, invalid-feedback, btn) plus com-appointments-* hooks for a
 * site template. The component stylesheet only adds layout, touch-target sizes and the
 * error colour, so the template keeps typography and colours.
 */
$id = (int) $this->item->id;
$zone = $this->zone->getName();
$month = $this->month->format('Y-m');
$dates = new DateDisplay(static fn (string $key): string => Text::_($key));
$link = static fn (string $m, string $day = '') => Route::_('index.php?option=com_appointments&view=eventtype&id=' . $id
    . '&timezone=' . urlencode($zone) . '&month=' . urlencode($m) . ($day !== '' ? '&day=' . urlencode($day) : ''));
$values = $this->inputData;
$value = fn (string $key) => $this->escape(is_scalar($values[$key] ?? '') ? (string) ($values[$key] ?? '') : '');
$errors = $this->errors;
// Focus the first invalid control in page order, not in the order the checks ran.
$order = array_merge(['start', 'name', 'email', 'guests'], array_map(static fn (array $field): string => 'field-' . (int) $field['id'], $this->fields), ['consent']);
$firstError = current(array_intersect($order, array_keys($errors))) ?: null;
// Error wiring per field: the control is marked invalid and described by its message.
$invalid = static fn (string $key): string => isset($errors[$key]) ? ' is-invalid' : '';
$aria = static function (string $key, string $describedBy = '') use ($errors, $firstError): string {
    $ids = trim($describedBy . (isset($errors[$key]) ? ' booking-' . $key . '-error' : ''));

    return (isset($errors[$key]) ? ' aria-invalid="true"' : '') . ($ids !== '' ? ' aria-describedby="' . $ids . '"' : '')
        . ($key === $firstError ? ' autofocus' : '');
};
$error = fn (string $key): string => isset($errors[$key])
    ? '<div id="booking-' . $key . '-error" class="invalid-feedback com-appointments-field-error">' . $this->escape(Text::_($errors[$key])) . '</div>'
    : '';
$dayFormat = Text::_('COM_APPOINTMENTS_DATE_FORMAT_DAY');
$timeFormat = Text::_('COM_APPOINTMENTS_DATE_FORMAT_TIME');
$withOffset = Text::_('COM_APPOINTMENTS_TIME_WITH_OFFSET');
$selectedDay = new DateTimeImmutable($this->day . ' 00:00:00', $this->zone);
$first = new DateTimeImmutable($this->days[0], new DateTimeZone('UTC'));
$cells = array_merge(array_fill(0, (int) $first->format('N') - 1, null), $this->days);
$cells = array_merge($cells, array_fill(0, (7 - count($cells) % 7) % 7, null));
$location = trim((string) $this->item->location_text);
?>
<div class="com-appointments com-appointments-eventtype">
    <div class="com-appointments-container">
        <header class="com-appointments-header">
            <h1 class="com-appointments-heading"><?php echo $this->escape($this->pageHeading); ?></h1>
            <div class="com-appointments-description"><?php echo (new InputFilter([], [], 1, 1))->clean((string) $this->item->description, 'html'); ?></div>
            <p class="com-appointments-duration"><?php echo Text::sprintf((int) $this->item->duration_minutes === 1 ? 'COM_APPOINTMENTS_DURATION_MINUTES_1' : 'COM_APPOINTMENTS_DURATION_MINUTES_MORE', (int) $this->item->duration_minutes); ?></p>
            <?php if ($location !== '') : ?>
                <p class="com-appointments-location"><?php echo $this->escape($location); ?></p>
            <?php endif; ?>
        </header>

        <form class="com-appointments-zone" method="get" action="<?php echo Route::_('index.php'); ?>">
            <input type="hidden" name="option" value="com_appointments">
            <input type="hidden" name="view" value="eventtype">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="month" value="<?php echo $this->escape($month); ?>">
            <label class="form-label" for="appointment-zone"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_ZONE'); ?></label>
            <div class="com-appointments-zone-row">
                <select class="form-select" name="timezone" id="appointment-zone" aria-describedby="appointment-zone-note">
                    <?php foreach (DateTimeZone::listIdentifiers() as $name) : ?>
                        <option value="<?php echo $this->escape($name); ?>"<?php echo $name === $zone ? ' selected' : ''; ?>><?php echo $this->escape($name); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary com-appointments-zone-submit"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CHANGE_ZONE'); ?></button>
            </div>
            <p id="appointment-zone-note" class="form-text com-appointments-zone-note"><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_ZONE_NOTE', $this->escape($zone)); ?></p>
        </form>

        <section class="com-appointments-calendar" aria-labelledby="appointment-calendar-title">
            <nav class="com-appointments-calendar-nav" aria-label="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_MONTH'); ?>">
                <a class="btn btn-outline-secondary com-appointments-calendar-prev" href="<?php echo $link($this->month->modify('-1 month')->format('Y-m')); ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_PREVIOUS'); ?></a>
                <h2 id="appointment-calendar-title" class="com-appointments-calendar-title"><?php echo $this->escape($dates->format($this->month, Text::_('COM_APPOINTMENTS_DATE_FORMAT_MONTH'))); ?></h2>
                <a class="btn btn-outline-secondary com-appointments-calendar-next" href="<?php echo $link($this->month->modify('+1 month')->format('Y-m')); ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NEXT'); ?></a>
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
                            <?php foreach ($week as $day) : ?>
                                <?php if ($day === null) : ?>
                                    <td class="com-appointments-day-cell com-appointments-day-empty"></td>
                                <?php else : ?>
                                    <?php $label = $dates->format(new DateTimeImmutable($day, new DateTimeZone('UTC')), $dayFormat); ?>
                                    <td class="com-appointments-day-cell">
                                        <?php if (!empty($this->slots[$day])) : ?>
                                            <a class="com-appointments-day com-appointments-day-available<?php echo $day === $this->day ? ' com-appointments-day-selected' : ''; ?>" href="<?php echo $link($month, $day); ?>" aria-label="<?php echo $this->escape($label); ?>"<?php echo $day === $this->day ? ' aria-current="date"' : ''; ?>><?php echo (int) substr($day, 8, 2); ?></a>
                                        <?php else : ?>
                                            <span class="com-appointments-day com-appointments-day-unavailable"><?php echo (int) substr($day, 8, 2); ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <?php if (empty($this->slots[$this->day])) : ?>
            <p class="com-appointments-empty"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NO_SLOTS'); ?></p>
        <?php else : ?>
            <form method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=booking.submit&id=' . $id); ?>" class="com-appointments-form com-appointments-booking">
                <fieldset class="com-appointments-slots"<?php echo isset($errors['start']) ? ' aria-describedby="booking-start-error"' : ''; ?>>
                    <legend class="com-appointments-slots-legend"><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_SLOTS_ON', $this->escape($dates->format($selectedDay, $dayFormat))); ?></legend>
                    <div class="com-appointments-slot-list">
                        <?php foreach ($this->slots[$this->day] as $i => $slot) : ?>
                            <?php $slotValue = $slot->format('Y-m-d H:i:s'); ?>
                            <div class="form-check com-appointments-slot">
                                <input class="form-check-input<?php echo $invalid('start'); ?>" type="radio" id="booking-slot-<?php echo (int) $i; ?>" name="booking[start]" value="<?php echo $slotValue; ?>" required<?php echo ($values['start'] ?? null) === $slotValue ? ' checked' : ''; ?><?php echo isset($errors['start']) ? ' aria-invalid="true"' : ''; ?><?php echo $i === 0 && $firstError === 'start' ? ' autofocus' : ''; ?>>
                                <label class="form-check-label" for="booking-slot-<?php echo (int) $i; ?>"><?php echo $this->escape($dates->time($slot->setTimezone($this->zone), $timeFormat, $withOffset)); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php echo $error('start'); ?>
                </fieldset>

                <div class="com-appointments-field">
                    <label class="form-label" for="booking-name"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NAME'); ?></label>
                    <input class="form-control<?php echo $invalid('name'); ?>" id="booking-name" name="booking[name]" maxlength="255" required autocomplete="name" value="<?php echo $value('name'); ?>"<?php echo $aria('name'); ?>>
                    <?php echo $error('name'); ?>
                </div>
                <div class="com-appointments-field">
                    <label class="form-label" for="booking-email"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_EMAIL'); ?></label>
                    <input class="form-control<?php echo $invalid('email'); ?>" id="booking-email" type="email" name="booking[email]" maxlength="255" required autocomplete="email" value="<?php echo $value('email'); ?>"<?php echo $aria('email'); ?>>
                    <?php echo $error('email'); ?>
                </div>
                <div class="com-appointments-field">
                    <label class="form-label" for="booking-guests"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_GUESTS'); ?></label>
                    <textarea class="form-control<?php echo $invalid('guests'); ?>" id="booking-guests" name="booking[guests]" maxlength="3000"<?php echo $aria('guests'); ?>><?php echo $value('guests'); ?></textarea>
                    <?php echo $error('guests'); ?>
                </div>
                <?php foreach ($this->fields as $field) : ?>
                    <?php
                    $fieldId = (int) $field['id'];
                    $key = 'field-' . $fieldId;
                    $name = 'booking[fields][' . $fieldId . ']';
                    $raw = $values['fields'][$fieldId] ?? '';
                    $textValue = $this->escape(is_scalar($raw) ? (string) $raw : '');
                    $required = (int) $field['required'] === 1 ? ' required' : '';
                    $options = json_decode((string) ($field['options'] ?? '[]'), true) ?: [];
                    $description = $field['description'] ? 'booking-desc-' . $fieldId : '';
                    $descriptionHtml = $description !== '' ? '<div id="' . $description . '" class="form-text com-appointments-field-description">' . $this->escape($field['description']) . '</div>' : '';
                    $labelText = $this->escape($field['label']) . ($required ? ' *' : '');
                    ?>
                    <?php if (in_array($field['type'], ['radio', 'checkboxes'], true)) : ?>
                        <fieldset class="com-appointments-field com-appointments-field-group" id="booking-field-<?php echo $fieldId; ?>"<?php echo trim($description . (isset($errors[$key]) ? ' booking-' . $key . '-error' : '')) !== '' ? ' aria-describedby="' . trim($description . (isset($errors[$key]) ? ' booking-' . $key . '-error' : '')) . '"' : ''; ?>>
                            <legend class="form-label"><?php echo $labelText; ?></legend>
                            <?php echo $descriptionHtml; ?>
                            <?php foreach (array_values($options) as $index => $choice) : ?>
                                <div class="form-check">
                                    <input class="form-check-input<?php echo $invalid($key); ?>" id="booking-field-<?php echo $fieldId; ?>-<?php echo (int) $index; ?>" type="<?php echo $field['type'] === 'radio' ? 'radio' : 'checkbox'; ?>" name="<?php echo $name . ($field['type'] === 'checkboxes' ? '[]' : ''); ?>" value="<?php echo $this->escape($choice); ?>"<?php echo $field['type'] === 'radio' ? $required : ''; ?><?php echo (is_array($raw) ? in_array($choice, $raw, true) : $raw === $choice) ? ' checked' : ''; ?><?php echo isset($errors[$key]) ? ' aria-invalid="true"' : ''; ?><?php echo $index === 0 && $firstError === $key ? ' autofocus' : ''; ?>>
                                    <label class="form-check-label" for="booking-field-<?php echo $fieldId; ?>-<?php echo (int) $index; ?>"><?php echo $this->escape($choice); ?></label>
                                </div>
                            <?php endforeach; ?>
                            <?php echo $error($key); ?>
                        </fieldset>
                    <?php elseif ($field['type'] === 'checkbox') : ?>
                        <div class="com-appointments-field">
                            <div class="form-check">
                                <input class="form-check-input<?php echo $invalid($key); ?>" id="booking-field-<?php echo $fieldId; ?>" type="checkbox" name="<?php echo $name; ?>" value="1"<?php echo $required; ?><?php echo $raw === '1' ? ' checked' : ''; ?><?php echo $aria($key, $description); ?>>
                                <label class="form-check-label" for="booking-field-<?php echo $fieldId; ?>"><?php echo $labelText; ?></label>
                                <?php echo $descriptionHtml; ?>
                                <?php echo $error($key); ?>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="com-appointments-field">
                            <label class="form-label" for="booking-field-<?php echo $fieldId; ?>"><?php echo $labelText; ?></label>
                            <?php echo $descriptionHtml; ?>
                            <?php if ($field['type'] === 'textarea') : ?>
                                <textarea class="form-control<?php echo $invalid($key); ?>" id="booking-field-<?php echo $fieldId; ?>" name="<?php echo $name; ?>" maxlength="8000"<?php echo $required; ?><?php echo $aria($key, $description); ?>><?php echo $textValue; ?></textarea>
                            <?php elseif ($field['type'] === 'tel') : ?>
                                <div class="com-appointments-tel">
                                    <label class="visually-hidden" for="booking-country-<?php echo $fieldId; ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_COUNTRY'); ?></label>
                                    <select class="form-select com-appointments-tel-country<?php echo $invalid($key); ?>" id="booking-country-<?php echo $fieldId; ?>" name="<?php echo $name; ?>[country]"<?php echo $required; ?><?php echo isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="booking-' . $key . '-error"' : ''; ?>>
                                        <option value=""><?php echo Text::_('COM_APPOINTMENTS_BOOKING_COUNTRY'); ?></option>
                                        <?php foreach (BookingInput::DIAL_CODES as $code) : ?>
                                            <option value="<?php echo $code; ?>"<?php echo is_array($raw) && ($raw['country'] ?? '') === $code ? ' selected' : ''; ?>>+<?php echo $code; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input class="form-control com-appointments-tel-number<?php echo $invalid($key); ?>" id="booking-field-<?php echo $fieldId; ?>" type="tel" name="<?php echo $name; ?>[number]" maxlength="40" autocomplete="tel-national" value="<?php echo $this->escape(is_array($raw) ? (string) ($raw['number'] ?? '') : ''); ?>"<?php echo $required; ?><?php echo $aria($key, $description); ?>>
                                </div>
                            <?php else : ?>
                                <?php $type = in_array($field['type'], ['text', 'number', 'email', 'url', 'date'], true) ? $field['type'] : 'text'; ?>
                                <input class="form-control<?php echo $invalid($key); ?>" id="booking-field-<?php echo $fieldId; ?>" type="<?php echo $type; ?>" name="<?php echo $name; ?>" maxlength="255" value="<?php echo $textValue; ?>"<?php echo $type === 'number' ? ' step="any"' : ''; ?><?php echo $required; ?><?php echo $aria($key, $description); ?>>
                            <?php endif; ?>
                            <?php echo $error($key); ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="com-appointments-honeypot" aria-hidden="true">
                    <label for="booking-website"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_WEBSITE'); ?></label>
                    <input id="booking-website" name="website" tabindex="-1" autocomplete="off">
                </div>
                <div class="com-appointments-privacy">
                    <p id="booking-consent-text"><?php echo $this->escape((string) $this->params->get('consent_text', Text::_('COM_APPOINTMENTS_BOOKING_CONSENT_TEXT'))); ?></p>
                    <?php if ($this->params->get('privacy_url')) : ?>
                        <p><a href="<?php echo $this->escape((string) $this->params->get('privacy_url')); ?>" rel="noreferrer"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_PRIVACY'); ?></a></p>
                    <?php endif; ?>
                    <p class="form-text"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_ERASURE_NOTICE'); ?></p>
                </div>
                <div class="com-appointments-field">
                    <div class="form-check com-appointments-consent">
                        <input class="form-check-input<?php echo $invalid('consent'); ?>" id="booking-consent" type="checkbox" name="consent" value="1" required<?php echo $aria('consent', 'booking-consent-text'); ?>>
                        <label class="form-check-label" for="booking-consent"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CONSENT'); ?></label>
                        <?php echo $error('consent'); ?>
                    </div>
                </div>
                <input type="hidden" name="booking[timezone]" value="<?php echo $this->escape($zone); ?>">
                <input type="hidden" name="rendered_at" value="<?php echo $this->renderedAt; ?>">
                <input type="hidden" name="form_signature" value="<?php echo $this->formSignature; ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
                <div class="com-appointments-actions">
                    <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_SUBMIT'); ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
