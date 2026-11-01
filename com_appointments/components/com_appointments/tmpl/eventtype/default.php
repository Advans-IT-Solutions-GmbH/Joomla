<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\BookingInput;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Filter\InputFilter;

defined('_JEXEC') or die;

/*
 * Template-neutral markup: Joomla's standard form classes (form-label, form-control,
 * form-select, form-check, input-group, invalid-feedback, btn) plus com-appointments-*
 * hooks for a site template. The component stylesheet only adds layout, touch-target
 * sizes and the error colour, so the template keeps typography and colours.
 *
 * Two steps in one form: 1. date and time (default_picker.php), 2. the visitor's details.
 * The booking script (media/com_appointments/js/booking.js) only replaces step 1 in the
 * background; without it every link and button works as a normal page request.
 */
$id = (int) $this->item->id;
$zone = $this->zone->getName();
$values = $this->inputData;
$value = fn (string $key) => $this->escape(is_scalar($values[$key] ?? '') ? (string) ($values[$key] ?? '') : '');
$errors = $this->errors;
// Focus the first invalid control in page order, not in the order the checks ran.
$order = array_merge(['start', 'name', 'email', 'phone'], array_map(static fn (array $field): string => 'field-' . (int) $field['id'], $this->fields), ['guests', 'consent']);
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
$icon = static fn (string $paths): string => '<svg class="com-appointments-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
$locationTypes = [
    'phone_host_calls' => ['COM_APPOINTMENTS_LOCATION_TYPE_PHONE_HOST', '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>'],
    'phone_invitee_calls' => ['COM_APPOINTMENTS_LOCATION_TYPE_PHONE_INVITEE', '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>'],
    'in_person' => ['COM_APPOINTMENTS_LOCATION_TYPE_IN_PERSON', '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>'],
    'conference' => ['COM_APPOINTMENTS_LOCATION_TYPE_CONFERENCE', '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-3v10l-5-3"/>'],
];
$locationType = $locationTypes[(string) $this->item->location_type] ?? null;
$location = trim((string) $this->item->location_text);
$description = trim((new InputFilter([], [], 1, 1))->clean((string) $this->item->description, 'html'));
$hasSlots = !empty($this->slots[$this->day]);
$dialCode = function (mixed $raw): string {
    $code = is_array($raw) && is_string($raw['country'] ?? null) ? $raw['country'] : '';

    return $code !== '' ? $code : $this->defaultDialCode;
};
$dialOptions = function (string $selected): string {
    $html = '<option value="">' . Text::_('COM_APPOINTMENTS_BOOKING_COUNTRY') . '</option>';

    foreach (BookingInput::DIAL_CODES as $code) {
        $html .= '<option value="' . $code . '"' . ($selected === $code ? ' selected' : '') . '>+' . $code . '</option>';
    }

    return $html;
};
$phone = is_array($values['phone'] ?? null) ? $values['phone'] : [];
$guestsOpen = $value('guests') !== '' || isset($errors['guests']);
$consentText = trim((string) $this->params->get('consent_text', '')) ?: Text::_('COM_APPOINTMENTS_BOOKING_CONSENT_TEXT');
?>
<div class="com-appointments com-appointments-eventtype" data-appointments-booking>
    <div class="com-appointments-container">
        <header class="com-appointments-header">
            <h1 class="com-appointments-heading"><?php echo $this->escape($this->pageHeading); ?></h1>
            <ul class="com-appointments-meta">
                <li class="com-appointments-duration"><?php echo $icon('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'); ?><span><?php echo Text::sprintf((int) $this->item->duration_minutes === 1 ? 'COM_APPOINTMENTS_DURATION_MINUTES_1' : 'COM_APPOINTMENTS_DURATION_MINUTES_MORE', (int) $this->item->duration_minutes); ?></span></li>
                <?php if ($locationType !== null) : ?>
                    <li class="com-appointments-location-type"><?php echo $icon($locationType[1]); ?><span><?php echo Text::_($locationType[0]); ?></span></li>
                <?php endif; ?>
            </ul>
            <?php if ($description !== '') : ?>
                <div class="com-appointments-description"><?php echo $description; ?></div>
            <?php endif; ?>
            <?php if ($location !== '') : ?>
                <p class="com-appointments-location"><?php echo $this->escape($location); ?></p>
            <?php endif; ?>
        </header>

        <form id="appointment-booking" method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=booking.submit&id=' . $id); ?>" class="com-appointments-form com-appointments-booking">
            <section class="com-appointments-step com-appointments-step-time" aria-labelledby="appointment-step-time">
                <h2 id="appointment-step-time" class="com-appointments-step-title"><span class="com-appointments-step-number" aria-hidden="true">1</span><?php echo Text::_('COM_APPOINTMENTS_BOOKING_STEP_TIME'); ?></h2>
                <?php echo $this->loadTemplate('picker'); ?>
                <details class="com-appointments-zone">
                    <summary class="com-appointments-zone-toggle"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_ZONE_OTHER'); ?></summary>
                    <div class="com-appointments-zone-row">
                        <label class="form-label" for="appointment-zone"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_ZONE'); ?></label>
                        <select class="form-select" name="timezone" id="appointment-zone" form="appointment-zone-form" aria-describedby="appointment-zone-note">
                            <?php foreach (DateTimeZone::listIdentifiers() as $name) : ?>
                                <option value="<?php echo $this->escape($name); ?>"<?php echo $name === $zone ? ' selected' : ''; ?>><?php echo $this->escape($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" form="appointment-zone-form" class="btn btn-secondary com-appointments-zone-submit"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_SHOW_TIMES'); ?></button>
                    </div>
                </details>
            </section>

            <section id="appointment-details" class="com-appointments-step com-appointments-step-details" aria-labelledby="appointment-step-details"<?php echo $hasSlots ? '' : ' hidden'; ?>>
                <h2 id="appointment-step-details" class="com-appointments-step-title"><span class="com-appointments-step-number" aria-hidden="true">2</span><?php echo Text::_('COM_APPOINTMENTS_BOOKING_STEP_DETAILS'); ?></h2>
                <div class="com-appointments-fields">
                    <div class="com-appointments-field com-appointments-field-half">
                        <label class="form-label" for="booking-name"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NAME'); ?></label>
                        <input class="form-control<?php echo $invalid('name'); ?>" id="booking-name" name="booking[name]" maxlength="255" required autocomplete="name" value="<?php echo $value('name'); ?>"<?php echo $aria('name'); ?>>
                        <?php echo $error('name'); ?>
                    </div>
                    <div class="com-appointments-field com-appointments-field-half">
                        <label class="form-label" for="booking-email"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_EMAIL'); ?></label>
                        <input class="form-control<?php echo $invalid('email'); ?>" id="booking-email" type="email" name="booking[email]" maxlength="255" required autocomplete="email" value="<?php echo $value('email'); ?>"<?php echo $aria('email'); ?>>
                        <?php echo $error('email'); ?>
                    </div>
                    <?php if ($this->phoneRequired) : ?>
                        <div class="com-appointments-field com-appointments-field-half com-appointments-field-phone">
                            <label class="form-label" for="booking-phone"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_PHONE'); ?></label>
                            <div class="input-group com-appointments-tel">
                                <label class="visually-hidden" for="booking-phone-country"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_COUNTRY'); ?></label>
                                <select class="form-select com-appointments-tel-country<?php echo $invalid('phone'); ?>" id="booking-phone-country" name="booking[phone][country]" autocomplete="tel-country-code"<?php echo isset($errors['phone']) ? ' aria-invalid="true" aria-describedby="booking-phone-error"' : ''; ?>><?php echo $dialOptions($dialCode($phone)); ?></select>
                                <input class="form-control com-appointments-tel-number<?php echo $invalid('phone'); ?>" id="booking-phone" type="tel" name="booking[phone][number]" maxlength="40" required autocomplete="tel-national" inputmode="tel" value="<?php echo $this->escape(is_string($phone['number'] ?? null) ? $phone['number'] : ''); ?>"<?php echo $aria('phone', 'booking-phone-desc'); ?>>
                            </div>
                            <div id="booking-phone-desc" class="form-text com-appointments-field-description"><?php echo Text::_($this->item->location_type === 'phone_host_calls' ? 'COM_APPOINTMENTS_BOOKING_PHONE_DESC_HOST' : 'COM_APPOINTMENTS_BOOKING_PHONE_DESC_INVITEE'); ?></div>
                            <?php echo $error('phone'); ?>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($this->fields as $field) : ?>
                        <?php
                        $fieldId = (int) $field['id'];
                        $key = 'field-' . $fieldId;
                        $name = 'booking[fields][' . $fieldId . ']';
                        $raw = $values['fields'][$fieldId] ?? '';
                        $textValue = $this->escape(is_scalar($raw) ? (string) $raw : '');
                        $required = (int) $field['required'] === 1 ? ' required' : '';
                        $options = json_decode((string) ($field['options'] ?? '[]'), true) ?: [];
                        $fieldDescription = $field['description'] ? 'booking-desc-' . $fieldId : '';
                        $descriptionHtml = $fieldDescription !== '' ? '<div id="' . $fieldDescription . '" class="form-text com-appointments-field-description">' . $this->escape($field['description']) . '</div>' : '';
                        $labelText = $this->escape($field['label']) . ($required ? ' *' : '');
                        ?>
                        <?php if (in_array($field['type'], ['radio', 'checkboxes'], true)) : ?>
                            <fieldset class="com-appointments-field com-appointments-field-group" id="booking-field-<?php echo $fieldId; ?>"<?php echo trim($fieldDescription . (isset($errors[$key]) ? ' booking-' . $key . '-error' : '')) !== '' ? ' aria-describedby="' . trim($fieldDescription . (isset($errors[$key]) ? ' booking-' . $key . '-error' : '')) . '"' : ''; ?>>
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
                                    <input class="form-check-input<?php echo $invalid($key); ?>" id="booking-field-<?php echo $fieldId; ?>" type="checkbox" name="<?php echo $name; ?>" value="1"<?php echo $required; ?><?php echo $raw === '1' ? ' checked' : ''; ?><?php echo $aria($key, $fieldDescription); ?>>
                                    <label class="form-check-label" for="booking-field-<?php echo $fieldId; ?>"><?php echo $labelText; ?></label>
                                    <?php echo $descriptionHtml; ?>
                                    <?php echo $error($key); ?>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="com-appointments-field<?php echo in_array($field['type'], ['textarea'], true) ? '' : ' com-appointments-field-half'; ?>">
                                <label class="form-label" for="booking-field-<?php echo $fieldId; ?>"><?php echo $labelText; ?></label>
                                <?php if ($field['type'] === 'textarea') : ?>
                                    <textarea class="form-control<?php echo $invalid($key); ?>" id="booking-field-<?php echo $fieldId; ?>" name="<?php echo $name; ?>" maxlength="8000"<?php echo $required; ?><?php echo $aria($key, $fieldDescription); ?>><?php echo $textValue; ?></textarea>
                                <?php elseif ($field['type'] === 'tel') : ?>
                                    <div class="input-group com-appointments-tel">
                                        <label class="visually-hidden" for="booking-country-<?php echo $fieldId; ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_COUNTRY'); ?></label>
                                        <select class="form-select com-appointments-tel-country<?php echo $invalid($key); ?>" id="booking-country-<?php echo $fieldId; ?>" name="<?php echo $name; ?>[country]"<?php echo isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="booking-' . $key . '-error"' : ''; ?>><?php echo $dialOptions($dialCode($raw)); ?></select>
                                        <input class="form-control com-appointments-tel-number<?php echo $invalid($key); ?>" id="booking-field-<?php echo $fieldId; ?>" type="tel" name="<?php echo $name; ?>[number]" maxlength="40" autocomplete="tel-national" inputmode="tel" value="<?php echo $this->escape(is_array($raw) && is_string($raw['number'] ?? null) ? $raw['number'] : ''); ?>"<?php echo $required; ?><?php echo $aria($key, $fieldDescription); ?>>
                                    </div>
                                    <?php echo $descriptionHtml; ?>
                                <?php else : ?>
                                    <?php $type = in_array($field['type'], ['text', 'number', 'email', 'url', 'date'], true) ? $field['type'] : 'text'; ?>
                                    <input class="form-control<?php echo $invalid($key); ?>" id="booking-field-<?php echo $fieldId; ?>" type="<?php echo $type; ?>" name="<?php echo $name; ?>" maxlength="255" value="<?php echo $textValue; ?>"<?php echo $type === 'number' ? ' step="any"' : ''; ?><?php echo $required; ?><?php echo $aria($key, $fieldDescription); ?>>
                                <?php endif; ?>
                                <?php if ($field['type'] !== 'tel') : ?>
                                    <?php echo $descriptionHtml; ?>
                                <?php endif; ?>
                                <?php echo $error($key); ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <details class="com-appointments-guests"<?php echo $guestsOpen ? ' open' : ''; ?>>
                    <summary class="com-appointments-guests-toggle"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_GUESTS_ADD'); ?></summary>
                    <div class="com-appointments-field">
                        <label class="form-label" for="booking-guests"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_GUESTS'); ?></label>
                        <textarea class="form-control<?php echo $invalid('guests'); ?>" id="booking-guests" name="booking[guests]" maxlength="3000" rows="2"<?php echo $aria('guests'); ?>><?php echo $value('guests'); ?></textarea>
                        <?php echo $error('guests'); ?>
                    </div>
                </details>
                <div class="com-appointments-honeypot" aria-hidden="true">
                    <label for="booking-website"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_WEBSITE'); ?></label>
                    <input id="booking-website" name="website" tabindex="-1" autocomplete="off">
                </div>
                <div class="com-appointments-field com-appointments-privacy">
                    <div class="form-check com-appointments-consent">
                        <input class="form-check-input<?php echo $invalid('consent'); ?>" id="booking-consent" type="checkbox" name="consent" value="1" required<?php echo $aria('consent', 'booking-consent-text'); ?>>
                        <label class="form-check-label" for="booking-consent"><?php if ($this->privacyLink !== '') : ?><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_CONSENT_LINK', '<a class="com-appointments-privacy-link" href="' . $this->escape($this->privacyLink) . '" target="_blank" rel="noopener">' . Text::_('COM_APPOINTMENTS_BOOKING_PRIVACY_LINK') . '<span class="visually-hidden"> ' . Text::_('COM_APPOINTMENTS_BOOKING_NEW_WINDOW') . '</span></a>'); ?><?php else : ?><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CONSENT'); ?><?php endif; ?></label>
                        <?php echo $error('consent'); ?>
                    </div>
                    <p id="booking-consent-text" class="form-text com-appointments-consent-text"><?php echo $this->escape($consentText); ?> <?php echo Text::_('COM_APPOINTMENTS_BOOKING_ERASURE_NOTICE'); ?></p>
                </div>
                <input type="hidden" name="rendered_at" value="<?php echo $this->renderedAt; ?>">
                <input type="hidden" name="form_signature" value="<?php echo $this->formSignature; ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
                <div class="com-appointments-actions">
                    <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_SUBMIT'); ?></button>
                </div>
            </section>
        </form>
        <form id="appointment-zone-form" class="com-appointments-zone-form" method="get" action="<?php echo Route::_('index.php'); ?>#appointment-picker">
            <input type="hidden" name="option" value="com_appointments">
            <input type="hidden" name="view" value="eventtype">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="month" value="<?php echo $this->escape($this->month->format('Y-m')); ?>">
        </form>
    </div>
</div>
