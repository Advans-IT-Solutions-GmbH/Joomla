<?php
/**
 * @package     Appointments Component
 * @subpackage  Site
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

defined('_JEXEC') or die;

/**
 * The event types a visitor may book.
 *
 * @since  1.0.0
 */
class EventtypesModel extends BaseDatabaseModel
{
    /**
     * Published event types the current visitor is allowed to see, in the
     * configured order.
     *
     * @return  array<int, \stdClass>
     */
    public function getItems(): array
    {
        $app         = Factory::getApplication();
        $db          = $this->getDatabase();
        $viewLevels  = $app->getIdentity()->getAuthorisedViewLevels();
        $languageTag = $app->getLanguage()->getTag();

        // An empty view-level list would turn the IN () clause into invalid SQL.
        // The public view level always exists, so this is a safety net only.
        if ($viewLevels === []) {
            return [];
        }

        $query = $this->createDbQuery($db)
            ->select($db->quoteName(['id', 'title', 'alias', 'duration_minutes']))
            ->from($db->quoteName('#__appointments_eventtypes'))
            ->where($db->quoteName('published') . ' = 1')
            ->whereIn($db->quoteName('access'), $viewLevels)
            ->whereIn($db->quoteName('language'), [$languageTag, '*'], ParameterType::STRING)
            ->order($db->quoteName('ordering') . ' ASC')
            ->order($db->quoteName('title') . ' ASC');

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * Creates a query object compatible with Joomla 5 (getQuery) and 6 (createQuery).
     */
    private function createDbQuery(DatabaseInterface $db): QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
