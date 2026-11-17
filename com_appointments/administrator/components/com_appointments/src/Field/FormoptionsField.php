<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Field;

use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * The choices of a list field of another form of this component, for a list filter: the filter
 * then always offers exactly the values the edit form can store (`source_form`, `source_field`).
 *
 * @since  1.6.0
 */
class FormoptionsField extends ListField
{
    protected $type = 'Formoptions';

    protected function getOptions()
    {
        $options = parent::getOptions();
        $form = (string) $this->element['source_form'];
        $field = (string) $this->element['source_field'];

        if (preg_match('/^[a-z]{1,40}$/D', $form) !== 1 || preg_match('/^[a-z_]{1,40}$/D', $field) !== 1) {
            return $options;
        }

        $file = \dirname(__DIR__, 2) . '/forms/' . $form . '.xml';
        $xml = is_file($file) ? simplexml_load_file($file) : false;

        if ($xml === false) {
            return $options;
        }

        foreach ($xml->xpath('//field[@name="' . $field . '"]/option') ?: [] as $option) {
            $value = (string) $option['value'];

            if ($value !== '') {
                $options[] = HTMLHelper::_('select.option', $value, Text::_(trim((string) $option)));
            }
        }

        return $options;
    }
}
