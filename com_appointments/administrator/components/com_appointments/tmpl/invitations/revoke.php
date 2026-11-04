<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/** @var \Advans\Component\Appointments\Administrator\View\Invitations\HtmlView $this */

if (!$this->enabled) : ?>
    <div class="alert alert-info" role="status"><?php echo Text::_('COM_APPOINTMENTS_TEAM_DISABLED'); ?></div>
    <?php return; ?>
<?php endif;

$item = $this->item;
$open = $item && (int) $item->state === TeamStates::INVITATION_OPEN;
$revocable = $item && $item->mac_valid && \in_array((int) $item->state, [TeamStates::INVITATION_OPEN, TeamStates::INVITATION_COMPLETED], true);
?>
<section class="card" aria-labelledby="revoke-heading">
    <div class="card-body">
        <h2 id="revoke-heading" class="h4"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_REVOKE_TITLE'); ?></h2>
        <?php if (!$revocable) : ?>
            <p class="alert alert-info"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_REVOKE_NOTHING'); ?></p>
        <?php else : ?>
            <p><strong><?php echo $this->escape(trim((string) $item->user_name . ' (' . (string) $item->user_username . ')')); ?></strong>,
                <?php echo $this->escape($item->email_masked); ?>,
                <?php echo Text::_($item->audience === 'external' ? 'COM_APPOINTMENTS_TEAM_C_AUDIENCE_EXTERNAL' : 'COM_APPOINTMENTS_TEAM_C_AUDIENCE_TEAM'); ?></p>
            <p><?php echo Text::_($open ? 'COM_APPOINTMENTS_TEAM_C_REVOKE_OPEN_DESC' : 'COM_APPOINTMENTS_TEAM_C_REVOKE_COMPLETED_DESC'); ?></p>
            <form action="<?php echo Route::_('index.php?option=com_appointments&view=invitations'); ?>" method="post">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="revoke-confirm" name="confirm" value="1" required>
                    <label class="form-check-label" for="revoke-confirm"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_REVOKE_CONFIRM'); ?></label>
                </div>
                <input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
                <button type="submit" class="btn btn-danger" name="task" value="invitations.revoke"><?php echo Text::_($open ? 'COM_APPOINTMENTS_TEAM_C_INVITATION_CLOSE' : 'COM_APPOINTMENTS_TEAM_C_REVOKE_BUTTON'); ?></button>
                <?php echo HTMLHelper::_('form.token'); ?>
            </form>
        <?php endif; ?>
        <p class="mt-3"><a href="<?php echo Route::_('index.php?option=com_appointments&view=invitations'); ?>"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_BACK_TO_LIST'); ?></a></p>
    </div>
</section>
