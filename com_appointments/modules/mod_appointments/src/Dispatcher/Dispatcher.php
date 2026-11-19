<?php
/**
 * @package     Appointments Dashboard Module
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Module\Appointments\Administrator\Dispatcher;

use Advans\Module\Appointments\Administrator\Helper\AppointmentsHelper;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Helper\HelperFactoryAwareInterface;
use Joomla\CMS\Helper\HelperFactoryAwareTrait;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Renders the next appointments, or nothing at all for a user who may not see the bookings list
 * of com_appointments (AppointmentsHelper::getDigest() returns null): an empty module is not shown
 * on the administrator dashboard, so such a user does not even see its title.
 */
class Dispatcher extends AbstractModuleDispatcher implements HelperFactoryAwareInterface
{
    use HelperFactoryAwareTrait;

    /** @var array<string, mixed>|null */
    private ?array $digest = null;

    public function dispatch()
    {
        $this->loadLanguage();

        /** @var AppointmentsHelper $helper */
        $helper = $this->getHelperFactory()->getHelper('AppointmentsHelper');
        $this->digest = $helper->getDigest(new Registry($this->module->params ?? ''), $this->getApplication());

        if ($this->digest === null) {
            return;
        }

        parent::dispatch();
    }

    protected function getLayoutData(): array
    {
        $data = parent::getLayoutData();
        $data['digest'] = $this->digest;

        return $data;
    }
}
