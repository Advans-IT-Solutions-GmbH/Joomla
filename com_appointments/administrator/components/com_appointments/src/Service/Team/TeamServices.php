<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * The one place the team calendar services are wired together. Every caller
 * (controllers, plugins, the Scheduler task, tests) gets its services from
 * here, and every service gets the others from here, so no two work packages
 * edit the same file to connect their classes. The implementation classes are
 * fixed by name; each work package replaces the body of its own classes only.
 *
 * Instances live for one request. Nothing here is static state.
 *
 * @since  1.4.0
 */
final class TeamServices
{
    /**
     * @var  array<string, object>
     */
    private $instances = [];

    /**
     * @param   \Closure():\DateTimeImmutable  $clock  Returns "now" in UTC; injected so tests control time.
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly KeyProvider $keyProvider,
        private readonly Registry $params,
        private readonly \Closure $clock
    ) {
    }

    public static function fromApplication(): self
    {
        return new self(
            Factory::getContainer()->get(DatabaseInterface::class),
            new KeyProvider((string) Factory::getApplication()->get('secret', '')),
            ComponentHelper::getParams('com_appointments'),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }

    /**
     * Whether the team calendars are switched on and can work at all. Every
     * entry point (CalDAV, «My calendars», the backend views' actions, the
     * booking bridge) answers as if the feature did not exist when this is false.
     */
    public function enabled(): bool
    {
        return (int) $this->params->get('teamcal_enabled', 0) === 1 && CipherSuite::available();
    }

    public function db(): DatabaseInterface
    {
        return $this->db;
    }

    public function keyProvider(): KeyProvider
    {
        return $this->keyProvider;
    }

    public function params(): Registry
    {
        return $this->params;
    }

    public function now(): \DateTimeImmutable
    {
        return ($this->clock)();
    }

    /** Work package B. */
    public function keys(): KeyServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new KeyService($this);
    }

    /** Work package B. */
    public function keySession(): KeySessionInterface
    {
        return $this->instances[__FUNCTION__] ??= new KeySession($this);
    }

    /** Work package C. */
    public function calendars(): CalendarServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new CalendarService($this);
    }

    /** Work package C. */
    public function grants(): GrantServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new GrantService($this);
    }

    /** Work package C. */
    public function invitations(): InvitationServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new InvitationService($this);
    }

    /** Work package D. */
    public function devices(): DeviceServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new DeviceService($this);
    }

    /** Work package D. */
    public function mailer(): TeamMailerInterface
    {
        return $this->instances[__FUNCTION__] ??= new TeamMailer($this);
    }

    /** Work package A. */
    public function objects(): CalendarObjectStoreInterface
    {
        return $this->instances[__FUNCTION__] ??= new CalendarObjectStore($this);
    }

    /** Work package E. */
    public function timing(): TimingIndexInterface
    {
        return $this->instances[__FUNCTION__] ??= new TimingIndex($this);
    }

    /** Work package E. */
    public function bookings(): BookingCalendarBridgeInterface
    {
        return $this->instances[__FUNCTION__] ??= new BookingCalendarBridge($this);
    }

    /** Work package F. */
    public function audit(): AuditServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new AuditService($this);
    }

    /** Work package F. */
    public function lockout(): LockoutServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new LockoutService($this);
    }

    /** Work package F. */
    public function cleanup(): CleanupServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new CleanupService($this);
    }
}
