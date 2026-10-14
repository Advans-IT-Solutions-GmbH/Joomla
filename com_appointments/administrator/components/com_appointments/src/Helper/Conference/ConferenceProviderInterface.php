<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Conference;

defined('_JEXEC') or die;

interface ConferenceProviderInterface
{
    public function getName(): string;

    /** Form XML fragment describing this provider's settings. */
    public function getConfigForm(): string;

    public function createMeeting(MeetingRequest $request): MeetingResult;

    public function destroyMeeting(string $roomIdentifier): void;

    public function requiresOutboundAccess(): bool;
}
