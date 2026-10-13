<?php
/**
 * @package     DMARC Reports Dashboard Module Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// The shipped helper guards against direct access with
// `defined('_JEXEC') or die;`. Define the constant so the pure, Joomla-free
// helpers (summariseTotals, rejectRate, limitActions) can be loaded and
// exercised without a running Joomla.
defined('_JEXEC') or define('_JEXEC', 1);

require_once dirname(__DIR__) . '/src/Helper/DmarcReportsHelper.php';

// A light-weight ActionPresenter stub in the component namespace so the enabled
// path (presentDashboard) can be exercised without the real component present.
require_once __DIR__ . '/Fixtures/ActionPresenterStub.php';
