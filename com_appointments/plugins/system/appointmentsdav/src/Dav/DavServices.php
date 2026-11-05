<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Service\Team\AuditServiceInterface;
use Advans\Component\Appointments\Administrator\Service\Team\CalendarObjectStoreInterface;
use Advans\Component\Appointments\Administrator\Service\Team\CalendarServiceInterface;
use Advans\Component\Appointments\Administrator\Service\Team\CleanupServiceInterface;
use Advans\Component\Appointments\Administrator\Service\Team\DeviceServiceInterface;
use Advans\Component\Appointments\Administrator\Service\Team\GrantServiceInterface;
use Advans\Component\Appointments\Administrator\Service\Team\KeyServiceInterface;
use Advans\Component\Appointments\Administrator\Service\Team\LockoutServiceInterface;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Advans\Component\Appointments\Administrator\Service\Team\TimingIndexInterface;

defined('_JEXEC') or die;

/**
 * The services the CalDAV server uses, by interface only. The server never
 * reads or writes a team table itself; every decision about access, keys,
 * devices and the clean-up is taken by the owning service.
 *
 * @since  1.4.0
 */
final class DavServices
{
    public function __construct(
        public readonly DeviceServiceInterface $devices,
        public readonly LockoutServiceInterface $lockout,
        public readonly GrantServiceInterface $grants,
        public readonly CalendarServiceInterface $calendars,
        public readonly KeyServiceInterface $keys,
        public readonly CleanupServiceInterface $cleanup,
        public readonly CalendarObjectStoreInterface $objects,
        public readonly TimingIndexInterface $timing,
        public readonly AuditServiceInterface $audit,
        public readonly KeyProvider $keyProvider
    ) {
    }

    public static function fromTeamServices(TeamServices $services): self
    {
        return new self(
            $services->devices(),
            $services->lockout(),
            $services->grants(),
            $services->calendars(),
            $services->keys(),
            $services->cleanup(),
            $services->objects(),
            $services->timing(),
            $services->audit(),
            $services->keyProvider()
        );
    }
}
