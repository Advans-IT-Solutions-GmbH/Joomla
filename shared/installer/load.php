<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer\V2;

defined('_JEXEC') or die;

(static function (): void {
    foreach (['RoutingCheck', 'SystemCheck', 'ScheduledTasks', 'Destinations', 'FileGuard', 'State', 'Knowledge', 'Planner', 'Approval', 'InstallerScript'] as $class) {
        if (!class_exists(__NAMESPACE__ . '\\' . $class, false)) {
            require_once __DIR__ . '/' . $class . '.php';
        }
    }
})();
