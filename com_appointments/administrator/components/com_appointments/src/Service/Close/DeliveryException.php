<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Close;

defined('_JEXEC') or die;

final class DeliveryException extends \RuntimeException
{
    public function __construct(public readonly string $state, public readonly string $reason, public readonly int $delay = 300)
    {
        parent::__construct($reason);
    }
}
