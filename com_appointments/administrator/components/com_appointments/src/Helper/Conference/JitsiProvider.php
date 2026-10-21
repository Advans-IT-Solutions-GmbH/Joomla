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

/**
 * Configuration is injected per event, with global defaults merged by the caller.
 * No participant identity or event title is placed in URLs or JWTs.
 */
final class JitsiProvider implements ConferenceProviderInterface
{
    private RoomName $rooms;
    private string $baseUrl;
    private array $config;

    public function __construct(KeyProvider $keys, array $config)
    {
        $this->config = array_replace([
            'room_pattern' => '{prefix}-{alias}-{random}', 'prefix' => 'appointment', 'alias' => 'event',
            'entropy' => 24, 'auth' => 'none', 'host_lead_minutes' => 30, 'grace_minutes' => 60,
            'jwt_audience' => 'jitsi', 'jwt_issuer' => $config['jwt_app_id'] ?? '',
        ], $config);
        $this->baseUrl = rtrim(ConferenceUrl::validate((string) ($config['base_url'] ?? '')), '/');
        $parts = parse_url($this->baseUrl);

        if (isset($parts['query']) || isset($parts['fragment'])
            || !in_array($this->config['auth'], ['none', 'jwt'], true)
        ) {
            throw new ConferenceException('configuration');
        }

        $this->rooms = new RoomName(
            $keys, (string) $this->config['room_pattern'], (string) $this->config['prefix'],
            (string) $this->config['alias'], (int) $this->config['entropy']
        );

        foreach (['host_lead_minutes', 'grace_minutes'] as $field) {
            if (filter_var($this->config[$field], FILTER_VALIDATE_INT) === false
                || (int) $this->config[$field] < 0 || (int) $this->config[$field] > 1440
            ) {
                throw new ConferenceException('configuration');
            }
        }

        if ($this->config['auth'] === 'jwt') {
            foreach (['jwt_secret', 'jwt_app_id', 'jwt_audience', 'jwt_issuer'] as $field) {
                if (!is_string($this->config[$field] ?? null) || $this->config[$field] === '') {
                    throw new ConferenceException('configuration');
                }
            }

            $this->config['jwt_subject'] = $config['jwt_subject'] ?? $parts['host'];

            if (!is_string($this->config['jwt_subject']) || $this->config['jwt_subject'] === '') {
                throw new ConferenceException('configuration');
            }
        }
    }

    public function getName(): string
    {
        return 'jitsi';
    }

    public function getConfigForm(): string
    {
        return '<fieldset name="jitsi">'
            . '<field name="base_url" type="url" label="Base URL" required="true" />'
            . '<field name="room_pattern" type="text" label="Room pattern" default="{prefix}-{alias}-{random}" />'
            . '<field name="prefix" type="text" label="Room prefix" default="appointment" />'
            . '<field name="alias" type="text" label="Event alias" default="event" />'
            . '<field name="entropy" type="number" label="Random characters" min="16" max="64" default="24" />'
            . '<field name="auth" type="list" label="Authentication" default="none">'
            . '<option value="none">None</option><option value="jwt">JWT</option></field>'
            . '<field name="jwt_secret" type="password" label="JWT secret" filter="raw" />'
            . '<field name="jwt_app_id" type="text" label="JWT app ID" />'
            . '<field name="jwt_audience" type="text" label="JWT audience" />'
            . '<field name="jwt_issuer" type="text" label="JWT issuer" />'
            . '<field name="jwt_subject" type="text" label="JWT subject (default: server hostname)" />'
            . '<field name="host_lead_minutes" type="number" label="Host token lead minutes" min="0" max="1440" default="30" />'
            . '<field name="grace_minutes" type="number" label="Token grace minutes" min="0" max="1440" default="60" />'
            . '</fieldset>';
    }

    public function createMeeting(MeetingRequest $request): MeetingResult
    {
        $room = $this->rooms->forMeeting($request);
        $url = $this->baseUrl . '/' . rawurlencode($room);

        if ($this->config['auth'] === 'none') {
            return new MeetingResult($url, $room);
        }

        return new MeetingResult(
            $url . '?jwt=' . $this->token($request, $room, false),
            $room,
            $url . '?jwt=' . $this->token($request, $room, true)
        );
    }

    private function token(MeetingRequest $request, string $room, bool $moderator): string
    {
        $lead = $moderator ? (int) $this->config['host_lead_minutes'] : 15;
        $header = self::base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::base64Url(json_encode([
            'aud' => $this->config['jwt_audience'],
            'iss' => $this->config['jwt_issuer'],
            'sub' => $this->config['jwt_subject'],
            'room' => $room,
            'nbf' => $request->startUtc->getTimestamp() - $lead * 60,
            'exp' => $request->endUtc->getTimestamp() + (int) $this->config['grace_minutes'] * 60,
            'context' => ['user' => ['moderator' => $moderator]],
        ], JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $header . '.' . $payload, $this->config['jwt_secret'], true);

        return $header . '.' . $payload . '.' . self::base64Url($signature);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function destroyMeeting(string $roomIdentifier): void
    {
    }

    public function requiresOutboundAccess(): bool
    {
        return false;
    }
}
