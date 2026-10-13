<?php
/**
 * @package     DMARC Reports Dashboard Module
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Module\DmarcReports\Administrator\Dispatcher;

use Advans\Module\DmarcReports\Administrator\Helper\DmarcReportsHelper;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Helper\HelperFactoryAwareInterface;
use Joomla\CMS\Helper\HelperFactoryAwareTrait;

defined('_JEXEC') or die;

/**
 * Dispatcher for mod_dmarcreports. Resolves the dashboard payload through the
 * module helper and hands it to the layout.
 *
 * @since  1.0.0
 */
class Dispatcher extends AbstractModuleDispatcher implements HelperFactoryAwareInterface
{
    use HelperFactoryAwareTrait;

    /**
     * Build the data the layout receives.
     *
     * @return  array<string, mixed>
     *
     * @since   1.0.0
     */
    protected function getLayoutData(): array
    {
        $data = parent::getLayoutData();

        /** @var DmarcReportsHelper $helper */
        $helper = $this->getHelperFactory()->getHelper('DmarcReportsHelper');

        $data['dashboard'] = $helper->getDashboard($data['params'], $this->getApplication());

        return $data;
    }
}
