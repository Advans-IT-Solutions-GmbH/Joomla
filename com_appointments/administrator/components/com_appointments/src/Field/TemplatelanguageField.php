<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Field;

use Advans\Component\Appointments\Administrator\Model\TemplatesModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * The languages of the e-mail templates the signed-in user may see, for the language filter of
 * the template list. A language of a template outside the user's scope is not offered.
 *
 * @since  1.6.0
 */
class TemplatelanguageField extends ListField
{
    protected $type = 'Templatelanguage';

    protected function getOptions()
    {
        $options = parent::getOptions();
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $scope = TemplatesModel::visibleScopes($db, Factory::getApplication()->getIdentity());

        if ($scope === []) {
            return $options;
        }

        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select('DISTINCT ' . $db->quoteName('language'))
            ->from($db->quoteName('#__appointments_templates'))
            ->where($db->quoteName('eventtype_id') . ' IN (' . implode(',', $scope) . ')')
            ->order($db->quoteName('language') . ' ASC');

        foreach ($db->setQuery($query)->loadColumn() ?: [] as $language) {
            $language = (string) $language;

            if ($language !== '') {
                $options[] = HTMLHelper::_('select.option', $language, $language);
            }
        }

        return $options;
    }
}
