<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Dispatcher;

defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Document\HtmlDocument;

/**
 * Administrator dispatcher. Every HTML page of the component loads the layout
 * stylesheet, so neither a long translated page title nor a wide table widens
 * the whole page.
 *
 * @since  1.4.1
 */
class Dispatcher extends ComponentDispatcher
{
    public function dispatch()
    {
        $document = $this->app->getDocument();
        if ($document instanceof HtmlDocument) {
            $assets = $document->getWebAssetManager();
            $assets->getRegistry()->addExtensionRegistryFile('com_appointments');
            $assets->useStyle('com_appointments.admin.layout');
        }

        parent::dispatch();
    }
}
