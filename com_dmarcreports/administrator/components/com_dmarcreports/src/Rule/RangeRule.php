<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Rule;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Server-side numeric range rule.
 *
 * The HTML5 number field enforces its min/max only in the browser, so a crafted
 * request can still post an out-of-range or non-numeric value. This rule rejects
 * such input during the server-side form validation that runs before a record is
 * written, reading the same min/max bounds declared on the field element. An
 * empty value passes so an optional field stays optional; presence is enforced
 * separately by the required attribute.
 *
 * @since  1.0.1
 */
class RangeRule extends FormRule
{
    /**
     * @param   \SimpleXMLElement  $element  The field XML element.
     * @param   mixed              $value    The posted value to validate.
     * @param   string|null        $group    The field group.
     * @param   Registry|null      $input    The full submitted data set.
     * @param   Form|null          $form     The form instance.
     *
     * @return  boolean  True when the value is a whole number within bounds, or
     *                   empty on an optional field; false otherwise.
     */
    public function test(\SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null): bool
    {
        $value = (string) $value;

        // An empty value on an optional field is deferred to the required rule.
        // On a required field it must fail here directly: core's required check
        // is not reliably applied on every save path (com_config can persist an
        // empty number), and consumers cast "" to 0, which is outside the
        // declared min/max. Enforcing it here keeps the bound authoritative.
        if ($value === '') {
            $required = strtolower((string) ($element['required'] ?? ''));

            return !\in_array($required, ['true', 'required', '1'], true);
        }

        // Validate the raw lexical value as a plain integer. is_numeric() would
        // accept "50.5" or "1e2", which a coercing filter could then round into
        // the valid range; the config fields use a non-coercing filter so this
        // rule is the authoritative bound check on the value the operator typed.
        if (!preg_match('/^-?\d+$/', $value)) {
            return false;
        }

        $number = (float) $value;

        if (isset($element['min']) && (string) $element['min'] !== '' && $number < (float) $element['min']) {
            return false;
        }

        if (isset($element['max']) && (string) $element['max'] !== '' && $number > (float) $element['max']) {
            return false;
        }

        return true;
    }
}
