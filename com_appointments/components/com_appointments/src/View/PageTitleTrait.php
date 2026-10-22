<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View;

use Joomla\CMS\Factory;

defined('_JEXEC') or die;

/**
 * Page title and main heading of the public views.
 *
 * Without a menu item of its own, Joomla keeps the title of whatever menu item is
 * active, often the home page. The view then sets the title itself, through
 * setDocumentTitle() so the global "Site Name in Page Titles" setting applies.
 * When the active menu item points at this view (and, for one event type, at this
 * event type), Joomla has already applied that menu item's browser page title, and
 * its page heading replaces the default heading when the menu item shows one.
 */
trait PageTitleTrait
{
    /** Main heading of the page, escaped by the layout. */
    protected string $pageHeading = '';

    protected function applyPageTitle(string $view, string $title, int $id = 0): void
    {
        $this->pageHeading = $title;
        $menu = Factory::getApplication()->getMenu()->getActive();
        $query = $menu !== null && is_array($menu->query) ? $menu->query : [];

        if ($menu !== null && ($query['option'] ?? '') === 'com_appointments' && ($query['view'] ?? '') === $view
            && ($id === 0 || (int) ($query['id'] ?? 0) === $id)
        ) {
            $params = $menu->getParams();
            $heading = trim((string) $params->get('page_heading', ''));

            if ((int) $params->get('show_page_heading', 0) === 1 && $heading !== '') {
                $this->pageHeading = $heading;
            }

            return;
        }

        $this->setDocumentTitle($title);
    }
}
