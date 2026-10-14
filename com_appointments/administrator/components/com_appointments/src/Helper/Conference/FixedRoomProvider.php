<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Conference;

defined('_JEXEC') or die;

final class FixedRoomProvider implements ConferenceProviderInterface
{
    private string $url;

    /** Pass the optional event-specific URL outside the privacy-limited request. */
    public function __construct(string $url, ?string $eventOverride = null)
    {
        $this->url = ConferenceUrl::validate($eventOverride !== null && $eventOverride !== '' ? $eventOverride : $url);
    }

    public function getName(): string
    {
        return 'fixed';
    }

    public function getConfigForm(): string
    {
        return '<fieldset name="fixed">'
            . '<field name="url" type="url" label="Permanent HTTPS meeting URL" required="true" />'
            . '<field name="event_override" type="url" label="Event-specific permanent URL" />'
            . '<field name="collision_warning" type="note" label="This provider reuses one room for every booking. '
            . 'Adjacent or overlapping appointments can collide; use separate event rooms or avoid overlaps." />'
            . '</fieldset>';
    }

    public function createMeeting(MeetingRequest $request): MeetingResult
    {
        // The identifier is stable too, without retaining a second copy of a bearer URL.
        return new MeetingResult($this->url, 'fixed-' . hash('sha256', $this->url));
    }

    public function destroyMeeting(string $roomIdentifier): void
    {
    }

    public function requiresOutboundAccess(): bool
    {
        return false;
    }
}
