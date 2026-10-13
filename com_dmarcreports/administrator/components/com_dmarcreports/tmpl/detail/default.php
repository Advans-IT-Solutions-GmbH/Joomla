<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\DmarcReports\Administrator\Helper\ActionPresenter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

defined('_JEXEC') or die;
?>
<div class="com-dmarcreports-detail">
    <form action="<?php echo Route::_('index.php?option=com_dmarcreports&view=detail'); ?>" method="get" class="mb-4">
        <input type="hidden" name="option" value="com_dmarcreports">
        <input type="hidden" name="view" value="detail">
        <label for="domain_id" class="form-label"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_SELECT_DOMAIN'); ?></label>
        <div class="d-flex gap-2">
            <select name="domain_id" id="domain_id" class="form-select">
                <option value="0"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_SELECT_PLACEHOLDER'); ?></option>
                <?php foreach ($this->domains as $domain) : ?>
                    <option value="<?php echo (int) $domain->id; ?>"
                        <?php echo $this->metrics && (int) $this->metrics['domain_id'] === (int) $domain->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars((string) $domain->domain); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">
                <?php echo Text::_('COM_DMARCREPORTS_DETAIL_SELECT_SUBMIT'); ?>
            </button>
        </div>
    </form>

    <?php if ($this->metrics === null) : ?>
        <div class="alert alert-info">
            <?php echo Text::_('COM_DMARCREPORTS_DETAIL_CHOOSE_DOMAIN'); ?>
        </div>
    <?php else : ?>
        <?php $m = $this->metrics; $token = Session::getFormToken(); ?>
        <h2><?php echo htmlspecialchars((string) $m['domain']); ?></h2>

        <div class="mb-3">
            <a class="btn btn-outline-primary btn-sm"
               href="<?php echo Route::_('index.php?option=com_dmarcreports&task=export.records&domain_id=' . (int) $m['domain_id'] . '&' . $token . '=1'); ?>">
                <?php echo Text::_('COM_DMARCREPORTS_DETAIL_EXPORT_CSV'); ?>
            </a>
        </div>

        <div class="row mb-4">
            <div class="col-md-3 col-6"><div class="card text-center"><div class="card-body">
                <div class="fs-4 fw-bold"><?php echo (int) $m['total']; ?></div>
                <div class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_STAT_MESSAGES'); ?></div>
            </div></div></div>
            <div class="col-md-3 col-6"><div class="card text-center"><div class="card-body">
                <div class="fs-4 fw-bold"><?php echo (float) $m['pass_rate']; ?>%</div>
                <div class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_STAT_PASS_RATE'); ?></div>
            </div></div></div>
            <div class="col-md-3 col-6"><div class="card text-center"><div class="card-body">
                <div class="fs-4 fw-bold"><?php echo (int) $m['reject_under_p']; ?></div>
                <div class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_STAT_REJECT_UNDER_P'); ?></div>
            </div></div></div>
            <div class="col-md-3 col-6"><div class="card text-center"><div class="card-body">
                <div class="fs-4 fw-bold">p=<?php echo htmlspecialchars((string) ($m['current_policy'] ?: 'none')); ?></div>
                <div class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_COL_POLICY'); ?></div>
            </div></div></div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h3 class="mb-0"><?php echo Text::_('COM_DMARCREPORTS_ACTION_CENTER_HEADING'); ?></h3></div>
            <div class="card-body">
                <?php if (empty($this->actions)) : ?>
                    <div class="alert alert-success mb-0"><?php echo Text::_('COM_DMARCREPORTS_ACTION_CENTER_EMPTY'); ?></div>
                <?php else : ?>
                    <ul class="list-group">
                        <?php foreach ($this->actions as $item) : ?>
                            <li class="list-group-item">
                                <span class="badge bg-<?php echo ActionPresenter::severityClass((int) $item['severity']); ?> me-2">
                                    <?php echo ActionPresenter::severityLabel((int) $item['severity']); ?>
                                </span>
                                <strong><?php echo htmlspecialchars(ActionPresenter::title($item)); ?></strong>
                                <div><?php echo htmlspecialchars(ActionPresenter::text($item)); ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h3 class="mb-0"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_DNS_HEADING'); ?></h3></div>
            <div class="card-body">
                <?php if (empty($this->dns['enabled'])) : ?>
                    <div class="alert alert-info mb-0"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_DNS_DISABLED'); ?></div>
                <?php else : ?>
                <dl class="row mb-0">
                    <dt class="col-sm-3"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_DNS_DMARC'); ?></dt>
                    <dd class="col-sm-9">
                        <?php if (!empty($this->dns['dmarc']['error'])) : ?>
                            <span class="text-warning"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_DNS_ERROR'); ?></span>
                        <?php elseif (!empty($this->dns['dmarc']['present'])) : ?>
                            <code><?php echo htmlspecialchars((string) $this->dns['dmarc']['record']); ?></code>
                        <?php else : ?>
                            <?php if (!empty($this->dns['subdomain'])) : ?>
                                <span class="text-info"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_DNS_SUBDOMAIN'); ?></span>
                            <?php else : ?>
                                <span class="text-danger"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_DNS_MISSING'); ?></span>
                                <?php if (!empty($this->dns['suggested'])) : ?>
                                    <div class="mt-1"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_DNS_SUGGESTED'); ?>
                                        <code><?php echo htmlspecialchars((string) $this->dns['suggested']); ?></code></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-sm-3"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_DNS_SPF'); ?></dt>
                    <dd class="col-sm-9">
                        <?php if (!empty($this->dns['spf']['error'])) : ?>
                            <span class="text-warning"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_DNS_ERROR'); ?></span>
                        <?php elseif (!empty($this->dns['spf']['present'])) : ?>
                            <code><?php echo htmlspecialchars((string) $this->dns['spf']['record']); ?></code>
                        <?php else : ?>
                            <span class="text-danger"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_DNS_MISSING'); ?></span>
                        <?php endif; ?>
                    </dd>
                </dl>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h3 class="mb-0"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_MTASTS_HEADING'); ?></h3></div>
            <div class="card-body">
                <?php if (empty($this->mtasts['enabled'])) : ?>
                    <div class="alert alert-info mb-0"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_MTASTS_DISABLED'); ?></div>
                <?php else : $g = $this->mtasts['generated']; ?>
                <p class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_MTASTS_MANUAL_STEP'); ?></p>
                <dl class="row mb-0">
                    <dt class="col-sm-3"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_MTASTS_MODE'); ?></dt>
                    <dd class="col-sm-9"><code><?php echo htmlspecialchars((string) $g['mode']); ?></code></dd>
                    <dt class="col-sm-3"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_MTASTS_POLICY_URL'); ?></dt>
                    <dd class="col-sm-9"><code><?php echo htmlspecialchars((string) $g['well_known_url']); ?></code></dd>
                    <dt class="col-sm-3"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_MTASTS_STS_RECORD'); ?></dt>
                    <dd class="col-sm-9">
                        <code><?php echo htmlspecialchars((string) $g['sts_record_host']); ?></code>
                        <?php if (!empty($g['sts_record'])) : ?>
                            &rarr; <code><?php echo htmlspecialchars((string) $g['sts_record']); ?></code>
                        <?php endif; ?>
                    </dd>
                    <?php if (!empty($g['tlsrpt_record'])) : ?>
                    <dt class="col-sm-3"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_MTASTS_TLSRPT_RECORD'); ?></dt>
                    <dd class="col-sm-9">
                        <code><?php echo htmlspecialchars((string) $g['tlsrpt_record_host']); ?></code>
                        &rarr; <code><?php echo htmlspecialchars((string) $g['tlsrpt_record']); ?></code>
                    </dd>
                    <?php endif; ?>
                </dl>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h3 class="mb-0"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_SOURCES_HEADING'); ?></h3></div>
            <div class="card-body">
                <?php if (empty($m['top_sources'])) : ?>
                    <div class="alert alert-info mb-0"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_NO_SOURCES'); ?></div>
                <?php else : ?>
                    <table class="table table-sm table-striped">
                        <thead><tr>
                            <th><?php echo Text::_('COM_DMARCREPORTS_COL_SOURCE_IP'); ?></th>
                            <th class="text-end"><?php echo Text::_('COM_DMARCREPORTS_COL_VOLUME'); ?></th>
                            <th class="text-end"><?php echo Text::_('COM_DMARCREPORTS_COL_PASS'); ?></th>
                            <th class="text-end"><?php echo Text::_('COM_DMARCREPORTS_COL_FAIL'); ?></th>
                            <th class="text-center"><?php echo Text::_('COM_DMARCREPORTS_COL_ALIGNED'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($m['top_sources'] as $src) : ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars((string) $src['source_ip']); ?></code></td>
                                    <td class="text-end"><?php echo (int) $src['total']; ?></td>
                                    <td class="text-end text-success"><?php echo (int) $src['pass']; ?></td>
                                    <td class="text-end text-danger"><?php echo (int) $src['fail']; ?></td>
                                    <td class="text-center">
                                        <?php if ((int) $src['aligned'] === 1) : ?>
                                            <span class="badge bg-success"><?php echo Text::_('COM_DMARCREPORTS_YES'); ?></span>
                                        <?php else : ?>
                                            <span class="badge bg-danger"><?php echo Text::_('COM_DMARCREPORTS_NO'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="mb-0"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_TIMELINE_HEADING'); ?></h3></div>
            <div class="card-body">
                <?php if (empty($m['timeline'])) : ?>
                    <div class="alert alert-info mb-0"><?php echo Text::_('COM_DMARCREPORTS_DETAIL_NO_TIMELINE'); ?></div>
                <?php else : ?>
                    <table class="table table-sm">
                        <thead><tr>
                            <th><?php echo Text::_('COM_DMARCREPORTS_COL_DAY'); ?></th>
                            <th class="text-end"><?php echo Text::_('COM_DMARCREPORTS_COL_VOLUME'); ?></th>
                            <th class="text-end"><?php echo Text::_('COM_DMARCREPORTS_COL_PASS'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($m['timeline'] as $day) : ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string) $day['day']); ?></td>
                                    <td class="text-end"><?php echo (int) $day['total']; ?></td>
                                    <td class="text-end"><?php echo (int) $day['pass']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
