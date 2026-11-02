<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\HTML\HTMLRegistryAwareTrait;
use Advans\Component\Appointments\Administrator\Helper\Conference\ConferenceManager;
use Psr\Container\ContainerInterface;

/**
 * Component class for Appointments. It serves the administrator and the site
 * part, which is why it lives in the administrator namespace and is referenced
 * by both service providers, the way Joomla's own components do it. It also
 * provides the site router (Site\Service\Router), so SEF addresses of the
 * booking pages resolve through menu items in every language and in "All".
 *
 * @since  1.0.0
 */
class AppointmentsComponent extends MVCComponent implements BootableExtensionInterface, RouterServiceInterface
{
    use HTMLRegistryAwareTrait;
    use RouterServiceTrait;

    private ConferenceManager $conferenceManager;

    public function getConferenceManager(): ConferenceManager
    {
        return $this->conferenceManager;
    }

    /**
     * Booting the extension.
     *
     * @param   ContainerInterface  $container  The container.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function boot(ContainerInterface $container)
    {
        $this->conferenceManager = $container->get(ConferenceManager::class);
    }
}
