<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Helper;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\Event\EventInterface;

defined('_JEXEC') or die;

/**
 * Keeps a page out of every cache. The signed pages, their receipts and the confirmation carry
 * signed links and form tokens of one browser session.
 *
 * allowCache(false) only governs the browser cache headers. The plugin "System - Page Cache" stores
 * every guest GET response by its address regardless of it and serves it to the next visitor
 * without running the component; it only skips a page that a listener of onPageCacheIsExcluded
 * excludes. That event is dispatched after the component ran, so the page registers the listener
 * while it renders.
 */
final class NoPageCache
{
    public static function apply(CMSApplicationInterface $app): void
    {
        $app->allowCache(false);
        // Joomla 5.4 and 6 dispatch a result-aware IsExcludedEvent; the plugin excludes the page when
        // any result is true.
        $app->getDispatcher()->addListener('onPageCacheIsExcluded', static function (EventInterface $event): void {
            if (method_exists($event, 'addResult')) {
                $event->addResult(true);
            }
        });
    }
}
