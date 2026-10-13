<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View\Eventtype;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    protected $item;
    protected $fields = [];

    public function display($tpl = null)
    {
        $this->item = $this->getModel()->getItem();
        $this->fields = $this->getModel()->getFields((int) $this->item->id);
        $assets = Factory::getApplication()->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_appointments');
        $assets->useStyle('com_appointments.site');

        return parent::display($tpl);
    }
}
