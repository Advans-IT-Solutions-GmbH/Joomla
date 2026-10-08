<?php
/**
 * @package     J2Commerce Import/Export Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\J2CommerceImportExport\Administrator\View\Dashboard;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\DatabaseNotFoundException;
use Joomla\Database\QueryInterface;

class HtmlView extends BaseHtmlView implements DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    protected $menutypes;
    protected $viewlevels;
    protected $categories;

    /**
     * Database resolved once per request by getDb().
     */
    private ?DatabaseInterface $resolvedDatabase = null;

    public function display($tpl = null)
    {
        $this->menutypes = $this->getMenuTypes();
        $this->viewlevels = $this->getViewLevels();
        $this->categories = $this->getCategories();

        $this->addToolbar();
        return parent::display($tpl);
    }

    /**
     * The database this view reads its option lists from.
     *
     * A view has to declare the dependency itself and be able to resolve it
     * itself. Joomla's MVCFactory injects a database into models, never into
     * views: createModel() calls setDatabase() on a DatabaseAwareInterface,
     * createView() sets only the form factory, dispatcher, router, cache
     * controller and user factory. Without DatabaseAwareTrait the call is an
     * undefined method (fatal error), with the trait alone it throws
     * DatabaseNotFoundException — so the container is the fallback. Same class
     * of defect as the OSMap plugin's createDbQuery() once had.
     */
    private function getDb(): DatabaseInterface
    {
        if ($this->resolvedDatabase === null) {
            try {
                $this->resolvedDatabase = $this->getDatabase();
            } catch (DatabaseNotFoundException $e) {
                $this->resolvedDatabase = Factory::getContainer()->get(DatabaseInterface::class);
            }
        }

        return $this->resolvedDatabase;
    }

    /**
     * Create a fresh query object — compatible with Joomla 4/5 (getQuery) and 6 (createQuery).
     */
    private function createDbQuery(DatabaseInterface $db): QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    protected function addToolbar()
    {
        ToolbarHelper::title(Text::_('COM_J2COMMERCE_IMPORTEXPORT'), 'upload');
        ToolbarHelper::preferences('com_j2commerce_importexport');
    }

    protected function getMenuTypes(): array
    {
        $db = $this->getDb();
        $query = $this->createDbQuery($db)
            ->select(['menutype', 'title'])
            ->from($db->quoteName('#__menu_types'))
            ->order('title ASC');
        $db->setQuery($query);
        return $db->loadObjectList();
    }

    protected function getViewLevels(): array
    {
        $db = $this->getDb();
        $query = $this->createDbQuery($db)
            ->select(['id', 'title'])
            ->from($db->quoteName('#__viewlevels'))
            ->order('ordering ASC');
        $db->setQuery($query);
        return $db->loadObjectList();
    }

    protected function getCategories(): array
    {
        $db = $this->getDb();
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
