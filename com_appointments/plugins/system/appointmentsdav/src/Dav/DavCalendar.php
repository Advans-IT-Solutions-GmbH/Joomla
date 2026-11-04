<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Service\Team\CleanupServiceInterface;

defined('_JEXEC') or die;

/**
 * One calendar as this request's device sees it: role, view and the display
 * properties. Built by {@see DavContext} from the grant and clean-up services
 * only; nothing here is read from the request.
 *
 * @since  1.4.0
 */
final class DavCalendar
{
    /**
     * @param   CalendarRole|null  $role  Null when the device is in clean-up and no grant row is left.
     * @param   string             $view  One of the CleanupServiceInterface::VIEW_* constants (never hidden).
     * @param   int|null           $cleanupToken  In the clean-up: the highest sync token delivered to the device before it.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uri,
        public readonly string $title,
        public readonly string $colour,
        public readonly ?int $order,
        public readonly int $synctoken,
        public readonly ?CalendarRole $role,
        public readonly string $view,
        public readonly bool $deviceActive,
        public readonly ?int $cleanupToken = null
    ) {
    }

    public function isEmptyView(): bool
    {
        return $this->view !== CleanupServiceInterface::VIEW_NORMAL || !$this->deviceActive || $this->role === null;
    }

    /**
     * Free/busy: redacted busy blocks only, no calendar key.
     */
    public function isFreeBusy(): bool
    {
        return $this->role === CalendarRole::FreeBusy;
    }

    public function readsContent(): bool
    {
        return !$this->isEmptyView() && $this->role !== null && $this->role->holdsKey();
    }

    public function isWritable(): bool
    {
        return !$this->isEmptyView() && $this->role !== null && $this->role->canWrite();
    }

    /**
     * CTag and sync token. The empty view of the clean-up and the free/busy view
     * have values of their own, so every client notices a change of view or
     * role and synchronises the collection again in full: a reader turned
     * free/busy loses its plain copies, a free/busy grantee turned reader its
     * redacted ones.
     */
    public function token(): string
    {
        if ($this->isEmptyView()) {
            return $this->synctoken . '-c';
        }

        return self::tokenFor($this->isFreeBusy(), $this->synctoken);
    }

    public static function tokenFor(bool $freeBusy, int $synctoken): string
    {
        return ($freeBusy ? 'fb' : '') . $synctoken;
    }
}
