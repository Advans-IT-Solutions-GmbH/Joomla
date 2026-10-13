<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\Language\Text;
use Joomla\Filter\InputFilter;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Advans\Component\Appointments\Administrator\Helper\BookingInput;

defined('_JEXEC') or die;
$id = (int) $this->item->id;
$zone = $this->zone->getName();
$month = $this->month->format('Y-m');
$link = static fn (string $m, string $day = '') => Route::_('index.php?option=com_appointments&view=eventtype&id=' . $id
    . '&timezone=' . urlencode($zone) . '&month=' . urlencode($m) . ($day !== '' ? '&day=' . urlencode($day) : ''));
$values = $this->inputData;
$value = fn (string $key) => $this->escape(is_scalar($values[$key] ?? '') ? (string) ($values[$key] ?? '') : '');
?>
<div class="com-appointments">
    <h1><?php echo $this->escape($this->item->title); ?></h1>
    <div class="com-appointments-description"><?php echo (new InputFilter([], [], 1, 1))->clean((string) $this->item->description, 'html'); ?></div>
    <p><?php echo Text::sprintf((int) $this->item->duration_minutes === 1 ? 'COM_APPOINTMENTS_DURATION_MINUTES_1' : 'COM_APPOINTMENTS_DURATION_MINUTES_MORE', (int) $this->item->duration_minutes); ?></p>
    <p><?php echo $this->escape((string) $this->item->location_text); ?></p>
    <form method="get" action="<?php echo Route::_('index.php'); ?>">
        <input type="hidden" name="option" value="com_appointments">
        <input type="hidden" name="view" value="eventtype">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="month" value="<?php echo $this->escape($month); ?>">
        <label for="appointment-zone"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_ZONE'); ?></label>
        <select name="timezone" id="appointment-zone">
            <?php foreach (DateTimeZone::listIdentifiers() as $name) : ?>
                <option value="<?php echo $this->escape($name); ?>"<?php echo $name === $zone ? ' selected' : ''; ?>><?php echo $this->escape($name); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CHANGE_ZONE'); ?></button>
    </form>
    <nav class="com-appointments-month-nav" aria-label="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_MONTH'); ?>">
        <a href="<?php echo $link($this->month->modify('-1 month')->format('Y-m')); ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_PREVIOUS'); ?></a>
        <h2><?php echo $this->escape($month); ?></h2>
        <a href="<?php echo $link($this->month->modify('+1 month')->format('Y-m')); ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NEXT'); ?></a>
    </nav>
    <div class="com-appointments-month" aria-label="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_CHOOSE_DAY'); ?>">
        <?php
        $first = new DateTimeImmutable($this->days[0], new DateTimeZone('UTC'));
        for ($blank = 1; $blank < (int) $first->format('N'); $blank++) : ?>
            <span aria-hidden="true"></span>
        <?php endfor; ?>
        <?php foreach ($this->days as $day) : ?>
            <?php if (!empty($this->slots[$day])) : ?>
                <a href="<?php echo $link($month, $day); ?>"<?php echo $day === $this->day ? ' aria-current="date"' : ''; ?>>
                    <span class="visually-hidden"><?php echo $this->escape($day); ?></span>
                    <span aria-hidden="true"><?php echo (int) substr($day, 8, 2); ?></span>
                </a>
            <?php else : ?>
                <span><?php echo (int) substr($day, 8, 2); ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php if (empty($this->slots[$this->day])) : ?>
        <p><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NO_SLOTS'); ?></p>
    <?php else : ?>
        <form method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=booking.submit&id=' . $id); ?>" class="com-appointments-booking">
            <fieldset>
                <legend><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_SLOTS_ON', $this->escape($this->day)); ?></legend>
                <?php foreach ($this->slots[$this->day] as $i => $slot) : ?>
                    <label class="com-appointments-slot">
                        <input type="radio" name="booking[start]" value="<?php echo $slot->format('Y-m-d H:i:s'); ?>" required>
                        <?php echo $this->escape($slot->setTimezone($this->zone)->format('H:i T')); ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <label for="booking-name"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NAME'); ?></label>
            <input id="booking-name" name="booking[name]" maxlength="255" required autocomplete="name" value="<?php echo $value('name'); ?>">
            <label for="booking-email"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_EMAIL'); ?></label>
            <input id="booking-email" type="email" name="booking[email]" maxlength="255" required autocomplete="email" value="<?php echo $value('email'); ?>">
            <label for="booking-guests"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_GUESTS'); ?></label>
            <textarea id="booking-guests" name="booking[guests]" maxlength="3000"><?php echo $value('guests'); ?></textarea>
            <?php foreach ($this->fields as $field) : ?>
                <?php
                $fieldId = (int) $field['id'];
                $name = 'booking[fields][' . $fieldId . ']';
                $raw = $values['fields'][$fieldId] ?? '';
                $textValue = $this->escape(is_scalar($raw) ? (string) $raw : '');
                $required = (int) $field['required'] === 1 ? ' required' : '';
                $options = json_decode((string) ($field['options'] ?? '[]'), true) ?: [];
                ?>
                <div class="com-appointments-custom-field">
                    <label for="booking-field-<?php echo $fieldId; ?>"><?php echo $this->escape($field['label']); ?><?php echo $required ? ' *' : ''; ?></label>
                    <?php if ($field['description']) : ?><p id="booking-desc-<?php echo $fieldId; ?>"><?php echo $this->escape($field['description']); ?></p><?php endif; ?>
                    <?php if ($field['type'] === 'textarea') : ?>
                        <textarea id="booking-field-<?php echo $fieldId; ?>" name="<?php echo $name; ?>" maxlength="8000"<?php echo $required; ?>><?php echo $textValue; ?></textarea>
                    <?php elseif ($field['type'] === 'tel') : ?>
                        <label for="booking-country-<?php echo $fieldId; ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_COUNTRY'); ?></label>
                        <select id="booking-country-<?php echo $fieldId; ?>" name="<?php echo $name; ?>[country]"<?php echo $required; ?>>
                            <option value=""><?php echo Text::_('JOPTION_SELECT'); ?></option>
                            <?php foreach (BookingInput::DIAL_CODES as $code) : ?>
                                <option value="<?php echo $code; ?>"<?php echo is_array($raw) && ($raw['country'] ?? '') === $code ? ' selected' : ''; ?>>+<?php echo $code; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input id="booking-field-<?php echo $fieldId; ?>" type="tel" name="<?php echo $name; ?>[number]" maxlength="40" autocomplete="tel-national" value="<?php echo $this->escape(is_array($raw) ? (string) ($raw['number'] ?? '') : ''); ?>"<?php echo $required; ?>>
                    <?php elseif (in_array($field['type'], ['radio', 'checkboxes'], true)) : ?>
                        <fieldset id="booking-field-<?php echo $fieldId; ?>"><legend class="visually-hidden"><?php echo $this->escape($field['label']); ?></legend>
                        <?php foreach ($options as $choice) : ?>
                            <label><input type="<?php echo $field['type'] === 'radio' ? 'radio' : 'checkbox'; ?>" name="<?php echo $name . ($field['type'] === 'checkboxes' ? '[]' : ''); ?>" value="<?php echo $this->escape($choice); ?>"<?php echo $field['type'] === 'radio' ? $required : ''; ?><?php echo (is_array($raw) ? in_array($choice, $raw, true) : $raw === $choice) ? ' checked' : ''; ?>><?php echo $this->escape($choice); ?></label>
                        <?php endforeach; ?>
                        </fieldset>
                    <?php elseif ($field['type'] === 'checkbox') : ?>
                        <input id="booking-field-<?php echo $fieldId; ?>" type="checkbox" name="<?php echo $name; ?>" value="1"<?php echo $required; ?><?php echo $raw === '1' ? ' checked' : ''; ?>>
                    <?php else : ?>
                        <?php $type = in_array($field['type'], ['text', 'number', 'email', 'url', 'date'], true) ? $field['type'] : 'text'; ?>
                        <input id="booking-field-<?php echo $fieldId; ?>" type="<?php echo $type; ?>" name="<?php echo $name; ?>" maxlength="255" value="<?php echo $textValue; ?>"<?php echo $type === 'number' ? ' step="any"' : ''; ?><?php echo $required; ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="com-appointments-honeypot" aria-hidden="true">
                <label for="booking-website"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_WEBSITE'); ?></label>
                <input id="booking-website" name="website" tabindex="-1" autocomplete="off">
            </div>
            <p><?php echo $this->escape((string) $this->params->get('consent_text', Text::_('COM_APPOINTMENTS_BOOKING_CONSENT_TEXT'))); ?></p>
            <?php if ($this->params->get('privacy_url')) : ?>
                <a href="<?php echo $this->escape((string) $this->params->get('privacy_url')); ?>" rel="noreferrer"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_PRIVACY'); ?></a>
            <?php endif; ?>
            <p><?php echo Text::_('COM_APPOINTMENTS_BOOKING_ERASURE_NOTICE'); ?></p>
            <label><input type="checkbox" name="consent" value="1" required><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CONSENT'); ?></label>
            <input type="hidden" name="booking[timezone]" value="<?php echo $this->escape($zone); ?>">
            <input type="hidden" name="rendered_at" value="<?php echo $this->renderedAt; ?>">
            <input type="hidden" name="form_signature" value="<?php echo $this->formSignature; ?>">
            <?php echo HTMLHelper::_('form.token'); ?>
            <button type="submit"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_SUBMIT'); ?></button>
        </form>
    <?php endif; ?>
</div>
