<?php
/**
 * @package     Appointments Dashboard Module
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var array{items: list<array{id: int, start_utc: string, state: int, title: string, name: string}>, zone: string, since: ?string, new: int, error: bool} $digest */
$list = Route::_('index.php?option=com_appointments&view=bookings');
$format = Text::_('MOD_APPOINTMENTS_DATE_FORMAT');
?>
<div class="mod-appointments">
    <?php if ($digest['error']) : ?>
        <p class="alert alert-warning mb-2"><?php echo Text::_('MOD_APPOINTMENTS_ERROR'); ?></p>
    <?php else : ?>
        <?php if ($digest['since'] !== null) : ?>
            <p class="mod-appointments-new mb-2">
                <a href="<?php echo $list; ?>"><?php echo match ((int) $digest['new']) {
                    0 => Text::_('MOD_APPOINTMENTS_NEW_SINCE_VISIT_NONE'),
                    1 => Text::_('MOD_APPOINTMENTS_NEW_SINCE_VISIT_ONE'),
                    default => Text::sprintf('MOD_APPOINTMENTS_NEW_SINCE_VISIT_MANY', (int) $digest['new']),
                }; ?></a>
            </p>
        <?php endif; ?>
        <?php if ($digest['items'] === []) : ?>
            <p class="mod-appointments-empty mb-2"><?php echo Text::_('MOD_APPOINTMENTS_NO_UPCOMING'); ?></p>
        <?php else : ?>
            <ul class="list-group list-group-flush mod-appointments-list mb-2">
                <?php foreach ($digest['items'] as $item) : ?>
                    <li class="list-group-item px-0">
                        <a href="<?php echo Route::_('index.php?option=com_appointments&view=booking&id=' . (int) $item['id']); ?>">
                            <time datetime="<?php echo htmlspecialchars(str_replace(' ', 'T', $item['start_utc']) . 'Z', ENT_QUOTES, 'UTF-8'); ?>"><?php
                                echo htmlspecialchars(HTMLHelper::_('date', $item['start_utc'], $format, $digest['zone']), ENT_QUOTES, 'UTF-8'); ?></time>
                        </a>
                        <?php if ($item['state'] === 0) : ?>
                            <span class="badge bg-warning text-dark"><?php echo Text::_('MOD_APPOINTMENTS_PENDING'); ?></span>
                        <?php endif; ?>
                        <div class="mod-appointments-event"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="mod-appointments-name text-muted"><?php echo htmlspecialchars($item['name'] !== '' ? $item['name'] : Text::_('MOD_APPOINTMENTS_NO_NAME'), ENT_QUOTES, 'UTF-8'); ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="small text-muted mb-2"><?php echo Text::sprintf('MOD_APPOINTMENTS_TIMES_IN_ZONE', htmlspecialchars($digest['zone'], ENT_QUOTES, 'UTF-8')); ?></p>
    <?php endif; ?>
    <a class="btn btn-sm btn-primary mod-appointments-open" href="<?php echo $list; ?>"><?php echo Text::_('MOD_APPOINTMENTS_OPEN_BOOKINGS'); ?></a>
</div>
