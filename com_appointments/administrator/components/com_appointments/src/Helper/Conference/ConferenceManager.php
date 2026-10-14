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
 * DI accepts an iterable of providers, including plugin registrations.
 * Call only after saving the booking. On ConferenceException keep the booking,
 * persist reason/message as failed, and send the normal mail with location text.
 */
final class ConferenceManager
{
    /** @var array<string, ConferenceProviderInterface> */
    private array $providers = [];

    public function __construct(iterable $providers = [], private bool $allowOutbound = false)
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(ConferenceProviderInterface $provider): void
    {
        $name = $provider->getName();

        if (!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $name) || isset($this->providers[$name])) {
            throw new ConferenceException('configuration');
        }

        $this->providers[$name] = $provider;
    }

    /** @return string[] */
    public function getProviderNames(): array
    {
        return array_keys($this->providers);
    }

    public function getConfigForm(string $name): string
    {
        try {
            return $this->provider($name)->getConfigForm();
        } catch (\Throwable $error) {
            throw new ConferenceException('configuration');
        }
    }

    public function createMeeting(string $name, MeetingRequest $request): MeetingResult
    {
        $provider = $this->provider($name);

        try {
            $this->checkOutbound($provider);

            return $provider->createMeeting($request);
        } catch (\Throwable $error) {
            throw new ConferenceException($error instanceof ConferenceException ? $error->reason : 'creation_failed');
        }
    }

    public function destroyMeeting(string $name, string $roomIdentifier): void
    {
        $provider = $this->provider($name);

        try {
            $this->checkOutbound($provider);
            $provider->destroyMeeting($roomIdentifier);
        } catch (\Throwable $error) {
            throw new ConferenceException($error instanceof ConferenceException ? $error->reason : 'teardown_failed');
        }
    }

    private function provider(string $name): ConferenceProviderInterface
    {
        if (!isset($this->providers[$name])) {
            throw new ConferenceException('unknown_provider');
        }

        return $this->providers[$name];
    }

    private function checkOutbound(ConferenceProviderInterface $provider): void
    {
        if (!$provider->requiresOutboundAccess()) {
            return;
        }

        if (!$this->allowOutbound) {
            throw new ConferenceException('outbound_disabled');
        }

        if (!$provider instanceof OutboundConferenceProvider) {
            throw new ConferenceException('outbound_unsupported');
        }
    }
}
