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
        public readonly bool $deviceActive
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
     * CTag and sync token. The empty view of the clean-up has its own value, so
     * every client notices the change and fetches the (empty) collection.
     */
    public function token(): string
    {
        return $this->isEmptyView() ? $this->synctoken . '-c' : (string) $this->synctoken;
    }
}
