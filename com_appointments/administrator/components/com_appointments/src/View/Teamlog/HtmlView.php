<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Teamlog;

use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Internet calendars: the log with the chain check. Its checksum (the sealed head of the chain)
 * is shown here and nowhere on the site. `core.admin` only.
 *
 * Work package F owns this view and its layout.
 *
 * @since  1.4.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * Whether the team calendars are switched on and libsodium is available.
     *
     * @var  bool
     */
    protected $enabled = false;

    protected $items = [];

    protected $pagination;

    protected $state;

    /**
     * Hex checksum of the chain head.
     *
     * @var  string
     */
    protected $checksum = '';

    public function display($tpl = null)
    {
        $app = Factory::getApplication();

        if (!$app->getIdentity()->authorise('core.admin', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $app->setHeader('Cache-Control', 'private, no-store', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $services = TeamServices::fromApplication();
        $this->enabled = $services->enabled();
        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_TEAM_LOG_TITLE'), 'calendar');
        $toolbar = Toolbar::getInstance('toolbar');

        if ($this->enabled) {
            $model = $this->getModel();
            $this->items = $model->getItems();
            $this->pagination = $model->getPagination();
            $this->state = $model->getState();
            $this->checksum = $services->audit()->headHash();
            $toolbar->standardButton('checkmark')->text(Text::_('COM_APPOINTMENTS_TEAM_F_LOG_VERIFY'))->task('teamlog.verify');
        }

        $toolbar->linkButton('dashboard')->url('index.php?option=com_appointments')->text(Text::_('COM_APPOINTMENTS_TOOLBAR_DASHBOARD'));

        return parent::display($tpl);
    }
}