<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Model;

use Advans\Component\DmarcReports\Administrator\Helper\EnvironmentCheck;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

defined('_JEXEC') or die;

/**
 * Supplies the re-runnable System Check view.
 *
 * @since  1.0.0
 */
class SystemcheckModel extends BaseDatabaseModel
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getChecks(): array
    {
        return EnvironmentCheck::run();
    }
}
