<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

defined('_JEXEC') or die;

$token     = Session::getFormToken();
$canIngest = Factory::getApplication()->getIdentity()->authorise('dmarc.ingest', 'com_dmarcreports');
?>
<form action="<?php echo Route::_('index.php?option=com_dmarcreports&view=sources'); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-12">
            <?php if (empty($this->items)) : ?>
                <div class="alert alert-info">
                    <?php echo Text::_('COM_DMARCREPORTS_SOURCES_EMPTY'); ?>
                </div>
            <?php else : ?>
                <table class="table" id="sourceList">
                    <caption class="visually-hidden"><?php echo Text::_('COM_DMARCREPORTS_SOURCES_TITLE'); ?></caption>
                    <thead>
                        <tr>
                            <td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                            <th><?php echo Text::_('COM_DMARCREPORTS_COL_NAME'); ?></th>
                            <th><?php echo Text::_('COM_DMARCREPORTS_COL_HOST'); ?></th>
                            <th><?php echo Text::_('COM_DMARCREPORTS_COL_DOMAIN'); ?></th>
                            <th><?php echo Text::_('COM_DMARCREPORTS_COL_LAST_RUN'); ?></th>
                            <th class="text-center"><?php echo Text::_('COM_DMARCREPORTS_COL_ACTIONS'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->items as $i => $item) : ?>
                            <tr>
                                <td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, $item->id); ?></td>
                                <td>
                                    <a href="<?php echo Route::_('index.php?option=com_dmarcreports&task=source.edit&id=' . (int) $item->id); ?>">
                                        <?php echo htmlspecialchars((string) $item->name); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars((string) $item->host); ?></td>
                                <td><?php echo htmlspecialchars((string) ($item->domain ?? '')); ?></td>
                                <td>
                                    <?php echo $item->last_run && $item->last_run !== '0000-00-00 00:00:00'
                                        ? HTMLHelper::_('date', $item->last_run, Text::_('DATE_FORMAT_LC5'))
                                        : Text::_('COM_DMARCREPORTS_SOURCE_NEVER_RUN'); ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($canIngest) : ?>
                                    <a class="btn btn-sm btn-primary"
                                       href="<?php echo Route::_('index.php?option=com_dmarcreports&task=ingest.import&source_id=' . (int) $item->id . '&' . $token . '=1'); ?>">
                                        <?php echo Text::_('COM_DMARCREPORTS_SOURCE_RUN_IMPORT'); ?>
                                    </a>
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

<?php if ($canIngest) : ?>
<div class="card mt-4">
    <div class="card-header">
        <h3 class="mb-0"><?php echo Text::_('COM_DMARCREPORTS_UPLOAD_HEADING'); ?></h3>
    </div>
    <div class="card-body">
        <p class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_UPLOAD_INTRO'); ?></p>
        <form action="<?php echo Route::_('index.php?option=com_dmarcreports'); ?>" method="post" enctype="multipart/form-data" class="row g-2 align-items-center">
            <div class="col-auto">
                <label for="dmarc-report-file" class="visually-hidden"><?php echo Text::_('COM_DMARCREPORTS_UPLOAD_FILE_LABEL'); ?></label>
                <input type="file" id="dmarc-report-file" name="report_file" class="form-control" accept=".xml,.gz,.zip" required>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-success">
                    <?php echo Text::_('COM_DMARCREPORTS_UPLOAD_SUBMIT'); ?>
                </button>
            </div>
            <input type="hidden" name="task" value="ingest.upload">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    </div>
</div>
<?php endif; ?>
