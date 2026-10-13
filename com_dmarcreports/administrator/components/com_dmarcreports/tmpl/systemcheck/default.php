<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

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
</div>
