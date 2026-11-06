<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;
$this->getDocument()->getWebAssetManager()->useScript('form.validate')->useScript('keepalive');
?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=eventtype&layout=edit&id=' . (int) $this->item->id); ?>"
      method="post" name="adminForm" id="eventtype-form" class="form-validate">
    <div class="main-card">
        <?php echo HTMLHelper::_('uitab.startTabSet', 'eventtype-tabs', ['active' => 'details', 'recall' => true, 'breakpoint' => 768]); ?>
        <?php echo HTMLHelper::_('uitab.addTab', 'eventtype-tabs', 'details', Text::_('JDETAILS')); ?>
        <div class="row">
            <div class="col-lg-9">
                <?php echo $this->form->renderFieldset('details'); ?>
                <fieldset class="options-form" id="eventtype-display">
                    <legend><?php echo Text::_('COM_APPOINTMENTS_EVENTTYPE_FIELDSET_DISPLAY'); ?></legend>
                    <?php echo $this->form->renderFieldset('display'); ?>
                </fieldset>
                <?php echo $this->form->renderFieldset('conference'); ?>
                <?php if ($this->form->getFieldset('calendars') !== []) : ?>
                <fieldset class="options-form" id="eventtype-calendars">
                    <legend><?php echo Text::_('COM_APPOINTMENTS_TEAM_E_EVENTTYPE_FIELDSET'); ?></legend>
                    <?php echo $this->form->renderFieldset('calendars'); ?>
                </fieldset>
                <?php endif; ?>
            </div>
            <div class="col-lg-3"><?php echo $this->form->renderFieldset('state'); ?></div>
        </div>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>
        <?php echo HTMLHelper::_('uitab.addTab', 'eventtype-tabs', 'permissions', Text::_('JCONFIG_PERMISSIONS_LABEL')); ?>
        <fieldset class="options-form" id="eventtype-permissions">
            <legend><?php echo Text::_('JCONFIG_PERMISSIONS_LABEL'); ?></legend>
            <div><?php echo $this->form->getInput('rules'); ?></div>
        </fieldset>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>
        <?php echo HTMLHelper::_('uitab.endTabSet'); ?>
    </div>
    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
