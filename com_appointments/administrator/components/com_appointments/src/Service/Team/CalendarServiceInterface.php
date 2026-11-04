<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

defined('_JEXEC') or die;

/**
 * Calendars as records (work package C). Calendars are created in the
 * administrator only (`core.create`), never by a CalDAV client: MKCALENDAR is
 * refused. Creation needs a recovery key and an owner with a key pair; the owner
 * receives a manage grant.
 *
 * @since  1.4.0
 */
interface CalendarServiceInterface
{
    /**
     * @param   array{title: string, description?: string, colour?: string, owner_user_id: int, allow_external?: bool, blocks_availability?: bool, retention_days?: int}  $data
     *
     * @return  int  The new calendar id.
     */
    public function create(int $actorUserId, array $data): int;

    /**
     * Same keys as create(), all optional; the owner cannot be changed here.
     */
    public function update(int $actorUserId, int $calendarId, array $data): void;

    /**
     * Delete a calendar with every object, grant and key. Event types that
     * targeted it lose their target (`calendar_id` NULL). Devices see the
     * calendar disappear on their next synchronisation.
     */
    public function delete(int $actorUserId, int $calendarId): void;

    /**
     * @return  object|null  The row without `row_mac`, after verifying it.
     */
    public function get(int $calendarId): ?object;

    public function getByUri(string $uri): ?object;

    /**
     * The account was deleted: every calendar it owned gets owner 0, with a new
     * row MAC, and is logged as calendar.updated with reason owner_deleted. The
     * contents stay; a recovery grant (GrantServiceInterface::grantWithRecovery())
     * names a new owner.
     *
     * @return  int  Calendars released.
     */
    public function releaseOwner(int $userId): int;
}
