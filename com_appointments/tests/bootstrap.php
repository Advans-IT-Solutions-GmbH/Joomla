<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// The shipped helper classes guard against direct access with
// `defined('_JEXEC') or die;`. Define the constant so the pure, Joomla-free
// helpers can be loaded and exercised without a running Joomla.
defined('_JEXEC') or define('_JEXEC', 1);

$helperDir = dirname(__DIR__) . '/administrator/components/com_appointments/src/Helper';

spl_autoload_register(static function (string $class) use ($helperDir): void {
    $prefix = 'Advans\\Component\\Appointments\\Administrator\\Helper\\';

    if (str_starts_with($class, $prefix)) {
        $file = $helperDir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

require_once $helperDir . '/KeyProvider.php';
require_once $helperDir . '/UtcTimestamp.php';
require_once $helperDir . '/AvailabilityWindow.php';
require_once $helperDir . '/ScheduleResolver.php';
require_once $helperDir . '/SlotRules.php';
require_once $helperDir . '/BusyPeriod.php';
require_once $helperDir . '/SlotEngine.php';
