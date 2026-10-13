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
 * Raised when a report arrives in a container the host recognises but cannot
 * decompress because the required PHP extension (zlib for gzip, zip for zip)
 * is not installed. It is deliberately distinct from an empty extraction result
 * so a caller can tell "nothing usable in this payload" apart from "could not
 * even try": the IMAP client leaves such a message unseen for a later run
 * instead of marking a still-valid report processed, and the upload handler can
 * surface a concrete requirement message rather than a generic failure.
 *
 * @since  1.0.0
 */
final class DecoderUnavailableException extends \RuntimeException
{
}
