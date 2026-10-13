<?php
/**
 * @package     DMARC Reports Ingestion Task Plugin Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// The shipped support class guards against direct access with
// `defined('_JEXEC') or die;`. Define the constant so the pure, Joomla-free
// aggregation helper can be loaded and exercised without a running Joomla.
defined('_JEXEC') or define('_JEXEC', 1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($autoload)) {
    require_once $autoload;
}

require_once dirname(__DIR__) . '/src/Support/IngestSummary.php';
