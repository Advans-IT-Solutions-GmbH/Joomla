<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;
$this->getDocument()->getWebAssetManager()->useScript('form.validate')->useScript('keepalive');
?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=field&layout=edit&id=' . (int) $this->item->id); ?>"
      method="post" name="adminForm" id="field-form" class="form-validate">
    <div class="row">
        <div class="col-lg-9"><?php echo $this->form->renderFieldset('details'); ?></div>
        <div class="col-lg-3"><?php echo $this->form->renderFieldset('state'); ?></div>
    </div>
    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
