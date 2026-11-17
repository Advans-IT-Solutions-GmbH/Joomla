<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;
$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('form.validate')->useScript('keepalive');

// «Hide past dates» only hides rows in the browser. The rows stay in the form, so saving keeps
// every date: the save replaces all rows of the schedule with what the form sends. Today is the
// date in the schedule's own zone, the zone its dates are written in.
try {
    $zone = new DateTimeZone((string) ($this->item->timezone ?? '') ?: 'UTC');
} catch (Exception $e) {
    $zone = new DateTimeZone('UTC');
}

$today = (new DateTimeImmutable('now', $zone))->format('Y-m-d');
$wa->addInlineScript(<<<'JS'
((start) => {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})(() => {
  const toggle = document.getElementById('appointments-hide-past-overrides');
  const note = document.getElementById('appointments-past-overrides-hidden');

  if (!toggle || !note) {
    return;
  }

  const box = toggle.closest('.card-body');
  const today = toggle.dataset.today || '';
  const apply = () => {
    let hidden = 0;

    box.querySelectorAll('.subform-repeatable-group').forEach((row) => {
      const input = row.querySelector('input[name$="[specific_date]"]');
      const day = input ? String(input.value || '').slice(0, 10) : '';
      const past = toggle.checked && /^\d{4}-\d{2}-\d{2}$/.test(day) && day < today;

      row.hidden = past;
      hidden += past ? 1 : 0;
    });

    note.textContent = (note.dataset.template || '').replace('%d', String(hidden));
    note.hidden = hidden === 0;
  };

  toggle.addEventListener('change', apply);
  box.addEventListener('change', apply);
  document.addEventListener('subform-row-add', apply);
  apply();
});
JS);
?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=schedule&layout=edit&id=' . (int) $this->item->id); ?>"
      method="post" name="adminForm" id="schedule-form" class="form-validate">
    <div class="row">
        <div class="col-lg-9">
            <div class="card">
                <div class="card-body">
                    <?php echo $this->form->renderFieldset('details'); ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="mb-0"><?php echo Text::_('COM_APPOINTMENTS_SCHEDULE_WEEKLY_HEADING'); ?></h2>
                </div>
                <div class="card-body">
                    <p class="text-muted"><?php echo Text::_('COM_APPOINTMENTS_SCHEDULE_WEEKLY_INTRO'); ?></p>
                    <?php echo $this->form->renderFieldset('weekly'); ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="mb-0"><?php echo Text::_('COM_APPOINTMENTS_SCHEDULE_OVERRIDES_HEADING'); ?></h2>
                </div>
                <div class="card-body">
                    <p class="text-muted"><?php echo Text::_('COM_APPOINTMENTS_SCHEDULE_OVERRIDES_INTRO'); ?></p>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="appointments-hide-past-overrides"
                               data-today="<?php echo $this->escape($today); ?>">
                        <label class="form-check-label" for="appointments-hide-past-overrides"><?php echo Text::_('COM_APPOINTMENTS_SCHEDULE_OVERRIDES_HIDE_PAST'); ?></label>
                    </div>
                    <p id="appointments-past-overrides-hidden" class="small text-muted" role="status" hidden
                       data-template="<?php echo $this->escape(Text::_('COM_APPOINTMENTS_SCHEDULE_OVERRIDES_PAST_HIDDEN')); ?>"></p>
                    <?php echo $this->form->renderFieldset('overrides'); ?>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card">
                <div class="card-body">
                    <?php echo $this->form->renderFieldset('state'); ?>
                </div>
            </div>
        </div>
    </div>

    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
