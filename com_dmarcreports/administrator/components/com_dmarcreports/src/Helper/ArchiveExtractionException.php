<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Raised when extraction cannot even be attempted because of a transient host
 * problem — a temporary scratch file for an archive could not be created or
 * fully written. Like DecoderUnavailableException it is deliberately distinct
 * from an empty extraction result: the payload may still hold a valid report,
 * so the IMAP client must leave the message in place for a later run instead of
 * advancing the UID cursor and deleting a still-unprocessed report. A genuinely
 * empty or corrupt archive keeps returning an empty list and is skipped.
 *
 * @since  1.0.0
 */
final class ArchiveExtractionException extends \RuntimeException
{
}
