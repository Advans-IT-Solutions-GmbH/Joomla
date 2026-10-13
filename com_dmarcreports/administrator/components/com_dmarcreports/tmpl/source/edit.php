<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/** @var Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')->useScript('form.validate');
?>
<form action="<?php echo Route::_('index.php?option=com_dmarcreports&view=source&layout=edit&id=' . (int) $this->item->id); ?>"
      method="post" name="adminForm" id="source-form" class="form-validate">
    <div class="row">
        <div class="col-lg-9">
            <div class="card">
                <div class="card-body">
                    <?php echo $this->form->renderFieldset('details'); ?>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><?php echo Text::_('COM_DMARCREPORTS_SOURCE_MAILBOX_HEADING'); ?></div>
                <div class="card-body">
                    <p class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_SOURCE_PASSWORD_HINT'); ?></p>
                    <?php echo $this->form->renderFieldset('mailbox'); ?>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card">
                <div class="card-body">
                    <?php echo $this->form->renderFieldset('state'); ?>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><?php echo Text::_('COM_DMARCREPORTS_ROLLOUT_HEADING'); ?></div>
                <div class="card-body small">
                    <p><?php echo Text::_('COM_DMARCREPORTS_ROLLOUT_NONE'); ?></p>
                    <p><?php echo Text::_('COM_DMARCREPORTS_ROLLOUT_QUARANTINE'); ?></p>
                    <p><?php echo Text::_('COM_DMARCREPORTS_ROLLOUT_REJECT'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
