<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\DmarcReports\Administrator\Helper\ActionPresenter;
use Advans\Component\DmarcReports\Administrator\Helper\EnvironmentCheck;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

$labels = [
    'php_version'         => 'COM_DMARCREPORTS_CHECK_PHP_VERSION',
    'required_extensions' => 'COM_DMARCREPORTS_CHECK_REQUIRED_EXTENSIONS',
    'gzip'                => 'COM_DMARCREPORTS_CHECK_GZIP',
    'zip'                 => 'COM_DMARCREPORTS_CHECK_ZIP',
    'dns'                 => 'COM_DMARCREPORTS_CHECK_DNS',
    'sockets'             => 'COM_DMARCREPORTS_CHECK_SOCKETS',
    'crypto'              => 'COM_DMARCREPORTS_CHECK_CRYPTO',
];

$badge = [
    EnvironmentCheck::OK      => 'success',
    EnvironmentCheck::WARNING => 'warning',
    EnvironmentCheck::ERROR   => 'danger',
];

$statusLabel = [
    EnvironmentCheck::OK      => 'COM_DMARCREPORTS_CHECK_STATUS_OK',
    EnvironmentCheck::WARNING => 'COM_DMARCREPORTS_CHECK_STATUS_WARNING',
    EnvironmentCheck::ERROR   => 'COM_DMARCREPORTS_CHECK_STATUS_ERROR',
];

$detailFor = static function (string $id, array $params): string {
    $value = $params[0] ?? '';

    return match ($id) {
        'php_version'         => Text::sprintf('COM_DMARCREPORTS_CHECK_PHP_VERSION_DETAIL', $value),
        'required_extensions' => Text::sprintf('COM_DMARCREPORTS_CHECK_REQUIRED_EXTENSIONS_DETAIL', $value),
        'gzip'                => Text::_('COM_DMARCREPORTS_CHECK_GZIP_DETAIL'),
        'zip'                 => Text::_('COM_DMARCREPORTS_CHECK_ZIP_DETAIL'),
        'dns'                 => Text::_('COM_DMARCREPORTS_CHECK_DNS_DETAIL'),
        'sockets'             => Text::_('COM_DMARCREPORTS_CHECK_SOCKETS_DETAIL'),
        'crypto'              => Text::_('COM_DMARCREPORTS_CHECK_CRYPTO_DETAIL'),
        default               => '',
    };
};
?>
<div class="com-dmarcreports-systemcheck">
    <div class="card">
        <div class="card-header">
            <h2 class="mb-0"><?php echo Text::_('COM_DMARCREPORTS_SYSTEMCHECK_HEADING'); ?></h2>
        </div>
        <div class="card-body">
            <p class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_SYSTEMCHECK_INTRO'); ?></p>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th><?php echo Text::_('COM_DMARCREPORTS_CHECK_COL_NAME'); ?></th>
                        <th class="text-center"><?php echo Text::_('COM_DMARCREPORTS_CHECK_COL_STATUS'); ?></th>
                        <th><?php echo Text::_('COM_DMARCREPORTS_CHECK_COL_DETAIL'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->checks as $check) : ?>
                        <?php
                        $id      = (string) $check['id'];
                        $status  = (string) $check['status'];
                        $label   = isset($labels[$id]) ? Text::_($labels[$id]) : $id;
                        $detail  = $detailFor($id, (array) $check['params']);
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($label); ?></td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $badge[$status] ?? 'secondary'; ?>">
                                    <?php echo htmlspecialchars(Text::_($statusLabel[$status] ?? 'COM_DMARCREPORTS_CHECK_STATUS_WARNING')); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($detail); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php $mtasts = $this->mtasts; ?>
    <div class="card mt-4">
        <div class="card-header">
            <h2 class="mb-0"><?php echo Text::_('COM_DMARCREPORTS_SYSTEMCHECK_MTASTS_HEADING'); ?></h2>
        </div>
        <div class="card-body">
            <p class="text-muted"><?php echo Text::_('COM_DMARCREPORTS_SYSTEMCHECK_MTASTS_INTRO'); ?></p>
            <?php if (empty($mtasts['enabled'])) : ?>
                <p class="mb-0"><?php echo Text::_('COM_DMARCREPORTS_SYSTEMCHECK_MTASTS_DISABLED'); ?></p>
            <?php elseif (empty($mtasts['domains'])) : ?>
                <p class="mb-0"><?php echo Text::_('COM_DMARCREPORTS_SYSTEMCHECK_MTASTS_NO_DOMAINS'); ?></p>
            <?php else : ?>
                <?php if (empty($mtasts['network'])) : ?>
                    <div class="alert alert-info"><?php echo Text::_('COM_DMARCREPORTS_SYSTEMCHECK_MTASTS_NETWORK_OFF'); ?></div>
                <?php endif; ?>
                <?php foreach ($mtasts['domains'] as $row) : ?>
                    <?php
                    $domain   = (string) ($row['domain'] ?? '');
                    $findings = (array) ($row['findings'] ?? []);
                    ?>
                    <div class="mb-3">
                        <h3 class="h5"><?php echo htmlspecialchars($domain); ?></h3>
                        <?php if ($findings === []) : ?>
                            <span class="badge bg-success">
                                <?php echo htmlspecialchars(Text::_('COM_DMARCREPORTS_SYSTEMCHECK_MTASTS_CONSISTENT')); ?>
                            </span>
                        <?php else : ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($findings as $finding) : ?>
                                    <?php $severity = (int) ($finding['severity'] ?? 0); ?>
                                    <li class="mb-2">
                                        <span class="badge bg-<?php echo ActionPresenter::severityClass($severity); ?> me-1">
                                            <?php echo htmlspecialchars(ActionPresenter::severityLabel($severity)); ?>
                                        </span>
                                        <strong><?php echo htmlspecialchars(ActionPresenter::title($finding)); ?></strong>
                                        <div class="text-muted"><?php echo htmlspecialchars(ActionPresenter::text($finding)); ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
