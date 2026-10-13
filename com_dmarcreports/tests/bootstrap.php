<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// The shipped helper classes guard against direct access with
// `defined('_JEXEC') or die;`. Define the constant so the pure, Joomla-free
// helpers can be loaded and exercised without a running Joomla.
defined('_JEXEC') or define('_JEXEC', 1);

$helperDir = dirname(__DIR__) . '/administrator/components/com_dmarcreports/src/Helper';

require_once $helperDir . '/DomainName.php';
require_once $helperDir . '/DmarcParser.php';
require_once $helperDir . '/ReportArchive.php';
require_once $helperDir . '/TlsReportArchive.php';
require_once $helperDir . '/MimeMessage.php';
require_once $helperDir . '/ImapException.php';
require_once $helperDir . '/ImapClient.php';
require_once $helperDir . '/DnsResolverInterface.php';
require_once $helperDir . '/DnsDiagnostics.php';
require_once $helperDir . '/ActionCenter.php';
require_once $helperDir . '/SourceIdentity.php';
require_once $helperDir . '/PolicyFetcherInterface.php';
require_once $helperDir . '/MtaStsPolicy.php';
require_once $helperDir . '/MtaStsDiagnostics.php';
require_once $helperDir . '/TlsRptParser.php';
