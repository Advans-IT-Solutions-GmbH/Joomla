<?php
/**
 * Disable the legacy installer approval entry point.
 *
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

fwrite(STDERR, "Installer conflict approvals are no longer supported. Resolve the listed files manually; existing stored files are left untouched.\n");
exit(1);
