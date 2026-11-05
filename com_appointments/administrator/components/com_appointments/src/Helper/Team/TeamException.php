<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Team;

defined('_JEXEC') or die;

/**
 * A refusal of the team calendar services that a user may read. The message is
 * a language key, never a translated text and never a detail of a key, a
 * password, a token or a calendar's content. The code is the HTTP status a
 * controller should answer with.
 *
 * Codes in use: 403 not allowed, 404 unknown or not visible (the two are never
 * told apart), 409 conflict, 412 precondition failed, 423 keys locked (sign in
 * again with the password and the second factor), 429 temporarily locked.
 *
 * @since  1.4.0
 */
class TeamException extends \RuntimeException
{
    public function __construct(string $languageKey, int $code = 403, ?\Throwable $previous = null)
    {
        parent::__construct($languageKey, $code, $previous);
    }
}
