<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View;

use Joomla\CMS\Factory;

defined('_JEXEC') or die;

/**
 * The messages of a completed form (a failed email, a missing conference link) belong to its
 * outcome. A page with a result lands at that result and focuses it, so messages the template
 * shows above the component would be out of view and never read out. The page takes them from
 * the queue and shows them in the result, which names them as its description.
 */
trait ResultNoticesTrait
{
    /** @var array<int, array{type: string, message: string}> */
    protected array $notices = [];

    protected function takeResultNotices(): void
    {
        $classes = ['error' => 'danger', 'warning' => 'warning', 'notice' => 'info', 'info' => 'info'];

        foreach (Factory::getApplication()->getMessageQueue(true) as $message) {
            $text = (string) ($message['message'] ?? '');

            if ($text !== '') {
                $this->notices[] = ['type' => $classes[$message['type'] ?? ''] ?? 'success', 'message' => $text];
            }
        }
    }
}
