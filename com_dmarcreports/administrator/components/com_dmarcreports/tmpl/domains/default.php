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
?>
<form action="<?php echo Route::_('index.php?option=com_dmarcreports&view=domains'); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-12">
            <?php if (empty($this->items)) : ?>
                <div class="alert alert-info">
                    <?php echo Text::_('COM_DMARCREPORTS_DOMAINS_EMPTY'); ?>
                </div>
            <?php else : ?>
                <table class="table" id="domainList">
                    <caption class="visually-hidden"><?php echo Text::_('COM_DMARCREPORTS_DOMAINS_TITLE'); ?></caption>
                    <thead>
                        <tr>
                            <td class="w-1 text-center">
                                <?php echo HTMLHelper::_('grid.checkall'); ?>
                            </td>
                            <th><?php echo Text::_('COM_DMARCREPORTS_COL_DOMAIN'); ?></th>
                            <th><?php echo Text::_('COM_DMARCREPORTS_COL_TITLE'); ?></th>
                            <th class="text-center"><?php echo Text::_('COM_DMARCREPORTS_COL_EXPECTED_POLICY'); ?></th>
                            <th class="text-center"><?php echo Text::_('JPUBLISHED'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->items as $i => $item) : ?>
                            <tr>
                                <td class="text-center">
                                    <?php echo HTMLHelper::_('grid.id', $i, $item->id); ?>
                                </td>
                                <td>
                                    <a href="<?php echo Route::_('index.php?option=com_dmarcreports&task=domain.edit&id=' . (int) $item->id); ?>">
                                        <?php echo htmlspecialchars((string) $item->domain); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars((string) $item->title); ?></td>
                                <td class="text-center">p=<?php echo htmlspecialchars((string) $item->expected_policy); ?></td>
                                <td class="text-center">
                                    <?php if ((int) $item->published === 1) : ?>
                                        <span class="badge bg-success"><?php echo Text::_('JPUBLISHED'); ?></span>
                                    <?php else : ?>
                                        <span class="badge bg-secondary"><?php echo Text::_('JUNPUBLISHED'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php echo $this->pagination->getListFooter(); ?>
            <?php endif; ?>
        </div>
    </div>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="boxchecked" value="0">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
