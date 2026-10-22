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
 * active, often the home page, so the view uses its own title. When the active menu
 * item points at this view (and, for one event type, at this event type), that menu
 * item's browser page title (or else its menu title) applies, and its page heading
 * replaces the default heading when the menu item shows one. Either title passes
 * through setDocumentTitle(), so the global "Site Name in Page Titles" setting applies.
 */
trait PageTitleTrait
{
    /** Main heading of the page, escaped by the layout. */
    protected string $pageHeading = '';

    protected function applyPageTitle(string $view, string $title, int $id = 0): void
    {
        $page = self::resolvePageTitle(Factory::getApplication()->getMenu()->getActive(), $view, $title, $id);
        $this->pageHeading = $page['heading'];
        $this->setDocumentTitle($page['title']);
    }

    /**
     * Pure decision, testable without Joomla. $menu is the active menu item or null.
     *
     * @return array{title: string, heading: string}
     */
    public static function resolvePageTitle(?object $menu, string $view, string $title, int $id = 0): array
    {
        $query = $menu !== null && is_array($menu->query ?? null) ? $menu->query : [];

        if ($menu === null || ($query['option'] ?? '') !== 'com_appointments' || ($query['view'] ?? '') !== $view
            || ($id !== 0 && (int) ($query['id'] ?? 0) !== $id)
        ) {
            return ['title' => $title, 'heading' => $title];
        }

        $params = $menu->getParams();
        $pageTitle = trim((string) $params->get('page_title', ''));
        $heading = trim((string) $params->get('page_heading', ''));

        return [
            'title' => $pageTitle !== '' ? $pageTitle : (trim((string) ($menu->title ?? '')) ?: $title),
            'heading' => (int) $params->get('show_page_heading', 0) === 1 && $heading !== '' ? $heading : $title,
        ];
    }
}
