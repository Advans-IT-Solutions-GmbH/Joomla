<?php
/**
 * @package     Privacy Requests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\PrivacyRequests\Service;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Input\Input;

defined('_JEXEC') or die;

/**
 * Installed only on the two model instances used by this operation. The component,
 * application identity and registered-user factory are never replaced.
 */
final class RequestFactory implements MVCFactoryInterface
{
    public function __construct(
        private MVCFactoryInterface $core,
        private DatabaseInterface $db,
        private string $email,
        private int $id = 0,
        private string $hash = ''
    ) {
    }

    public function createTable($name, $prefix = '', array $config = [])
    {
        if (strtolower($name) === 'request' && strtolower($prefix) === 'administrator') {
            return new RequestTable($this->db, $this->email, $this->id, $this->hash);
        }

        return $this->core->createTable($name, $prefix, $config);
    }

    public function createController($name, $prefix, array $config, CMSApplicationInterface $app, Input $input)
    {
        return $this->core->createController($name, $prefix, $config, $app, $input);
    }

    public function createModel($name, $prefix = '', array $config = [])
    {
        return $this->core->createModel($name, $prefix, $config);
    }

    public function createView($name, $prefix = '', $type = '', array $config = [])
    {
        return $this->core->createView($name, $prefix, $type, $config);
    }
}
