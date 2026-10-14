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
 * Extension point only; no remote-service adapter is shipped.
 * Request builders/parsers must be local pure functions, not perform their own I/O.
 * Creation requests must be idempotent because a failed attempt is retried once.
 */
abstract class OutboundConferenceProvider implements ConferenceProviderInterface
{
    final public function createMeeting(MeetingRequest $request): MeetingResult
    {
        $response = (new BoundedHttpClient())->request($this->buildCreateRequest($request));

        return $this->parseCreateResponse($response);
    }

    final public function destroyMeeting(string $roomIdentifier): void
    {
        $request = $this->buildDestroyRequest($roomIdentifier);

        if ($request !== null) {
            (new BoundedHttpClient())->request($request);
        }
    }

    final public function requiresOutboundAccess(): bool
    {
        return true;
    }

    abstract protected function buildCreateRequest(MeetingRequest $request): OutboundHttpRequest;

    abstract protected function parseCreateResponse(string $response): MeetingResult;

    protected function buildDestroyRequest(string $roomIdentifier): ?OutboundHttpRequest
    {
        return null;
    }
}
