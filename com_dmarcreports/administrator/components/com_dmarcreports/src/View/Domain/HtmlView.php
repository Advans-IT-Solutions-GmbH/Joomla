<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\View\Domain;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Edit view for a single monitored domain.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    protected $form;

    protected $item;

    public function display($tpl = null)
    {
        $this->form = $this->getModel()->getForm();
        $this->item = $this->getModel()->getItem();

        $this->addToolbar();

        // This view only ever renders the edit form; pin the layout so the
        // default URL (view=domain, no layout) resolves to tmpl/domain/edit.php.
        $this->setLayout('edit');

        return parent::display($tpl);
    }

    private function addToolbar(): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $isNew   = empty($this->item->id);
        $toolbar = \Joomla\CMS\Toolbar\Toolbar::getInstance('toolbar');

        ToolbarHelper::title(
            Text::_($isNew ? 'COM_DMARCREPORTS_DOMAIN_NEW' : 'COM_DMARCREPORTS_DOMAIN_EDIT'),
            'list'
        );

        $toolbar->apply('domain.apply');
        $toolbar->save('domain.save');
        $toolbar->cancel('domain.cancel');
    }
}
