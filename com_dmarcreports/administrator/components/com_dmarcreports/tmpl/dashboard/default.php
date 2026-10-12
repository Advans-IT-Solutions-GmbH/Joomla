<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\DmarcReports\Administrator\Helper\ActionPresenter;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

defined('_JEXEC') or die;

$token = Session::getFormToken();

// The Action Center controls persist a status change, which ActionsController
// gates behind core.edit.state; hide them from view-only users who would only
// hit a 403 on submit.
$canEditState = Factory::getApplication()->getIdentity()->authorise('core.edit.state', 'com_dmarcreports');

$statusLabels = [
    'done'     => 'COM_DMARCREPORTS_ACTION_STATUS_DONE',
    'ignored'  => 'COM_DMARCREPORTS_ACTION_STATUS_IGNORED',
    'snoozed'  => 'COM_DMARCREPORTS_ACTION_STATUS_SNOOZED',
];
?>
<div class="com-dmarcreports-dashboard">
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="mb-0"><?php echo Text::_('COM_DMARCREPORTS_ACTION_CENTER_HEADING'); ?></h2>
        </div>
        <div class="card-body">
            <p class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_ACTION_CENTER_INTRO'); ?></p>

            <?php if (empty($this->actions)) : ?>
                <div class="alert alert-success mb-0">
                    <?php echo Text::_('COM_DMARCREPORTS_ACTION_CENTER_EMPTY'); ?>
                </div>
            <?php else : ?>
                <ol class="list-group list-group-numbered">
                    <?php foreach ($this->actions as $item) : ?>
                        <?php $class = ActionPresenter::severityClass((int) $item['severity']); ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start flex-wrap">
                            <div class="ms-2 me-auto">
                                <div class="fw-bold">
                                    <span class="badge bg-<?php echo $class; ?> me-2">
                                        <?php echo ActionPresenter::severityLabel((int) $item['severity']); ?>
                                    </span>
                                    <?php echo htmlspecialchars((string) $item['domain']); ?> &middot;
                                    <?php echo htmlspecialchars(ActionPresenter::title($item)); ?>
                                </div>
                                <?php echo htmlspecialchars(ActionPresenter::text($item)); ?>
                            </div>
                            <?php if ($canEditState) : ?>
                            <form action="<?php echo Route::_('index.php?option=com_dmarcreports'); ?>" method="post" class="d-flex gap-1 mt-2">
                                <input type="hidden" name="task" value="actions.done">
                                <input type="hidden" name="domain_id" value="<?php echo (int) $item['domain_id']; ?>">
                                <input type="hidden" name="action_key" value="<?php echo htmlspecialchars((string) $item['action_key']); ?>">
                                <input type="hidden" name="category" value="<?php echo htmlspecialchars((string) $item['category']); ?>">
                                <input type="hidden" name="<?php echo $token; ?>" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-success" title="<?php echo Text::_('COM_DMARCREPORTS_ACTION_MARK_DONE'); ?>">
                                    <?php echo Text::_('COM_DMARCREPORTS_ACTION_MARK_DONE'); ?>
                                </button>
                            </form>
                            <form action="<?php echo Route::_('index.php?option=com_dmarcreports'); ?>" method="post" class="d-flex gap-1 mt-2 ms-1">
                                <input type="hidden" name="task" value="actions.snooze">
                                <input type="hidden" name="domain_id" value="<?php echo (int) $item['domain_id']; ?>">
                                <input type="hidden" name="action_key" value="<?php echo htmlspecialchars((string) $item['action_key']); ?>">
                                <input type="hidden" name="days" value="7">
                                <input type="hidden" name="<?php echo $token; ?>" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <?php echo Text::_('COM_DMARCREPORTS_ACTION_SNOOZE'); ?>
                                </button>
                            </form>
                            <form action="<?php echo Route::_('index.php?option=com_dmarcreports'); ?>" method="post" class="d-flex gap-1 mt-2 ms-1">
                                <input type="hidden" name="task" value="actions.ignore">
                                <input type="hidden" name="domain_id" value="<?php echo (int) $item['domain_id']; ?>">
                                <input type="hidden" name="action_key" value="<?php echo htmlspecialchars((string) $item['action_key']); ?>">
                                <input type="hidden" name="<?php echo $token; ?>" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <?php echo Text::_('COM_DMARCREPORTS_ACTION_IGNORE'); ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($this->suppressed)) : ?>
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="mb-0"><?php echo Text::_('COM_DMARCREPORTS_ACTION_HANDLED_HEADING'); ?></h3>
        </div>
        <div class="card-body">
            <p class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_ACTION_HANDLED_INTRO'); ?></p>
            <ul class="list-group">
                <?php foreach ($this->suppressed as $item) : ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap">
                        <div class="ms-2 me-auto">
                            <span class="badge bg-light text-dark me-2">
                                <?php echo Text::_($statusLabels[(string) $item['status']] ?? 'COM_DMARCREPORTS_ACTION_STATUS_DONE'); ?>
                            </span>
                            <?php echo htmlspecialchars((string) $item['domain']); ?> &middot;
                            <?php echo htmlspecialchars(ActionPresenter::title($item)); ?>
                        </div>
                        <?php if ($canEditState) : ?>
                        <form action="<?php echo Route::_('index.php?option=com_dmarcreports'); ?>" method="post" class="d-flex gap-1 mt-2">
                            <input type="hidden" name="task" value="actions.reopen">
                            <input type="hidden" name="domain_id" value="<?php echo (int) $item['domain_id']; ?>">
                            <input type="hidden" name="action_key" value="<?php echo htmlspecialchars((string) $item['action_key']); ?>">
                            <input type="hidden" name="<?php echo $token; ?>" value="1">
                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                <?php echo Text::_('COM_DMARCREPORTS_ACTION_REOPEN'); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-3 col-6">
            <div class="card text-center"><div class="card-body">
                <div class="fs-3 fw-bold"><?php echo (int) ($this->totals['domains'] ?? 0); ?></div>
                <div class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_STAT_DOMAINS'); ?></div>
            </div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card text-center"><div class="card-body">
                <div class="fs-3 fw-bold"><?php echo (int) ($this->totals['total'] ?? 0); ?></div>
                <div class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_STAT_MESSAGES'); ?></div>
            </div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card text-center"><div class="card-body">
                <div class="fs-3 fw-bold"><?php echo (float) ($this->totals['pass_rate'] ?? 0); ?>%</div>
                <div class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_STAT_PASS_RATE'); ?></div>
            </div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card text-center"><div class="card-body">
                <div class="fs-3 fw-bold"><?php echo (int) ($this->totals['reject_under_p'] ?? 0); ?></div>
                <div class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_STAT_REJECT_UNDER_P'); ?></div>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="mb-0"><?php echo Text::_('COM_DMARCREPORTS_DASHBOARD_DOMAINS_HEADING'); ?></h3></div>
        <div class="card-body">
            <?php if (empty($this->summaries)) : ?>
                <div class="alert alert-info mb-0">
                    <?php echo Text::_('COM_DMARCREPORTS_DASHBOARD_NO_DOMAINS'); ?>
                    <a href="<?php echo Route::_('index.php?option=com_dmarcreports&view=sources'); ?>">
                        <?php echo Text::_('COM_DMARCREPORTS_DASHBOARD_NO_DOMAINS_CTA'); ?>
                    </a>
                </div>
            <?php else : ?>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo Text::_('COM_DMARCREPORTS_COL_DOMAIN'); ?></th>
                            <th class="text-end"><?php echo Text::_('COM_DMARCREPORTS_COL_VOLUME'); ?></th>
                            <th class="text-end"><?php echo Text::_('COM_DMARCREPORTS_COL_PASS_RATE'); ?></th>
                            <th class="text-center"><?php echo Text::_('COM_DMARCREPORTS_COL_POLICY'); ?></th>
                            <th class="text-end"><?php echo Text::_('COM_DMARCREPORTS_COL_REJECT_UNDER_P'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->summaries as $row) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo Route::_('index.php?option=com_dmarcreports&view=detail&domain_id=' . (int) $row['id']); ?>">
                                        <?php echo htmlspecialchars((string) $row['domain']); ?>
                                    </a>
                                </td>
                                <td class="text-end"><?php echo (int) $row['total']; ?></td>
                                <td class="text-end"><?php echo (float) $row['pass_rate']; ?>%</td>
                                <td class="text-center">
                                    <?php $p = (string) ($row['current_policy'] ?: 'none'); ?>
                                    <span class="badge bg-secondary">p=<?php echo htmlspecialchars($p); ?></span>
                                </td>
                                <td class="text-end"><?php echo (int) $row['reject_under_p']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
