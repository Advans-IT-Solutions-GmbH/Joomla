<?php
/**
 * @package     DMARC Reports Dashboard Module
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @var   array<string, mixed>              $dashboard  Dashboard payload from the helper.
 * @var   \Joomla\Registry\Registry         $params     Module parameters.
 * @var   \Joomla\CMS\Application\CMSApplicationInterface  $app  Administrator application.
 */

use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

$state       = (string) ($dashboard['state'] ?? 'unavailable');
$link        = (string) ($dashboard['link'] ?? 'index.php?option=com_dmarcreports');
$showSummary = (int) $params->get('show_summary', 1) === 1;

if ($state !== 'ok') {
    $notice = match ($state) {
        'unauthorised' => Text::_('MOD_DMARCREPORTS_NOT_AUTHORISED'),
        'error'        => Text::_('MOD_DMARCREPORTS_ERROR'),
        default        => Text::_('MOD_DMARCREPORTS_COMPONENT_UNAVAILABLE'),
    };
    ?>
    <div class="mod-dmarcreports">
        <div class="alert alert-info m-0" role="alert">
            <?php echo $notice; ?>
        </div>
    </div>
    <?php
    return;
}

$totals     = $dashboard['totals'];
$actions    = $dashboard['actions'];
$activeCnt  = (int) ($dashboard['activeCount'] ?? 0);
$rejectRate = (float) ($dashboard['rejectRate'] ?? 0.0);
$hasData    = (int) ($totals['total'] ?? 0) > 0 || (int) ($totals['domains'] ?? 0) > 0;
?>
<div class="mod-dmarcreports">
    <?php if (!$hasData) : ?>
        <div class="alert alert-info" role="alert">
            <?php echo Text::_('MOD_DMARCREPORTS_NO_DATA'); ?>
        </div>
    <?php elseif ($showSummary) : ?>
        <div class="row g-2 mod-dmarcreports__summary mb-3">
            <div class="col-6 col-md-3">
                <div class="card h-100 text-center">
                    <div class="card-body py-2">
                        <div class="fs-3 fw-bold"><?php echo (int) $totals['domains']; ?></div>
                        <div class="small text-muted"><?php echo Text::_('MOD_DMARCREPORTS_SUMMARY_DOMAINS'); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card h-100 text-center">
                    <div class="card-body py-2">
                        <div class="fs-3 fw-bold"><?php echo number_format((int) $totals['total']); ?></div>
                        <div class="small text-muted"><?php echo Text::_('MOD_DMARCREPORTS_SUMMARY_MESSAGES'); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card h-100 text-center">
                    <div class="card-body py-2">
                        <div class="fs-3 fw-bold"><?php echo (float) $totals['pass_rate']; ?>%</div>
                        <div class="small text-muted"><?php echo Text::_('MOD_DMARCREPORTS_SUMMARY_PASS_RATE'); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card h-100 text-center">
                    <div class="card-body py-2">
                        <div class="fs-3 fw-bold"><?php echo $rejectRate; ?>%</div>
                        <div class="small text-muted"><?php echo Text::_('MOD_DMARCREPORTS_SUMMARY_WOULD_BE_REJECTED'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($hasData) : ?>
        <h4 class="mod-dmarcreports__heading h6 text-uppercase text-muted">
            <?php echo Text::_('MOD_DMARCREPORTS_ACTION_CENTER_HEADING'); ?>
        </h4>

        <?php if ($activeCnt === 0) : ?>
            <div class="alert alert-success" role="alert">
                <?php echo Text::_('MOD_DMARCREPORTS_NO_ACTIONS'); ?>
            </div>
        <?php else : ?>
            <?php if ($actions !== []) : ?>
            <div class="list-group mod-dmarcreports__actions mb-2">
                <?php foreach ($actions as $action) : ?>
                    <a class="list-group-item list-group-item-action d-flex align-items-start gap-2" href="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="badge bg-<?php echo htmlspecialchars($action['severityClass'], ENT_QUOTES, 'UTF-8'); ?> flex-shrink-0">
                            <?php echo htmlspecialchars($action['severityLabel'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span>
                            <span class="fw-semibold"><?php echo htmlspecialchars($action['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if ($action['domain'] !== '') : ?>
                                <span class="text-muted">— <?php echo htmlspecialchars($action['domain'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <br>
                            <span class="small text-muted"><?php echo htmlspecialchars($action['text'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($activeCnt > \count($actions)) : ?>
                <p class="small text-muted">
                    <?php echo Text::sprintf('MOD_DMARCREPORTS_MORE_ACTIONS', $activeCnt - \count($actions)); ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <a class="btn btn-sm btn-primary" href="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo Text::_('MOD_DMARCREPORTS_OPEN_DASHBOARD'); ?>
    </a>
</div>
