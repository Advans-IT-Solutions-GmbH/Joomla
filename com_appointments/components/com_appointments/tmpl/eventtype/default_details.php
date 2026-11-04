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

defined('_JEXEC') or die;

/*
 * Step 2 of the booking page: the visitor's details for the chosen time, sent as one POST
 * form with the chosen time and time zone as hidden values. The information column shows
 * the chosen time and the way back to the times of its day.
 *
 * One column of fields; only name and email share a row on a wider column. Every required
 * field carries the same mark after its label (decorative: the control itself is required, and a
 * required group of checkboxes names the requirement in its legend for screen readers).
 */
$id = (int) $this->item->id;
$values = $this->inputData;
$value = fn (string $key) => $this->escape(is_scalar($values[$key] ?? '') ? (string) ($values[$key] ?? '') : '');
$errors = $this->errors;
// Focus the first invalid control in page order, not in the order the checks ran.
$order = array_merge(['name', 'email', 'phone'], array_map(static fn (array $field): string => 'field-' . (int) $field['id'], $this->fields), ['guests', 'consent']);
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
$dialCode = function (mixed $raw): string {
    // A refused submission shows the visitor's own choice again, an explicitly empty one included;
    // the preselected code only applies to a field the visitor has not sent yet.
    if (is_array($raw) && array_key_exists('country', $raw)) {
        return is_string($raw['country']) ? $raw['country'] : '';
    }

    return $this->defaultDialCode;
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
$mark = '<span class="com-appointments-required" aria-hidden="true">*</span>';
?>
<form id="appointment-details-form" method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=booking.submit&id=' . $id); ?>" class="com-appointments-form com-appointments-booking">
    <section id="appointment-details" class="com-appointments-step com-appointments-step-details" aria-labelledby="appointment-step-title">
        <div class="com-appointments-fields">
            <div class="com-appointments-field com-appointments-field-half">
                <label class="form-label" for="booking-name"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NAME') . $mark; ?></label>
                <input class="form-control<?php echo $invalid('name'); ?>" id="booking-name" name="booking[name]" maxlength="255" required autocomplete="name" value="<?php echo $value('name'); ?>"<?php echo $aria('name'); ?>>
                <?php echo $error('name'); ?>
            </div>
            <div class="com-appointments-field com-appointments-field-half">
                <label class="form-label" for="booking-email"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_EMAIL') . $mark; ?></label>
                <input class="form-control<?php echo $invalid('email'); ?>" id="booking-email" type="email" name="booking[email]" maxlength="255" required autocomplete="email" value="<?php echo $value('email'); ?>"<?php echo $aria('email'); ?>>
                <?php echo $error('email'); ?>
            </div>
            <?php if ($this->phoneRequired) : ?>
                <div class="com-appointments-field com-appointments-field-phone">
                    <label class="form-label" for="booking-phone"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_PHONE') . $mark; ?></label>
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
                $labelText = $this->escape($field['label']) . ($required ? $mark : '');
                ?>
                <?php if (in_array($field['type'], ['radio', 'checkboxes'], true)) : ?>
                    <fieldset class="com-appointments-field com-appointments-field-group" id="booking-field-<?php echo $fieldId; ?>"<?php echo trim($fieldDescription . (isset($errors[$key]) ? ' booking-' . $key . '-error' : '')) !== '' ? ' aria-describedby="' . trim($fieldDescription . (isset($errors[$key]) ? ' booking-' . $key . '-error' : '')) . '"' : ''; ?>>
                        <legend class="form-label"><?php echo $labelText; ?><?php if ($required && $field['type'] === 'checkboxes') : ?><span class="visually-hidden"> <?php echo Text::_('COM_APPOINTMENTS_BOOKING_REQUIRED_CHOICE'); ?></span><?php endif; ?></legend>
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
                    <div class="com-appointments-field">
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
                <label class="form-check-label" for="booking-consent"><?php if ($this->privacyLink !== '') : ?><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_CONSENT_LINK', '<a class="com-appointments-privacy-link" href="' . $this->escape($this->privacyLink) . '" target="_blank" rel="noopener">' . Text::_('COM_APPOINTMENTS_BOOKING_PRIVACY_LINK') . '<span class="visually-hidden"> ' . Text::_('COM_APPOINTMENTS_BOOKING_NEW_WINDOW') . '</span></a>'); ?><?php else : ?><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CONSENT'); ?><?php endif; ?><?php echo $mark; ?></label>
                <?php echo $error('consent'); ?>
            </div>
            <p id="booking-consent-text" class="form-text com-appointments-consent-text"><?php echo $this->escape($consentText); ?> <?php echo Text::_('COM_APPOINTMENTS_BOOKING_ERASURE_NOTICE'); ?></p>
        </div>
        <input type="hidden" name="booking[start]" value="<?php echo $this->escape($this->selected->format('Y-m-d H:i:s')); ?>">
        <input type="hidden" name="booking[timezone]" value="<?php echo $this->escape($this->zone->getName()); ?>">
        <input type="hidden" name="rendered_at" value="<?php echo $this->renderedAt; ?>">
        <input type="hidden" name="form_signature" value="<?php echo $this->formSignature; ?>">
        <?php echo HTMLHelper::_('form.token'); ?>
        <div class="com-appointments-actions">
            <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_SUBMIT'); ?></button>
        </div>
    </section>
</form>
