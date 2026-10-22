<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Conference;

defined('_JEXEC') or die;

/**
 * Safe for conference_error. Never chain an adapter's original exception.
 */
final class ConferenceException extends \RuntimeException
{
    private const MESSAGES = [
        'configuration' => 'Conference configuration is invalid.',
        'not_configured' => 'No conference provider is selected for this event type.',
        'storage_failed' => 'Conference status could not be saved. Please retry.',
        'unknown_provider' => 'Conference provider is unavailable.',
        'outbound_disabled' => 'Outbound conference access is disabled.',
        'outbound_unsupported' => 'Conference provider requires a bounded outbound adapter.',
        'creation_failed' => 'Conference link creation failed. Please retry.',
        'teardown_failed' => 'Conference room cleanup failed. Please retry.',
        'outbound_failed' => 'Conference service did not respond successfully.',
    ];

    public readonly string $reason;

    public function __construct(string $reason = 'creation_failed')
    {
        $this->reason = isset(self::MESSAGES[$reason]) ? $reason : 'creation_failed';
        parent::__construct(self::MESSAGES[$this->reason]);
    }
}
