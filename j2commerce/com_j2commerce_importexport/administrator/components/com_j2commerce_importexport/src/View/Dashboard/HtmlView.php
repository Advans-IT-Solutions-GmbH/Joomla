<?php
/**
 * @package     J2Commerce Import/Export Component
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Component\J2CommerceImportExport\Administrator\View\Dashboard;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Factory;

class HtmlView extends BaseHtmlView
{
    /**
     * Create a fresh query object — compatible with Joomla 4/5 (getQuery) and 6 (createQuery).
     */
    private function createDbQuery(\Joomla\Database\DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    protected $menutypes;
    protected $viewlevels;
    protected $categories;

    public function display($tpl = null)
    {
        $this->menutypes = $this->getMenuTypes();
        $this->viewlevels = $this->getViewLevels();
        $this->categories = $this->getCategories();

        $this->addToolbar();
        return parent::display($tpl);
    }

    protected function addToolbar()
    {
        ToolbarHelper::title(Text::_('COM_J2COMMERCE_IMPORTEXPORT'), 'upload');
        ToolbarHelper::preferences('com_j2commerce_importexport');
    }

    protected function getMenuTypes(): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $this->createDbQuery($db)
            ->select(['menutype', 'title'])
            ->from($db->quoteName('#__menu_types'))
            ->order('title ASC');
        $db->setQuery($query);
        return $db->loadObjectList();
    }

    protected function getViewLevels(): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $this->createDbQuery($db)
            ->select(['id', 'title'])
            ->from($db->quoteName('#__viewlevels'))
            ->order('ordering ASC');
        $db->setQuery($query);
        return $db->loadObjectList();
    }

    protected function getCategories(): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $this->createDbQuery($db)
            ->select(['id', 'title', 'level'])
            ->from($db->quoteName('#__categories'))
            ->where('extension = ' . $db->quote('com_content'))
            ->where('published = 1')
            ->order('lft ASC');
        $db->setQuery($query);
        return $db->loadObjectList();
    }
}
