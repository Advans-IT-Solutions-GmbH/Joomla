<?php
/**
 * @package     Appointments Component
 * @subpackage  Site
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View\Eventtypes;

use Advans\Component\Appointments\Site\View\PageTitleTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

defined('_JEXEC') or die;

/**
 * The public list of bookable event types.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    use PageTitleTrait;

    /**
     * @var  array<int, \stdClass>
     */
    protected $items = [];

    public function display($tpl = null)
    {
        $this->items = $this->getModel()->getItems();

        // Every asset ships with the package and is declared in the component's
        // own joomla.asset.json: no remote font, no content delivery network,
        // nothing that leaves the operator's own server.
        $assets = Factory::getApplication()->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_appointments');
        $assets->useStyle('com_appointments.site');
        $this->applyPageTitle('eventtypes', Text::_('COM_APPOINTMENTS_EVENTTYPES_HEADING'));

        return parent::display($tpl);
    }
}
