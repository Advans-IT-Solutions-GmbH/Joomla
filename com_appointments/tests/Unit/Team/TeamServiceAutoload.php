<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// The bootstrap loads the Helper namespace only. The pure, static decisions of
// the internet calendar services are tested without Joomla: their classes are
// loaded, but no method that needs Joomla or a database is called.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Advans\\Component\\Appointments\\Administrator\\Service\\Team\\';

    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team/'
            . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});
