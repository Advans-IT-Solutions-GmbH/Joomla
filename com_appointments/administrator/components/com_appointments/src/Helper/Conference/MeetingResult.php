<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Conference;

defined('_JEXEC') or die;

final class MeetingResult
{
    public readonly string $joinUrl;
    public readonly string $roomIdentifier;
    public readonly ?string $hostUrl;

    public function __construct(string $joinUrl, string $roomIdentifier, ?string $hostUrl = null)
    {
        if ($roomIdentifier === '' || strlen($roomIdentifier) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $roomIdentifier)
        ) {
            throw new ConferenceException('configuration');
        }

        $this->joinUrl = ConferenceUrl::validate($joinUrl);
        $this->roomIdentifier = $roomIdentifier;
        $this->hostUrl = $hostUrl === null ? null : ConferenceUrl::validate($hostUrl);
    }
}
