<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Advans\Component\Appointments\Administrator\Helper\EventScope;

defined('_JEXEC') or die;

class TemplatesModel extends ListModel
{
    use ListFiltersTrait;

    /** Columns the list may be sorted by. */
    private const SORTABLE = ['t.id', 't.subject', 't.purpose', 't.language', 't.published', 'e.title'];

    public function __construct($config = [], $factory = null)
    {
        $config['filter_fields'] ??= self::SORTABLE;
        parent::__construct($config, $factory);
    }

    /**
     * The scopes (event type ids, 0 for the site-wide fallback) whose templates the user may see.
     *
     * @return  int[]
     */
    public static function visibleScopes(DatabaseInterface $db, User $user): array
    {
        $ids = array_map('intval', array_column(EventScope::events($db, $user), 'id'));

        if ($user->authorise('core.manage', 'com_appointments')
            && ($user->authorise('core.options', 'com_appointments') || $user->authorise('core.admin', 'com_appointments'))) {
            $ids[] = 0;
        }

        return $ids;
    }

    protected function filterNames(): array
    {
        return ['search', 'eventtype_id', 'purpose', 'language', 'published'];
    }

    protected function populateState($ordering = 't.id', $direction = 'ASC')
    {
        // Links of earlier releases: -1 meant every scope, which is now the empty value.
        $input = Factory::getApplication()->getInput();

        if ((string) $input->get('filter_eventtype_id', '', 'raw') === '-1') {
            $input->set('filter_eventtype_id', '');
        }

        $this->adoptFlatFilters();
        parent::populateState($ordering, $direction);
        $this->setState('filter.search', $this->textFilter('search'));
        // 0 is the site-wide fallback, so it is a value of its own and not «all».
        $scope = $this->rawFilter('eventtype_id');
        $this->setState('filter.eventtype_id', $scope === '0' ? '0' : $this->idFilter('eventtype_id'));
        $purpose = $this->rawFilter('purpose');
        $this->setState('filter.purpose', $purpose !== null && preg_match('/^[a-z_]{1,40}$/D', $purpose) === 1 ? $purpose : '');
        $language = $this->rawFilter('language');
        $this->setState('filter.language', $language !== null && preg_match('/^[a-z]{2,3}-[A-Z]{2}$/D', $language) === 1 ? $language : '');
        $this->setState('filter.published', $this->optionFilter('published', ['0', '1']));
    }

    protected function getStoreId($id = '')
    {
        foreach ($this->filterNames() as $name) {
            $id .= ':' . $this->getState('filter.' . $name);
        }

        return parent::getStoreId($id);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['t.id', 't.eventtype_id', 't.purpose', 't.language', 't.subject', 't.published', 't.checked_out']))
            ->select($db->quoteName('e.title', 'eventtype_title'))
            ->from($db->quoteName('#__appointments_templates', 't'))
            ->join('LEFT', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('t.eventtype_id'));
        // The permission scope first: a filter only narrows it, never widens it.
        $ids = self::visibleScopes($db, Factory::getApplication()->getIdentity());
        $query->where($ids === [] ? '1 = 0' : $db->quoteName('t.eventtype_id') . ' IN (' . implode(',', $ids) . ')');

        $scope = (string) $this->getState('filter.eventtype_id');

        if ($scope !== '') {
            $scopeId = (int) $scope;
            $query->where($db->quoteName('t.eventtype_id') . ' = :scope')->bind(':scope', $scopeId, ParameterType::INTEGER);
        }

        $purpose = (string) $this->getState('filter.purpose');

        if ($purpose !== '') {
            $query->where($db->quoteName('t.purpose') . ' = :purpose')->bind(':purpose', $purpose);
        }

        $language = (string) $this->getState('filter.language');

        if ($language !== '') {
            $query->where($db->quoteName('t.language') . ' = :language')->bind(':language', $language);
        }

        $published = (string) $this->getState('filter.published');

        if ($published !== '') {
            $publishedFlag = (int) $published;
            $query->where($db->quoteName('t.published') . ' = :published')->bind(':published', $publishedFlag, ParameterType::INTEGER);
        }

        $search = (string) $this->getState('filter.search');

        if ($search !== '') {
            if (preg_match('/^id:(\d{1,10})$/D', $search, $match) === 1) {
                $id = (int) $match[1];
                $query->where($db->quoteName('t.id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
            } else {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
                $query->where($db->quoteName('t.subject') . ' LIKE :search')->bind(':search', $like);
            }
        }

        [$order, $direction] = $this->listOrdering(self::SORTABLE, 't.id', 'ASC');

        return $query->order($db->quoteName($order) . ' ' . $direction)->order($db->quoteName('t.id') . ' ' . $direction);
    }
}
