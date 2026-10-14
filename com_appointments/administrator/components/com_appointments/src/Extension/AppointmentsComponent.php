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

use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\HTML\HTMLRegistryAwareTrait;
use Advans\Component\Appointments\Administrator\Helper\Conference\ConferenceManager;
use Psr\Container\ContainerInterface;

/**
 * Component class for Appointments. It serves the administrator and the site
 * part, which is why it lives in the administrator namespace and is referenced
 * by both service providers, the way Joomla's own components do it.
 *
 * @since  1.0.0
 */
class AppointmentsComponent extends MVCComponent implements BootableExtensionInterface
{
    use HTMLRegistryAwareTrait;
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
