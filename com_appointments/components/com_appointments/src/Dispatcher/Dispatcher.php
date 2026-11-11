<?php
/**
 * @package     Appointments Component
 * @subpackage  Site
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Dispatcher;

use Advans\Component\Appointments\Administrator\Helper\MenuViews;
use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Exception\RouteNotFoundException;

defined('_JEXEC') or die;

/**
 * Site dispatcher. Before any controller runs, the request must be one the site offers
 * (MenuViews::allows): a page through the active menu item of its type, a page that checks its
 * own signature, token or session, or a listed task. Anything else answers 404 like an address
 * that does not exist.
 *
 * @since  1.4.1
 */
class Dispatcher extends ComponentDispatcher
{
    public function dispatch()
    {
        // Controller and task exactly as ComponentDispatcher::dispatch() reads them.
        $command = $this->input->getCmd('task', 'display');

        if (str_contains($command, '.')) {
            [$controller, $task] = explode('.', $command);
        } else {
            $controller = (string) $this->input->get('controller', 'display');
            $task = $command;
        }

        $active = $this->app->getMenu()->getActive();
        $menu = $active !== null && \is_array($active->query ?? null) ? $active->query : null;

        if (!MenuViews::allows(
            (string) $controller, (string) $task, $this->input->getCmd('view', ''), $this->input->getInt('id', 0),
            $this->input->getString('layout', ''), $this->input->getCmd('format', 'html'), $menu
        )) {
            throw new RouteNotFoundException(Text::_('JERROR_PAGE_NOT_FOUND'));
        }

        parent::dispatch();
    }
}
