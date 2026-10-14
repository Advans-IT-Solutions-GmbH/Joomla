<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Rule;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

class RangeRule extends FormRule
{
    public function test(\SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null): bool
    {
        if ((!is_int($value) && !is_string($value)) || !ctype_digit((string) $value)) {
            return false;
        }

        $number = (int) $value;

        return (!isset($element['min']) || $number >= (int) $element['min'])
            && (!isset($element['max']) || $number <= (int) $element['max']);
    }
}
