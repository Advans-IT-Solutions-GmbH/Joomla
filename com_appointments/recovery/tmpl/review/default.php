<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Advans\Installer\AppointmentsConflict\V1\Guard;
use Advans\Installer\V2\Knowledge;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<p><?= Text::_('COM_APPOINTMENTSRECOVERY_HELP') ?></p>
<?php if (!empty($review['rows'])) : ?>
    <h2><?= Text::_('COM_APPOINTMENTSRECOVERY_REVIEW') ?>: <?= $esc($review['version']) ?></h2>
    <?php foreach ($review['rows'] as $id => $row) : ?>
        <section class="card p-3 mb-2">
            <h3 class="h5 text-break"><?= $esc(Knowledge::relative($row['destination'])) ?></h3>
            <dl class="text-break">
                <dt><?= Text::_('COM_APPOINTMENTSRECOVERY_EXISTING') ?></dt><dd><code><?= $esc($row['existing']) ?></code></dd>
                <dt><?= Text::_('COM_APPOINTMENTSRECOVERY_INCOMING') ?></dt><dd><code><?= $esc($row['incoming']) ?></code></dd>
                <dt><?= Text::_('COM_APPOINTMENTSRECOVERY_BACKUP_PATH') ?></dt><dd><code><?= $esc(Knowledge::relative($row['backup'])) ?></code></dd>
            </dl>
            <form method="post" action="<?= $esc($page) ?>">
                <input type="hidden" name="action" value="decide">
                <input type="hidden" name="plan" value="<?= $esc($review['id']) ?>">
                <input type="hidden" name="file" value="<?= $esc($id) ?>">
                <label for="choice-<?= $esc($id) ?>"><?= Text::_('COM_APPOINTMENTSRECOVERY_CHOICE') ?></label>
                <select id="choice-<?= $esc($id) ?>" name="choice" required>
                    <option value=""><?= Text::_('COM_APPOINTMENTSRECOVERY_SELECT') ?></option>
                    <?php if ($row['keep']) : ?>
                        <option value="keep" <?= ($review['choices'][$id] ?? '') === 'keep' ? 'selected' : '' ?>><?= Text::_('COM_APPOINTMENTSRECOVERY_KEEP') ?></option>
                    <?php endif; ?>
                    <option value="replace" <?= ($review['choices'][$id] ?? '') === 'replace' ? 'selected' : '' ?>><?= Text::_($row['remove'] ? 'COM_APPOINTMENTSRECOVERY_REMOVE' : 'COM_APPOINTMENTSRECOVERY_REPLACE') ?></option>
                </select>
                <?= HTMLHelper::_('form.token') ?>
                <button class="btn btn-primary" type="submit"><?= Text::_('JSAVE') ?></button>
                <?php if (!$row['keep']) : ?><p><?= Text::_('COM_APPOINTMENTSRECOVERY_NO_KEEP') ?></p><?php endif; ?>
            </form>
        </section>
    <?php endforeach; ?>
<?php else : ?>
    <p><?= Text::_('COM_APPOINTMENTSRECOVERY_NO_REVIEW') ?></p>
<?php endif; ?>

<?php if (!empty($attempt['rows'])) : ?>
    <h2><?= Text::_('COM_APPOINTMENTSRECOVERY_RECOVER') ?>: <?= $esc($attempt['version']) ?></h2>
    <?php foreach ($attempt['rows'] as $id => $row) :
        try {
            $live = $row['keep'] ? Guard::liveHash($row) : '';
        } catch (\RuntimeException $error) {
            $live = '';
        }
        ?>
        <section class="card p-3 mb-2 text-break">
            <h3 class="h5"><?= $esc(Knowledge::relative($row['destination'])) ?></h3>
            <p><?= Text::_('COM_APPOINTMENTSRECOVERY_BACKUP') ?>: <code><?= $esc($row['existing']) ?></code></p>
            <?php if ($live === null || $live === $row['incoming']) : ?>
                <p><?= Text::_('COM_APPOINTMENTSRECOVERY_EXISTING') ?>: <code><?= $esc($live ?? Text::_('COM_APPOINTMENTSRECOVERY_MISSING')) ?></code></p>
                <form method="post" action="<?= $esc($page) ?>">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="plan" value="<?= $esc($attempt['id']) ?>">
                    <input type="hidden" name="file" value="<?= $esc($id) ?>">
                    <input type="hidden" name="expected" value="<?= $esc($live ?? '') ?>">
                    <?= HTMLHelper::_('form.token') ?>
                    <button class="btn btn-warning" type="submit"><?= Text::_('COM_APPOINTMENTSRECOVERY_RESTORE') ?></button>
                </form>
            <?php else : ?>
                <p><?= Text::_('COM_APPOINTMENTSRECOVERY_NO_RESTORE') ?></p>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
