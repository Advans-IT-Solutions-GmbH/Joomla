<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * $languageKey is the message for the form as a whole. $fieldErrors maps a field of
 * the booking form (name, email, guests, field-<id>) to the language key of the
 * message shown at that field. Both hold language keys only, never visitor input.
 */
final class BookingValidationException extends \InvalidArgumentException
{
    /** @param array<string, string> $fieldErrors */
    public function __construct(public readonly string $languageKey, public readonly array $fieldErrors = [])
    {
        parent::__construct($languageKey);
    }
}
