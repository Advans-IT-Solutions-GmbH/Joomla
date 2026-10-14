<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

final class InvalidBookingLinkException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
    }
}
