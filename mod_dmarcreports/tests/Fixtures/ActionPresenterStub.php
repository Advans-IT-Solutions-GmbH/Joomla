<?php
/**
 * @package     DMARC Reports Dashboard Module Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

/**
 * Minimal stand-in for the component's ActionPresenter so the module's enabled
 * path (presentDashboard) can be unit tested without the real com_dmarcreports
 * installed. The real class carries the same public signatures; only the
 * wording differs, which the module does not assert. Guarded so the real
 * component class wins whenever it is autoloadable.
 */
if (!class_exists(ActionPresenter::class, false)) {
    class ActionPresenter
    {
        public static function title(array $item): string
        {
            return 'title:' . ($item['code'] ?? '');
        }

        public static function text(array $item): string
        {
            return 'text:' . ($item['code'] ?? '');
        }

        public static function severityLabel(int $severity): string
        {
            return 'label:' . $severity;
        }

        public static function severityClass(int $severity): string
        {
            return $severity >= 3 ? 'danger' : 'warning';
        }
    }
}
