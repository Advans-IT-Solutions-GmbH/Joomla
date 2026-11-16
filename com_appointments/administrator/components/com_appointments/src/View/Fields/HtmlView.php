<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Fields;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    protected $items = [];
    protected $pagination;
    protected $state;

    /** @var \Joomla\CMS\Form\Form|null  Searchtools filter form (read by the core layout). */
    public $filterForm;

    /** @var array  Filters with a value (read by the core layout). */
    public $activeFilters = [];

    /** Whether the rows can be dragged into a new order (sorted by the field order). */
    protected $dragOrdering = false;

    public function display($tpl = null)
    {
        $model = $this->getModel();
        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->state = $model->getState();
        $this->filterForm = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();
        $this->dragOrdering = $model->orderedByOrdering();

        if ($model->getErrors()) {
            throw new \RuntimeException(implode("\n", $model->getErrors()), 500);
        }

        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_FIELDS_TITLE'), 'list');
        $toolbar = Toolbar::getInstance('toolbar');

        if (Factory::getApplication()->getIdentity()->authorise('core.create', 'com_appointments')) {
            $toolbar->addNew('field.add');
        }

        $toolbar->publish('fields.publish')->listCheck(true);
        $toolbar->unpublish('fields.unpublish')->listCheck(true);
        $toolbar->delete('fields.delete')->message('JGLOBAL_CONFIRM_DELETE')->listCheck(true);
        $toolbar->linkButton('events')->url('index.php?option=com_appointments&view=eventtypes')->text(Text::_('COM_APPOINTMENTS_EVENTTYPES_TITLE'));
        $toolbar->linkButton('dashboard')->url('index.php?option=com_appointments')->text(Text::_('COM_APPOINTMENTS_TOOLBAR_DASHBOARD'));

        return parent::display($tpl);
    }
}
