<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Conference;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;

defined('_JEXEC') or die;

final class GenericRoomProvider implements ConferenceProviderInterface
{
    private RoomName $rooms;
    private string $urlPattern;

    public function __construct(KeyProvider $keys, array $config)
    {
        $this->urlPattern = (string) ($config['url_pattern'] ?? '');

        if (substr_count($this->urlPattern, '{room}') !== 1
            || preg_match('/[{}]/', str_replace('{room}', '', $this->urlPattern))
        ) {
            throw new ConferenceException('configuration');
        }

        ConferenceUrl::validate(str_replace('{room}', 'meeting', $this->urlPattern));
        $parts = parse_url($this->urlPattern);

        // The substitution must not control the scheme, authority or credentials.
        if (!is_array($parts) || str_contains($parts['host'] ?? '', '{room}')) {
            throw new ConferenceException('configuration');
        }

        $this->rooms = new RoomName(
            $keys, (string) ($config['room_pattern'] ?? '{prefix}-{alias}-{random}'),
            (string) ($config['prefix'] ?? 'meeting'), (string) ($config['alias'] ?? 'event'),
            (int) ($config['entropy'] ?? 24)
        );
    }

    public function getName(): string
    {
        return 'generic';
    }

    public function getConfigForm(): string
    {
        return '<fieldset name="generic">'
            . '<field name="url_pattern" type="text" label="HTTPS URL pattern containing {room}" required="true" />'
            . '<field name="room_pattern" type="text" label="Room pattern" default="{prefix}-{alias}-{random}" />'
            . '<field name="prefix" type="text" label="Room prefix" default="meeting" />'
            . '<field name="alias" type="text" label="Event alias" default="event" />'
            . '<field name="entropy" type="number" label="Random characters" min="16" max="64" default="24" />'
            . '</fieldset>';
    }

    public function createMeeting(MeetingRequest $request): MeetingResult
    {
        $room = $this->rooms->forMeeting($request);

        return new MeetingResult(str_replace('{room}', rawurlencode($room), $this->urlPattern), $room);
    }

    public function destroyMeeting(string $roomIdentifier): void
    {
    }

    public function requiresOutboundAccess(): bool
    {
        return false;
    }
}
