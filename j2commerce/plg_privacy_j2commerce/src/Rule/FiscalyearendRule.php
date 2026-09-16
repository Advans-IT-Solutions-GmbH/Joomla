<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Rule
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Plugin\Privacy\J2Commerce\Rule;

defined('_JEXEC') or die;

use Advans\Plugin\Privacy\J2Commerce\Retention\RetentionPeriod;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\Registry\Registry;

/**
 * Server-side check of the "Fiscal Year End" option (validate="fiscalyearend"): MM-DD with a day
 * that exists in that month (02-29 allowed). The form "pattern" attribute is only checked in
 * the browser.
 */
class FiscalyearendRule extends FormRule
{
    public function test(\SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        if (!class_exists(RetentionPeriod::class)) {
            require_once \dirname(__DIR__) . '/Retention/RetentionPeriod.php';
        }

        $value = trim((string) $value);

        // Empty: the default (12-31) applies, unless the field is required.
        if ($value === '') {
            return !((string) $element['required'] === 'true' || (string) $element['required'] === 'required');
        }

        return RetentionPeriod::isValidFiscalYearEnd($value);
    }
}
